<?php

namespace App\Messaging;

use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\User;
use App\Notifications\NotificationPreferences;
use App\Notifications\ThreadNotice;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Letters between the site's staff and a user (backlog item 91). A reviewer's question takes a pending
 * suggestion out of the queue (awaiting_reply_since) until the submitter answers or the deadline passes;
 * an admin's direct message opens a thread of its own. The audit log records each event without its text.
 *
 * A send may carry a client token (the form's hidden client_token, or an email reply's id; backlog item 115).
 * The token is unique on messages, so a request sent again on a flaky connection finds the first letter and
 * writes nothing, and its notice goes out once. The notices leave a few seconds late and are dropped if the
 * letter has been deleted meanwhile.
 */
class Conversations
{
    /** A reviewer asks the submitter of a pending suggestion; returns the thread */
    public static function ask(FieldSuggestion $suggestion, User $staff, string $body, ?string $token = null): MessageThread
    {
        abort_unless($suggestion->status === 'pending' && $suggestion->user_id, 422, 'فقط درباره‌ی پیشنهاد در انتظار یک کاربر واردشده می‌توان پرسید.');

        return self::once($token, fn () => self::doAsk($suggestion, $staff, $body, $token), fn (Message $m) => $m->thread);
    }

    private static function doAsk(FieldSuggestion $suggestion, User $staff, string $body, ?string $token): MessageThread
    {
        $message = null;
        $thread = DB::transaction(function () use ($suggestion, $staff, $body, $token, &$message) {
            $thread = $suggestion->thread ?? MessageThread::create([
                'kind' => MessageThread::KIND_SUGGESTION,
                'user_id' => $suggestion->user_id,
                'field_suggestion_id' => $suggestion->id,
                'subject' => 'پرسش درباره‌ی پیشنهاد #' . \App\Support\Fa::digits($suggestion->id) . ' (' . $suggestion->field_label . ')',
                'started_by' => $staff->id,
                'reply_token' => self::token(),
            ]);
            $message = self::write($thread, $staff, $body, true, 'site', $token);
            $suggestion->update(['awaiting_reply_since' => now(), 'reply_reminded_at' => null, 'reply_state' => null]);
            self::log($staff, $suggestion, 'ask', $thread);

            return $thread;
        });

        self::notify($thread->user, new ThreadNotice($thread, 'suggestion.question',
            'پرسش داور درباره‌ی پیشنهاد شما',
            ['رکورد: ' . self::recordLabel($suggestion), 'پیشنهاد شما: ' . Str::limit((string) $suggestion->suggested_value, 200), 'پرسش داور: ' . $body,
                'پیشنهاد تا پاسخ شما در انتظار می‌ماند؛ اگر تا ' . \App\Support\Fa::num((int) config('notifications.question_deadline_days')) . ' روز پاسخی نرسد، داور بی‌پاسخ درباره‌اش تصمیم می‌گیرد.'],
            route('my.messages.show', $thread)), $message);

        return $thread;
    }

    /**
     * A reviewer writes to the submitter from the suggestion's page, whatever its status: on a pending suggestion
     * it is a question that waits for the answer (ask); on a decided one a plain letter on the same thread, which
     * leaves the suggestion as it is and lets the submitter answer again. Returns the thread.
     */
    public static function toSubmitter(FieldSuggestion $suggestion, User $staff, string $body, ?string $token = null): MessageThread
    {
        // A send repeated after the first one asked (and so changed nothing) is still the first send
        if ($token && ($sent = self::sent($token))) {
            return $sent->thread;
        }
        if ($suggestion->status === 'pending') {
            return self::ask($suggestion, $staff, $body, $token);
        }
        abort_unless((bool) $suggestion->user_id, 422, 'پیشنهاد مهمان پیشنهاددهنده‌ای برای پیام ندارد.');

        return self::once($token, fn () => self::doLetter($suggestion, $staff, $body, $token), fn (Message $m) => $m->thread);
    }

