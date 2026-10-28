<?php

namespace Tests\Feature;

use App\Messaging\ReplyText;
use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\MessageTemplate;
use App\Models\MessageThread;
use App\Models\User;
use App\Models\Work;
use App\Notifications\NotificationPreferences;
use App\Notifications\ThreadNotice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Letters between the staff and users (backlog item 91): questions on suggestions and direct messages */
class MessagingTest extends TestCase
{
    private function user(string $role = 'user', array $extra = []): User
    {
        return User::create($extra + ['name' => "کاربر {$role}", 'email' => $role . '-' . uniqid() . '@example.com', 'password' => 'password123', 'role' => $role]);
    }

    private function suggestion(?User $by, array $extra = []): FieldSuggestion
    {
        $work = Work::create(['primary_title' => 'اثر آزمون پیام', 'clean_title' => 'اثر آزمون پیام', 'slug' => 'msg-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $m = Manuscript::create(['work_id' => $work->id, 'shelfmark' => '۱', 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);

        return FieldSuggestion::create($extra + ['suggestable_type' => Manuscript::class, 'suggestable_id' => $m->id, 'kind' => 'field', 'field_name' => 'copy_place',
            'suggested_value' => 'مشهد', 'rationale_citation' => 'فهرست کتابخانه', 'status' => 'pending', 'user_id' => $by?->id]);
    }

    private function ask(User $reviewer, FieldSuggestion $s, string $body = 'آیا نشانی فهرستی را که به آن ارجاع داده‌اید دارید؟')
    {
        return $this->actingAs($reviewer)->post(route('admin.suggestions.ask', $s), ['body' => $body]);
    }

    public function test_a_question_takes_the_suggestion_out_of_the_queue_until_the_submitter_answers(): void
    {
        Notification::fake();
        $editor = $this->user('editor');
        $submitter = $this->user();
        $s = $this->suggestion($submitter);

        $this->ask($editor, $s)->assertRedirect();
        $s->refresh();
        $this->assertNotNull($s->awaiting_reply_since);
        $this->assertSame('pending', $s->status);
        $this->assertSame(0, FieldSuggestion::inQueue()->count());
        $this->actingAs($editor)->get(route('admin.suggestions.index'))->assertDontSee('مشهد');
        $this->actingAs($editor)->get(route('admin.suggestions.index', ['status' => 'awaiting']))->assertSee('منتظر پاسخ');

        // The submitter hears, with the question and a link to answer; the reviewer's name stays out of it
        Notification::assertSentTo($submitter, ThreadNotice::class, function (ThreadNotice $n, array $channels) use ($submitter, $editor) {
            $mail = (string) $n->toMail($submitter)->render();

            return $n->type() === 'suggestion.question' && in_array('mail', $channels, true)
                && str_contains($mail, 'نشانی فهرستی') && str_contains($mail, route('my.messages.show', $n->thread))
                && ! str_contains($mail, $editor->name) && ! str_contains($mail, $editor->email);
        });
        // Logged without the text
        $log = AuditLog::where('action', 'ask')->sole();
        $this->assertStringNotContainsString('نشانی فهرستی', json_encode($log->new_values, JSON_UNESCAPED_UNICODE));

        // «پیشنهادهای من» and «پیام‌ها» show it waiting, as «داور فهارس»
        $this->actingAs($submitter)->get(route('my.suggestions'))->assertSee('منتظر پاسخ شما');
        $thread = MessageThread::sole();
        $this->actingAs($submitter)->get(route('my.messages.show', $thread))->assertOk()->assertSee('داور فهارس')->assertDontSee($editor->name);

        $this->actingAs($submitter)->post(route('my.messages.reply', $thread), ['body' => 'بله، فهرست جلد ۳ صفحه‌ی ۴۵'])->assertRedirect();
        $s->refresh();
        $this->assertNull($s->awaiting_reply_since);
        $this->assertSame('answered', $s->reply_state);
        $this->assertSame(1, FieldSuggestion::inQueue()->count());
        Notification::assertSentTo($editor, ThreadNotice::class, fn (ThreadNotice $n) => $n->type() === 'suggestion.replied');
        $this->actingAs($editor)->get(route('admin.suggestions.show', $s))->assertSee('فهرست جلد ۳ صفحه‌ی ۴۵')->assertSee('پاسخ داد');
    }

    public function test_a_decision_ends_the_wait_and_closes_the_thread(): void
    {
        Notification::fake();
        $editor = $this->user('editor');
        $submitter = $this->user();
        $s = $this->suggestion($submitter);
        $this->ask($editor, $s);

        $this->actingAs($editor)->post(route('admin.suggestions.reject', $s), ['public_note' => 'مستند کافی نبود']);
        $s->refresh();
        $this->assertSame('rejected', $s->status);
        $this->assertNull($s->awaiting_reply_since);
        $thread = MessageThread::sole();
        $this->assertFalse($thread->acceptsReplies());
        $this->actingAs($submitter)->post(route('my.messages.reply', $thread), ['body' => 'دیر شد'])->assertStatus(409);
    }

    public function test_a_user_reaches_only_their_own_threads(): void
    {
        Notification::fake();
        $editor = $this->user('editor');
        $submitter = $this->user();
        $other = $this->user();
        $this->ask($editor, $this->suggestion($submitter));
        $thread = MessageThread::sole();

        $this->actingAs($other)->get(route('my.messages.show', $thread))->assertNotFound();
        $this->actingAs($other)->post(route('my.messages.reply', $thread), ['body' => 'دخالت'])->assertNotFound();
        $this->actingAs($other)->post(route('my.messages.report', $thread))->assertNotFound();
        $this->actingAs($other)->get(route('my.messages'))->assertOk()->assertDontSee($thread->subject);
        $this->get(route('my.messages'))->assertOk();
        auth()->logout();
        $this->get(route('my.messages.show', $thread))->assertRedirect(route('login'));
        $this->assertSame(0, $thread->messages()->where('is_staff', false)->count());
    }

    public function test_only_reviewers_ask_and_never_about_a_guest_suggestion(): void
    {
        Notification::fake();
        $s = $this->suggestion($this->user());
        $this->ask($this->user('researcher'), $s)->assertForbidden();
        $this->assertNull($s->fresh()->awaiting_reply_since);

        $guest = $this->suggestion(null, ['guest_email' => 'guest@example.com', 'guest_name' => 'مهمان']);
        $editor = $this->user('editor');
        $this->ask($editor, $guest)->assertStatus(422);
        $this->actingAs($editor)->get(route('admin.suggestions.show', $guest))->assertSee('پرسش از پیشنهاددهنده ممکن نیست');
        $this->assertSame(0, MessageThread::count());
    }

    public function test_a_reviewer_without_the_permission_cannot_send_direct_messages(): void
    {
        Notification::fake();
        $editor = $this->user('editor');
        $target = $this->user();
        $this->assertFalse($editor->hasPermission('users.message'));

        $this->actingAs($editor)->get(route('admin.messages.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('admin.messages.create', ['user' => $target->id]))->assertForbidden();
        $this->actingAs($editor)->post(route('admin.messages.store'), ['user_id' => $target->id, 'subject' => 'سلام', 'body' => 'متن'])->assertForbidden();
        $this->actingAs($editor)->get(route('admin.messages.templates'))->assertForbidden();
        $this->assertSame(0, MessageThread::count());
        Notification::assertNothingSent();

        // Granted in the matrix, the editor may
        \Illuminate\Support\Facades\DB::table('role_permissions')->insert(['role' => 'editor', 'permission' => 'users.message']);
        \Illuminate\Support\Facades\Cache::forget('role_permissions');
        $this->actingAs($editor)->post(route('admin.messages.store'), ['user_id' => $target->id, 'subject' => 'سلام', 'body' => 'متن'])->assertRedirect();
        $this->assertSame(1, MessageThread::count());
    }

    public function test_a_direct_message_from_a_template_always_reaches_the_site_and_its_answer_reaches_the_sender(): void
    {
        Notification::fake();
        $admin = $this->user('admin');
        $target = $this->user('user', ['name' => 'مریم احمدی']);
        $this->suggestion($target, ['status' => 'approved']);

        // The templates are filled for the recipient on the compose page
        $this->actingAs($admin)->get(route('admin.messages.create', ['user' => $target->id]))->assertOk()
            ->assertSee('مریم احمدی گرامی', false)->assertSee('تا امروز ۱ پیشنهاد شما', false)->assertDontSee($target->email);

        // The in-site channel cannot be switched off; the email can
        NotificationPreferences::set($target, 'admin.message', 'database', false);
        NotificationPreferences::set($target, 'admin.message', 'mail', false);
        $this->assertTrue(NotificationPreferences::enabled($target, 'admin.message', 'database'));
        $this->actingAs($target)->get(route('notifications.settings'))->assertSee('در «پیام‌ها»ی سایت همیشه می‌رسد.');

        $this->actingAs($admin)->post(route('admin.messages.store'), ['user_id' => $target->id, 'subject' => 'به فهارس خوش آمدید', 'body' => 'مریم احمدی گرامی، سلام.'])->assertRedirect();
        Notification::assertSentTo($target, ThreadNotice::class, fn (ThreadNotice $n, array $channels) => $n->type() === 'admin.message' && $channels === ['database']);
        $thread = MessageThread::sole();
        $this->assertSame('direct', $thread->kind);

        $this->actingAs($target)->get(route('my.messages'))->assertSee('به فهارس خوش آمدید')->assertSee('مدیریت فهارس')->assertDontSee($admin->name);
        $this->actingAs($target)->post(route('my.messages.reply', $thread), ['body' => 'سپاس از شما'])->assertRedirect();
        Notification::assertSentTo($admin, ThreadNotice::class, fn (ThreadNotice $n) => $n->type() === 'message.replied');
        $this->actingAs($admin)->get(route('admin.messages.show', $thread))->assertSee('سپاس از شما');

        // Reported, the admins hear; closed, no answer is taken
        $this->actingAs($target)->post(route('my.messages.report', $thread))->assertRedirect();
        $this->assertNotNull($thread->fresh()->reported_at);
        Notification::assertSentTo($admin, ThreadNotice::class, fn (ThreadNotice $n) => $n->type() === 'message.reported');
        $this->actingAs($admin)->post(route('admin.messages.toggle', $thread));
        $this->actingAs($target)->post(route('my.messages.reply', $thread), ['body' => 'باز هم'])->assertStatus(409);
    }

    public function test_an_unanswered_question_is_reminded_once_then_returns_to_the_queue(): void
    {
        Notification::fake();
        $editor = $this->user('editor');
        $submitter = $this->user();
        $s = $this->suggestion($submitter);
        $this->ask($editor, $s);

        $this->travel(8)->days();
        $this->artisan('notifications:suggestion-questions')->expectsOutputToContain('Reminded 1, returned 0')->assertSuccessful();
        $this->artisan('notifications:suggestion-questions')->expectsOutputToContain('Reminded 0, returned 0');
        $this->assertCount(2, Notification::sent($submitter, ThreadNotice::class));

        $this->travel(7)->days();
        $this->artisan('notifications:suggestion-questions')->expectsOutputToContain('returned 1');
        $s->refresh();
        $this->assertNull($s->awaiting_reply_since);
        $this->assertSame('unanswered', $s->reply_state);
        $this->assertSame(1, FieldSuggestion::inQueue()->count());
        $this->actingAs($editor)->get(route('admin.suggestions.index'))->assertSee('بی‌پاسخ ماند');
    }

    public function test_an_email_reply_is_filed_in_its_thread_when_signed_and_from_the_user(): void
    {
        Notification::fake();
        $secret = 'whsec_' . base64_encode('test-signing-key-of-32-bytes!!!!');
        config(['notifications.inbound.reply_domain' => 'reply.fahares.test', 'notifications.inbound.webhook_secret' => $secret, 'services.resend.key' => 're_test']);
        $editor = $this->user('editor');
        $submitter = $this->user();
        $s = $this->suggestion($submitter);
        $this->ask($editor, $s);
        $thread = MessageThread::sole();
        $address = "reply+{$thread->reply_token}@reply.fahares.test";

        // The email carries the thread's reply-to
        Notification::assertSentTo($submitter, ThreadNotice::class, fn (ThreadNotice $n) => $n->toMail($submitter)->replyTo === [[$address, 'فهارس']]);

        Http::fake(['api.resend.com/emails/receiving/*' => Http::response(['text' => "بله، دارم: فهرست جلد ۳\n\nOn Tue, Oct 28, 2026 at 10:00 فهارس <noreply@fahares.net> wrote:\n> آیا نشانی فهرستی را دارید؟"])]);
        $post = function (string $from, ?string $sign = null) use ($address, $secret) {
            $body = json_encode(['type' => 'email.received', 'data' => ['email_id' => 'em_1', 'from' => "کاربر <{$from}>", 'to' => [$address]]]);
            $id = 'msg_' . uniqid();
            $ts = (string) time();
            $signature = 'v1,' . base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$body}", base64_decode(substr($sign ?? $secret, 6)), true));

            return $this->call('POST', route('messages.inbound'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_SVIX_ID' => $id, 'HTTP_SVIX_TIMESTAMP' => $ts, 'HTTP_SVIX_SIGNATURE' => $signature], $body);
        };

        // Not signed with the secret: refused; from someone else: dropped
        $post($submitter->email, 'whsec_' . base64_encode('another-key'))->assertForbidden();
        $post('stranger@example.com')->assertOk();
        $this->assertSame(0, $thread->messages()->where('is_staff', false)->count());

        $post(strtoupper($submitter->email))->assertOk();
        $reply = $thread->messages()->where('is_staff', false)->sole();
        $this->assertSame(['بله، دارم: فهرست جلد ۳', 'email'], [$reply->body, $reply->via]);
        $this->assertSame('answered', $s->fresh()->reply_state);
        Notification::assertSentTo($editor, ThreadNotice::class, fn (ThreadNotice $n) => $n->type() === 'suggestion.replied');
    }

    public function test_the_quoted_history_is_cut_from_an_email_reply(): void
    {
        $this->assertSame('سلام، نشانی را فرستادم.', ReplyText::extract("سلام، نشانی را فرستادم.\n\nدر تاریخ سه‌شنبه ۶ آبان ۱۴۰۵، فهارس نوشت:\n> پرسش"));
        $this->assertSame('خط اول', ReplyText::extract("خط اول\n-- \nامضا"));
        $this->assertSame('از html', ReplyText::extract(null, '<div>از html</div><blockquote>نقل قول</blockquote>'));
        $this->assertSame("یک\nدو", ReplyText::extract("یک\n> نقل\nدو"));
    }

    public function test_the_persian_name_request_is_a_direct_message_from_its_template(): void
    {
        Notification::fake();
        $latin = $this->user('user', ['name' => 'Ali Rezaei']);
        MessageTemplate::where('key', 'persian_name')->update(['body' => 'نام شما «{نام}» است. {پیوند_حساب}']);

        $this->artisan('fahares:ask-persian-names')->expectsOutputToContain('Notifying 1 user(s).')->assertSuccessful();
        $thread = MessageThread::where('user_id', $latin->id)->sole();
        $this->assertSame(['persian_name', null], [$thread->template, $thread->started_by]);
        $this->assertSame('نام شما «Ali Rezaei» است. ' . route('profile'), $thread->messages->sole()->body);
        Notification::assertSentTo($latin, ThreadNotice::class, fn (ThreadNotice $n) => $n->type() === 'admin.message');

        $this->artisan('fahares:ask-persian-names', ['--dry-run' => true])->expectsOutputToContain('Would notify 0 user(s).');
    }
}
