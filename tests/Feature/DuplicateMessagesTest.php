<?php

namespace Tests\Feature;

use App\Messaging\Conversations;
use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ThreadNotice;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** A letter sent twice on a flaky connection is written and notified once, and a duplicate can be deleted (backlog item 115, step 14.71) */
class DuplicateMessagesTest extends TestCase
{
    private const SIGNING_KEY = 'test-signing-key-of-32-bytes!!!!';

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'مدیر', 'email' => 'dup-admin@fahares.net', 'password' => 'password123', 'role' => 'admin']);
        $this->member = User::create(['name' => 'پژوهشگر', 'email' => 'dup-member@example.com', 'password' => 'password123', 'role' => 'user']);
    }

    private function suggestion(): FieldSuggestion
    {
        $work = Work::create(['primary_title' => 'اثر آزمون تکرار', 'clean_title' => 'اثر آزمون تکرار', 'slug' => 'dup-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $m = Manuscript::create(['work_id' => $work->id, 'shelfmark' => '۱', 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);

        return FieldSuggestion::create(['suggestable_type' => Manuscript::class, 'suggestable_id' => $m->id, 'kind' => 'field', 'field_name' => 'copy_place',
            'suggested_value' => 'مشهد', 'rationale_citation' => 'فهرست', 'status' => 'pending', 'user_id' => $this->member->id]);
    }

    public function test_a_question_sent_twice_with_one_token_is_one_letter_and_one_notice(): void
    {
        Notification::fake();
        $s = $this->suggestion();
        $this->actingAs($this->admin)->get(route('admin.suggestions.show', $s))->assertOk()->assertSee('name="client_token"', false)->assertSee('data-send-once', false);

        foreach ([1, 2] as $_) {
            $this->actingAs($this->admin)->post(route('admin.suggestions.ask', $s), ['body' => 'منبع شما چیست؟', 'client_token' => 'tok-ask'])
                ->assertRedirect(route('admin.suggestions.show', $s) . '#conversation');
        }

        $this->assertSame(1, Message::count());
        Notification::assertSentToTimes($this->member, ThreadNotice::class, 1);
        // The notice waits a little, and knows its letter
        Notification::assertSentTo($this->member, ThreadNotice::class, fn (ThreadNotice $n) => $n->messageId === Message::sole()->id && $n->delay !== null);

        // Another send of the form (a new token) is a new letter
        $this->actingAs($this->admin)->post(route('admin.suggestions.ask', $s), ['body' => 'منبع شما چیست؟', 'client_token' => 'tok-ask-2']);
        $this->assertSame(2, Message::count());
    }

    public function test_direct_message_and_user_reply_are_written_once_per_token(): void
    {
        Notification::fake();
        foreach ([1, 2] as $_) {
            $this->actingAs($this->admin)->post(route('admin.messages.store'), ['user_id' => $this->member->id, 'subject' => 'سلام', 'body' => 'متن پیام', 'client_token' => 'tok-direct']);
        }
        $thread = MessageThread::sole();
        $this->assertSame(1, $thread->messages()->count());
        Notification::assertSentToTimes($this->member, ThreadNotice::class, 1);

        foreach ([1, 2] as $_) {
            $this->actingAs($this->member)->post(route('my.messages.reply', $thread), ['body' => 'پاسخ من', 'client_token' => 'tok-reply'])
                ->assertRedirect(route('my.messages.show', $thread));
        }
        $this->assertSame(2, $thread->messages()->count());

        foreach ([1, 2] as $_) {
            $this->actingAs($this->admin)->post(route('admin.messages.reply', $thread), ['body' => 'پیام دوم', 'client_token' => 'tok-staff']);
        }
        $this->assertSame(3, $thread->messages()->count());
        Notification::assertSentToTimes($this->member, ThreadNotice::class, 2);
    }

    public function test_the_token_is_unique_in_the_table(): void
    {
        $thread = Conversations::sendDirect($this->member, $this->admin, 'موضوع', 'متن', null, 'tok-unique');
        $this->expectException(UniqueConstraintViolationException::class);
        $thread->messages()->create(['author_id' => $this->admin->id, 'is_staff' => true, 'body' => 'دیگر', 'client_token' => 'tok-unique']);
    }

    public function test_an_email_reply_filed_once_by_its_id_and_by_its_text(): void
    {
        Notification::fake();
        config(['notifications.inbound.reply_domain' => 'reply.fahares.test', 'notifications.inbound.webhook_secret' => 'whsec_' . base64_encode(self::SIGNING_KEY), 'services.resend.key' => 're_test']);
        $thread = Conversations::sendDirect($this->member, $this->admin, 'موضوع', 'متن');
        Http::fake(['api.resend.com/emails/receiving/*' => Http::response(['text' => "همان پاسخ\n\nOn Mon, someone wrote:\n> متن"])]);

        $this->inbound($thread, 'em_1')->assertOk();
        $this->inbound($thread, 'em_1')->assertOk();   // the webhook delivered again
        $this->inbound($thread, 'em_2')->assertOk();   // the user sent the same text again
        $this->assertSame(1, $thread->messages()->where('is_staff', false)->count());

        // Past the window the same text is a new letter
        $this->travel((int) config('notifications.duplicate_reply_minutes') + 1)->minutes();
        $this->inbound($thread, 'em_3')->assertOk();
        $this->assertSame(2, $thread->messages()->where('is_staff', false)->count());
    }

    public function test_admin_deletes_a_duplicate_letter(): void
    {
        Notification::fake();
        $thread = Conversations::sendDirect($this->member, $this->admin, 'موضوع', 'نخستین');
        Conversations::staffWrite($thread, $this->admin, 'متن تکراری');
        $duplicate = $thread->messages()->reorder()->latest('id')->first();
        $this->assertSame(1, MessageThread::whereKey($thread->id)->unreadByUser()->count());

        $this->actingAs($this->admin)->get(route('admin.messages.show', $thread))->assertOk()->assertSee(route('admin.letters.destroy', $duplicate), false);
        $this->actingAs($this->admin)->delete(route('admin.letters.destroy', $duplicate))->assertRedirect(route('admin.messages.show', $thread));

        $this->assertSoftDeleted($duplicate);
        $this->actingAs($this->member)->get(route('my.messages.show', $thread))->assertOk()->assertSee('نخستین')->assertDontSee('متن تکراری');
        $this->assertTrue(AuditLog::where('action', 'delete_message')->where('auditable_id', $thread->id)->exists());

        // A notice still in the queue for it is dropped
        $notice = (new ThreadNotice($thread, 'admin.message', 'موضوع', ['متن تکراری'], '/'))->forMessage($duplicate);
        $this->assertFalse($notice->shouldSend($this->member, 'mail'));

        // Read once, the thread has nothing unread; the deleted letter, newer than the reading, does not count
        $thread->update(['user_read_at' => $thread->messages()->min('created_at')]);
        $duplicate->forceFill(['created_at' => now()->addMinute()])->saveQuietly();
        $this->assertSame(0, MessageThread::whereKey($thread->id)->unreadByUser()->count());
    }

    public function test_only_staff_of_the_thread_delete(): void
    {
        $thread = Conversations::sendDirect($this->member, $this->admin, 'موضوع', 'متن');
        $letter = $thread->messages()->sole();
        $this->actingAs($this->member)->delete(route('admin.letters.destroy', $letter))->assertForbidden();
        $this->assertNotSoftDeleted($letter);
    }

    private function inbound(MessageThread $thread, string $emailId)
    {
        $body = json_encode(['type' => 'email.received', 'data' => ['email_id' => $emailId, 'from' => $this->member->email, 'to' => ["reply+{$thread->reply_token}@reply.fahares.test"]]]);
        $id = 'msg_' . uniqid();
        $ts = (string) time();
        $signature = 'v1,' . base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$body}", self::SIGNING_KEY, true));

        return $this->call('POST', route('messages.inbound'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_SVIX_ID' => $id, 'HTTP_SVIX_TIMESTAMP' => $ts, 'HTTP_SVIX_SIGNATURE' => $signature], $body);
    }
}