    private static function doLetter(FieldSuggestion $suggestion, User $staff, string $body, ?string $token): MessageThread
    {
        $message = null;
        $thread = DB::transaction(function () use ($suggestion, $staff, $body, $token, &$message) {
            $thread = $suggestion->thread ?? MessageThread::create([
                'kind' => MessageThread::KIND_SUGGESTION,
                'user_id' => $suggestion->user_id,
                'field_suggestion_id' => $suggestion->id,
                'subject' => 'درباره‌ی پیشنهاد #' . \App\Support\Fa::digits($suggestion->id) . ' (' . $suggestion->field_label . ')',
                'started_by' => $staff->id,
                'reply_token' => self::token(),
            ]);
            $message = self::write($thread, $staff, $body, true, 'site', $token);
            self::log($staff, $suggestion, 'message', $thread);

            return $thread;
        });

        self::notify($thread->user, new ThreadNotice($thread, 'suggestion.question',
            'پیام داور درباره‌ی پیشنهاد شما',
            ['رکورد: ' . self::recordLabel($suggestion), 'پیشنهاد شما: ' . Str::limit((string) $suggestion->suggested_value, 200), 'پیام داور: ' . $body,
                'این پیشنهاد پیش‌تر داوری شده است و این پیام وضعیت آن را تغییر نمی‌دهد. اگر خواستید، پاسخ دهید.'],
            route('my.messages.show', $thread)), $message);

        return $thread;
    }

    /** An admin's direct message to a user (no $staff: sent by a server command); returns the new thread */
    public static function sendDirect(User $recipient, ?User $staff, string $subject, string $body, ?string $template = null, ?string $token = null): MessageThread
    {
        return self::once($token, fn () => self::doDirect($recipient, $staff, $subject, $body, $template, $token), fn (Message $m) => $m->thread);
    }

    private static function doDirect(User $recipient, ?User $staff, string $subject, string $body, ?string $template, ?string $token): MessageThread
    {
        $message = null;
        $thread = DB::transaction(function () use ($recipient, $staff, $subject, $body, $template, $token, &$message) {
            $thread = MessageThread::create([
                'kind' => MessageThread::KIND_DIRECT,
                'user_id' => $recipient->id,
                'subject' => $subject,
                'started_by' => $staff?->id,
                'template' => $template,
                'reply_token' => self::token(),
            ]);
            $message = self::write($thread, $staff, $body, true, 'site', $token);
            self::log($staff, $recipient, 'message', $thread);

            return $thread;
        });
        self::notifyUserOfStaffLetter($thread, $body, $message);

        return $thread;
    }

    /** A further letter of the staff in an existing thread */
    public static function staffWrite(MessageThread $thread, User $staff, string $body, ?string $token = null): void
    {
        if ($thread->kind === MessageThread::KIND_SUGGESTION) {
            self::toSubmitter($thread->suggestion, $staff, $body, $token);

            return;
        }
        self::once($token, function () use ($thread, $staff, $body, $token) {
            $message = self::write($thread, $staff, $body, true, 'site', $token);
            self::log($staff, $thread->user, 'message', $thread);
            self::notifyUserOfStaffLetter($thread, $body, $message);
        }, fn () => null);
    }

    /** The user answers, on the site or by email; a suggestion waiting for it goes back to the queue */
    public static function userReply(MessageThread $thread, string $body, string $via = 'site', ?string $token = null): Message
    {
        // An email has no form to carry a token of its own: the same text from the same user a little while ago is
        // the same letter sent again (an email client's retry, or the user sending twice)
        if ($via === 'email' && ($same = self::recentSameReply($thread, $body))) {
            return $same;
        }

        return self::once($token, fn () => self::doReply($thread, $body, $via, $token), fn (Message $m) => $m);
    }

