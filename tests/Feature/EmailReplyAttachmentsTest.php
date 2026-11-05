<?php

namespace Tests\Feature;

use App\Messaging\EmailAttachments;
use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Pictures attached to an email reply are kept with the letter and shown to its thread only (step 14.36) */
class EmailReplyAttachmentsTest extends TestCase
{
    private const SECRET = 'whsec_dGVzdC1zaWduaW5nLWtleS1vZi0zMi1ieXRlcyEhISE=';

    private function user(string $role = 'user'): User
    {
        return User::create(['name' => "کاربر {$role}", 'email' => $role . '-' . uniqid() . '@example.com', 'password' => 'password123', 'role' => $role]);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(4, 3);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** A thread asked about a suggestion of $submitter, and its reply-to address */
    private function thread(User $editor, User $submitter): MessageThread
    {
        config(['notifications.inbound.reply_domain' => 'reply.fahares.test', 'notifications.inbound.webhook_secret' => self::SECRET, 'services.resend.key' => 're_test']);
        $work = Work::create(['primary_title' => 'اثر آزمون پیوست', 'clean_title' => 'اثر آزمون پیوست', 'slug' => 'att-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $m = Manuscript::create(['work_id' => $work->id, 'shelfmark' => '۱', 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);
        $s = FieldSuggestion::create(['suggestable_type' => Manuscript::class, 'suggestable_id' => $m->id, 'kind' => 'field', 'field_name' => 'copy_place',
            'suggested_value' => 'مشهد', 'rationale_citation' => 'فهرست', 'status' => 'pending', 'user_id' => $submitter->id]);
        $this->actingAs($editor)->post(route('admin.suggestions.ask', $s), ['body' => 'تصویر نسخه را دارید؟']);

        return MessageThread::sole();
    }

    private function inbound(MessageThread $thread, string $from)
    {
        $body = json_encode(['type' => 'email.received', 'data' => ['email_id' => 'em_1', 'from' => $from, 'to' => ["reply+{$thread->reply_token}@reply.fahares.test"]]]);
        $id = 'msg_' . uniqid();
        $ts = (string) time();
        $signature = 'v1,' . base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$body}", base64_decode(substr(self::SECRET, 6)), true));

        return $this->call('POST', route('messages.inbound'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_SVIX_ID' => $id, 'HTTP_SVIX_TIMESTAMP' => $ts, 'HTTP_SVIX_SIGNATURE' => $signature], $body);
    }

    private function fakeResend(string $text): void
    {
        Http::fake([
            'api.resend.com/emails/receiving/em_1/attachments' => Http::response(['data' => [
                ['id' => 'a1', 'filename' => 'برگ نخست.png', 'content_type' => 'image/png', 'size' => 100, 'download_url' => 'https://cdn.resend.test/a1'],
                // Not a picture by its bytes, though it says so
                ['id' => 'a2', 'filename' => 'x.png', 'content_type' => 'image/png', 'size' => 100, 'download_url' => 'https://cdn.resend.test/a2'],
                ['id' => 'a3', 'filename' => 'نامه.pdf', 'content_type' => 'application/pdf', 'size' => 100, 'download_url' => 'https://cdn.resend.test/a3'],
            ]]),
            'api.resend.com/emails/receiving/em_1' => Http::response(['text' => $text, 'attachments' => [['id' => 'a1'], ['id' => 'a2'], ['id' => 'a3']]]),
            'cdn.resend.test/a1' => Http::response($this->png()),
            'cdn.resend.test/a2' => Http::response('<script>alert(1)</script>'),
            'cdn.resend.test/a3' => Http::response('%PDF-1.4'),
        ]);
    }

    public function test_the_pictures_of_an_email_reply_are_kept_and_shown_to_its_thread_only(): void
    {
        Notification::fake();
        Storage::fake('public');
        $editor = $this->user('editor');
        $submitter = $this->user();
        $thread = $this->thread($editor, $submitter);
        $this->fakeResend("[image: برگ نخست.png]\n\nبله، این تصویر برگ نخست است.\n\nOn Tue, Oct 28, 2026 at 10:00 فهارس <noreply@fahares.net> wrote:\n> تصویر نسخه را دارید؟");

        $this->inbound($thread, "کاربر <{$submitter->email}>")->assertOk();
        $reply = Message::where('is_staff', false)->sole();
        $this->assertSame('بله، این تصویر برگ نخست است.', $reply->body);
        $picture = $reply->attachments->sole();
        $this->assertSame(['برگ نخست.png', 'image/png'], [$picture->filename, $picture->content_type]);
        $this->assertStringStartsWith("message-attachments/{$thread->id}/{$reply->id}-", $picture->path);
        Storage::disk('public')->assertExists($picture->path);

        $url = route('messages.attachment', $picture);
        $this->actingAs($editor)->get(route('admin.suggestions.show', $thread->field_suggestion_id))->assertSee($url, false);
        $this->actingAs($submitter)->get(route('my.messages.show', $thread))->assertSee($url, false);
        $this->actingAs($editor)->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($submitter)->get($url)->assertOk();
        $this->actingAs($this->user())->get($url)->assertNotFound();
        auth()->logout();
        $this->get($url)->assertRedirect();
    }

    public function test_a_reply_of_pictures_alone_is_filed(): void
    {
        Notification::fake();
        Storage::fake('public');
        $submitter = $this->user();
        $thread = $this->thread($this->user('editor'), $submitter);
        $this->fakeResend('[image: برگ نخست.png]');

        $this->inbound($thread, $submitter->email)->assertOk();
        $reply = Message::where('is_staff', false)->sole();
        $this->assertSame(['', 1], [$reply->body, $reply->attachments->count()]);
    }

    public function test_gmail_markers_are_taken_out_of_the_text(): void
    {
        $this->assertSame("سلام\n\nمتن", EmailAttachments::withoutMarkers("[image: الف.png]\n[image: ب .png][image: تحفه سامی تصحیح\nدستگردی.png]\n\n[image: image.png][image: image.png]\n\nسلام\n\nمتن"));
        $this->assertSame('[نه تصویر]', EmailAttachments::withoutMarkers('[نه تصویر]'));
    }

    public function test_the_pictures_of_an_earlier_reply_are_fetched_by_command(): void
    {
        Notification::fake();
        Storage::fake('public');
        $submitter = $this->user();
        $thread = $this->thread($this->user('editor'), $submitter);
        $reply = $thread->messages()->create(['author_id' => $submitter->id, 'is_staff' => false, 'via' => 'email', 'body' => "[image: برگ نخست.png]\n\nبله."]);
        $this->fakeResend('');
        Http::fake(['api.resend.com/emails/receiving?*' => Http::response(['data' => [
            ['id' => 'em_other', 'from' => 'someone@example.com', 'created_at' => now()->toIso8601String()],
            ['id' => 'em_1', 'from' => "کاربر <{$submitter->email}>", 'created_at' => now()->subSeconds(2)->toIso8601String()],
        ]])]);

        $this->artisan('fahares:fetch-reply-attachments', ['message' => $reply->id, '--dry-run' => true])->expectsOutputToContain('Email em_1: 1 picture(s)')->assertSuccessful();
        $this->assertSame(0, $reply->attachments()->count());

        $this->artisan('fahares:fetch-reply-attachments', ['message' => $reply->id])->expectsOutputToContain('Kept 1 picture(s)')->assertSuccessful();
        $this->assertSame(['بله.', 1], [$reply->fresh()->body, $reply->attachments()->count()]);

        $this->artisan('fahares:fetch-reply-attachments', ['message' => $reply->id])->expectsOutputToContain('already has 1 picture(s)')->assertSuccessful();
    }
}