    private static function doReply(MessageThread $thread, string $body, string $via, ?string $token): Message
    {
        $user = $thread->user;
        $backToQueue = $thread->suggestion?->status === 'pending';
        $message = DB::transaction(function () use ($thread, $user, $body, $via, $token) {
            $message = self::write($thread, $user, $body, false, $via, $token);
            $thread->update(['user_read_at' => now()]);
            $suggestion = $thread->suggestion;
            if ($suggestion && $suggestion->awaiting_reply_since) {
                $suggestion->update(['awaiting_reply_since' => null, 'reply_state' => 'answered']);
            }

            return $message;
        });

        if ($thread->kind === MessageThread::KIND_SUGGESTION) {
            $notice = new ThreadNotice($thread, 'suggestion.replied', 'پاسخ پیشنهاددهنده به پرسش شما',
                ['پیشنهاد #' . $thread->field_suggestion_id . ' — ' . self::recordLabel($thread->suggestion), 'پاسخ: ' . Str::limit($body, 1000),
                    $backToQueue ? 'پیشنهاد به صف داوری برگشت.' : 'این پیشنهاد پیش‌تر داوری شده است؛ وضعیتش تغییر نکرد.'],
                route('admin.suggestions.show', $thread->field_suggestion_id), 'مشاهده');
            self::notifyStaff($thread, 'suggestion.replied', $notice->forMessage($message));
        } else {
            $notice = new ThreadNotice($thread, 'message.replied', 'پاسخ ' . $user->name . ' به پیام «' . Str::limit($thread->subject, 60) . '»',
                ['پاسخ: ' . Str::limit($body, 1000)], route('admin.messages.show', $thread), 'مشاهده');
            self::notifyStaff($thread, 'message.replied', $notice->forMessage($message));
        }

        return $message;
    }

    /** The user reports a direct message as unwanted or offensive; the admins are told */
    public static function report(MessageThread $thread): void
    {
        if ($thread->reported_at) {
            return;
        }
        $thread->update(['reported_at' => now()]);
        NotificationPreferences::broadcast('message.reported', new ThreadNotice($thread, 'message.reported',
            'گزارش پیام به دست کاربر', [$thread->user->name . ' پیام «' . $thread->subject . '» را گزارش کرد.'], route('admin.messages.show', $thread), 'مشاهده'));
    }

    /**
     * The daily pass over questions left unanswered: one reminder after question_reminder_days, and after
     * question_deadline_days the suggestion goes back to the queue marked «بی‌پاسخ». Returns [reminded, returned].
     */
    public static function expire(): array
    {
        $reminded = $returned = 0;
        $deadline = now()->subDays((int) config('notifications.question_deadline_days'));
        foreach (FieldSuggestion::where('status', 'pending')->where('awaiting_reply_since', '<', $deadline)->get() as $s) {
            $s->update(['awaiting_reply_since' => null, 'reply_state' => 'unanswered']);
            $returned++;
        }

        $remindAt = now()->subDays((int) config('notifications.question_reminder_days'));
        foreach (FieldSuggestion::with('thread.user')->where('status', 'pending')->where('awaiting_reply_since', '<', $remindAt)->whereNull('reply_reminded_at')->get() as $s) {
            $s->update(['reply_reminded_at' => now()]);
            if (! $s->thread) {
                continue;
            }
            $last = $s->thread->messages()->where('is_staff', true)->reorder()->latest('id')->value('body');
            self::notify($s->thread->user, new ThreadNotice($s->thread, 'suggestion.question', 'یادآوری: پرسش داور درباره‌ی پیشنهاد شما',
                ['رکورد: ' . self::recordLabel($s), 'پرسش داور: ' . $last, 'اگر تا ' . \App\Support\Fa::num((int) config('notifications.question_deadline_days') - (int) config('notifications.question_reminder_days')) . ' روز دیگر پاسخی نرسد، داور بی‌پاسخ درباره‌ی پیشنهاد تصمیم می‌گیرد.'],
                route('my.messages.show', $s->thread)));
            $reminded++;
        }

        return [$reminded, $returned];
    }

    /** The reply-to address of a thread's emails, when replies by email are set up */
    public static function replyAddress(MessageThread $thread): ?string
    {
        $domain = config('notifications.inbound.reply_domain');

        return $domain ? "reply+{$thread->reply_token}@{$domain}" : null;
    }

    /** The thread a reply-to address points to */
    public static function threadForAddress(string $address): ?MessageThread
    {
        $domain = preg_quote((string) config('notifications.inbound.reply_domain'), '~');
        if (! $domain || ! preg_match('~reply\+([A-Za-z0-9]{32})@' . $domain . '~i', $address, $m)) {
            return null;
        }

        return MessageThread::where('reply_token', $m[1])->first();
    }

    /** The letter a client token was first sent with, deleted or not */
    public static function sent(?string $token): ?Message
    {
        return $token ? Message::withTrashed()->with('thread')->where('client_token', $token)->first() : null;
    }

    /** A client token for a form: one per rendering, so each send of the form is told from a repeat of it */
    public static function newToken(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Send once per token: a token already used returns what its first send made, and the unique index settles two
     * requests that arrive together (the second one's insert fails, its transaction is rolled back, nothing is notified).
     */
    private static function once(?string $token, \Closure $send, \Closure $existing): mixed
    {
        if ($token && ($sent = self::sent($token))) {
            return $existing($sent);
        }
        try {
            return $send();
        } catch (UniqueConstraintViolationException $e) {
            if ($token && ($sent = self::sent($token))) {
                return $existing($sent);
            }
            throw $e;
        }
    }

    private static function recentSameReply(MessageThread $thread, string $body): ?Message
    {
        return $thread->messages()->where('is_staff', false)->where('author_id', $thread->user_id)
            ->where('created_at', '>=', now()->subMinutes((int) config('notifications.duplicate_reply_minutes')))
            ->where('body', trim($body))->reorder()->latest('id')->first();
    }

    /**
     * An admin removes a letter that came twice (soft delete; backlog item 115). The user no longer sees it, it no
     * longer counts as unread, and a notice still waiting in the queue is dropped; an email already sent stays sent.
     */
    public static function deleteMessage(Message $message, User $staff): void
    {
        DB::transaction(function () use ($message, $staff) {
            $thread = $message->thread;
            $message->delete();
            $thread->update(['last_message_at' => $thread->messages()->max('created_at')]);
            AuditLog::create([
                'user_id' => $staff->id,
                'auditable_type' => MessageThread::class,
                'auditable_id' => $thread->id,
                'action' => 'delete_message',
                'new_values' => ['message_id' => $message->id, 'author_id' => $message->author_id, 'is_staff' => $message->is_staff],
                'ip_address' => request()?->ip(),
            ]);
        });
    }

    private static function write(MessageThread $thread, ?User $author, string $body, bool $staff, string $via = 'site', ?string $token = null): Message
    {
        $message = $thread->messages()->create(['author_id' => $author?->id, 'is_staff' => $staff, 'body' => trim($body), 'via' => $via, 'client_token' => $token]);
        $thread->update(['last_message_at' => $message->created_at] + ($staff ? ['staff_read_at' => now()] : []));

        return $message;
    }

    private static function notifyUserOfStaffLetter(MessageThread $thread, string $body, ?Message $message = null): void
    {
        self::notify($thread->user, new ThreadNotice($thread, 'admin.message', $thread->subject, [$body], route('my.messages.show', $thread)), $message);
    }

    /** To the staff member who wrote last, while they still hold the thread's permission; otherwise to everyone who holds it */
    private static function notifyStaff(MessageThread $thread, string $type, ThreadNotice $notice): void
    {
        $staffId = $thread->messages()->where('is_staff', true)->reorder()->latest('id')->value('author_id');
        $staff = $staffId ? User::find($staffId) : null;
        if ($staff && $staff->hasPermission($thread->staffPermission())) {
            self::notify($staff, $notice);

            return;
        }
        NotificationPreferences::broadcast($type, $notice);
    }

    private static function notify(?User $user, ThreadNotice $notice, ?Message $message = null): void
    {
        if (! $user) {
            return;
        }
        if ($message) {
            $notice->forMessage($message);
        }
        try {
            $user->notify($notice);
        } catch (\Throwable $e) {
            Log::warning("Thread notice {$notice->type()} failed: " . $e->getMessage());
        }
    }

    /** The event without the letter's text, which only the two sides read */
    private static function log(?User $staff, FieldSuggestion|User $about, string $event, MessageThread $thread): void
    {
        AuditLog::create([
            'user_id' => $staff?->id,
            'auditable_type' => $about::class,
            'auditable_id' => $about->id,
            'action' => $event,
            'new_values' => ['thread_id' => $thread->id],
            'ip_address' => request()->ip(),
        ]);
    }

    private static function recordLabel(?FieldSuggestion $s): string
    {
        $record = $s?->suggestable;

        return $record ? \App\Suggestions\SuggestionRegistry::describe($record)['label'] : 'رکورد حذف‌شده';
    }

    private static function token(): string
    {
        return Str::random(32);
    }
}
