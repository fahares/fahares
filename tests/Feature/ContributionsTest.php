<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\User;
use App\Models\Work;
use App\Suggestions\Contributions;
use App\Support\UserAvatar;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The public contributions page and user pictures (backlog items 63 and 66). */
class ContributionsTest extends TestCase
{
    protected string $tag;

    protected array $works = [];

    protected array $users = [];

    protected array $suggestions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tag = 'آزمونک' . random_int(10000, 99999);
    }

    protected function tearDown(): void
    {
        $workIds = array_map(fn ($w) => $w->id, $this->works);
        $msIds = Manuscript::whereIn('work_id', $workIds)->pluck('id');
        AuditLog::where('auditable_type', Manuscript::class)->whereIn('auditable_id', $msIds)->delete();
        FieldSuggestion::whereIn('id', $this->suggestions)->delete();
        FieldSuggestion::where('suggestable_type', Manuscript::class)->whereIn('suggestable_id', $msIds)->delete();
        Manuscript::whereIn('id', $msIds)->delete();
        Work::whereIn('id', $workIds)->delete();
        foreach ($this->users as $user) {
            if ($user->fresh()?->avatar_path) {
                Storage::disk('public')->delete($user->fresh()->avatar_path);
            }
            $user->delete();
        }

        parent::tearDown();
    }

    protected function user(string $role = 'researcher'): User
    {
        return $this->users[] = User::create([
            'name' => "کاربر {$this->tag} " . count($this->users),
            'email' => 'contrib-' . uniqid() . '@example.com',
            'password' => 'password123',
            'role' => $role,
        ]);
    }

    protected function work(string $title): Work
    {
        return $this->works[] = Work::create([
            'primary_title' => "{$title} {$this->tag}",
            'clean_title' => $title,
            'slug' => 'test-contrib-' . uniqid(),
            'volume_number' => 1,
            'page_start' => 1,
        ]);
    }

    protected function manuscript(?Work $work = null): Manuscript
    {
        return Manuscript::create([
            'work_id' => ($work ?? $this->work('اثر نسخه'))->id,
            'shelfmark' => '۱۲۳',
            'sequence_number' => 1,
            'volume_number' => 1,
            'page_start' => 1,
        ]);
    }

    protected function suggestion($record, array $attributes): FieldSuggestion
    {
        $s = FieldSuggestion::create($attributes + [
            'suggestable_type' => $record::class,
            'suggestable_id' => $record->id,
            'kind' => 'field',
            'field_name' => 'copy_place',
            'current_value' => 'اصفهان',
            'suggested_value' => 'هرات',
            'status' => 'approved',
            'standing' => 'adopted',
            'reviewed_at' => now(),
        ]);
        $this->suggestions[] = $s->id;

        return $s;
    }

    public function test_only_approved_public_corrections_are_listed_with_credited_names()
    {
        $researcher = $this->user();
        $shy = $this->user();
        $editor = $this->user('admin');
        $m = $this->manuscript();

        $credited = $this->suggestion($m, ['user_id' => $researcher->id, 'credit_name' => "پژوهشگر نامدار {$this->tag}", 'guest_name' => $researcher->name, 'guest_email' => $researcher->email]);
        $anonymous = $this->suggestion($m, ['user_id' => $shy->id, 'guest_name' => "نام پنهان {$this->tag}", 'guest_email' => 'hidden-' . $this->tag . '@example.com', 'suggested_value' => 'بلخ']);
        $guest = $this->suggestion($m, ['guest_name' => "مهمان {$this->tag}", 'guest_email' => 'guest-' . $this->tag . '@example.com', 'suggested_value' => 'مرو']);
        $staff = $this->suggestion($m, ['user_id' => $editor->id, 'guest_name' => $editor->name, 'suggested_value' => 'نیشابور']);
        $hidden = [
            $this->suggestion($m, ['user_id' => $researcher->id, 'status' => 'pending', 'standing' => null, 'suggested_value' => "در انتظار {$this->tag}"]),
            $this->suggestion($m, ['user_id' => $researcher->id, 'status' => 'rejected', 'standing' => null, 'suggested_value' => "ردشده {$this->tag}"]),
            $this->suggestion($m, ['user_id' => $researcher->id, 'kind' => 'note', 'field_name' => 'observation', 'status' => 'withdrawn', 'standing' => null, 'suggested_value' => "پس‌گرفته {$this->tag}"]),
            $this->suggestion($m, ['user_id' => $researcher->id, 'kind' => 'general', 'field_name' => null, 'standing' => null, 'suggested_value' => "کلی بی‌خلاصه {$this->tag}"]),
        ];

        $page = $this->get('/contributions?type=manuscript')->assertOk();
        foreach ([$credited, $anonymous, $guest, $staff] as $s) {
            $page->assertSee('data-contribution="' . $s->id . '"', false);
        }
        foreach ($hidden as $s) {
            $page->assertDontSee('data-contribution="' . $s->id . '"', false)->assertDontSee($s->suggested_value);
        }

        // Names only by permission; never an email, a guest's name or an unpermitted account name
        $page->assertSee("پژوهشگر نامدار {$this->tag}")
            ->assertSee(Contributions::ANONYMOUS_LABEL)
            ->assertSee(Contributions::STAFF_LABEL)
            ->assertDontSee("نام پنهان {$this->tag}")
            ->assertDontSee("مهمان {$this->tag}")
            ->assertDontSee($shy->name)
            ->assertDontSee($editor->name)
            ->assertDontSee($researcher->email)
            ->assertDontSee('@example.com')
            ->assertSee(route('contributors.show', $researcher), false)
            ->assertDontSee(route('contributors.show', $shy), false)
            // the change itself
            ->assertSee('اصفهان')->assertSee('هرات');
    }

    public function test_a_researcher_page_lists_only_the_corrections_credited_by_name()
    {
        $researcher = $this->user();
        $other = $this->user();
        $m = $this->manuscript();
        $named = $this->suggestion($m, ['user_id' => $researcher->id, 'credit_name' => "نام مجاز {$this->tag}"]);
        $unnamed = $this->suggestion($m, ['user_id' => $researcher->id, 'suggested_value' => 'بی‌نام']);

        $this->get(route('contributors.show', $researcher))->assertOk()
            ->assertSee("نام مجاز {$this->tag}")
            ->assertSee('data-contribution="' . $named->id . '"', false)
            // the same user's anonymous correction stays unlinked from them
            ->assertDontSee('data-contribution="' . $unnamed->id . '"', false);

        // Nobody without a credited correction has a page, so an id reveals nothing
        $this->suggestion($m, ['user_id' => $other->id]);
        $this->get(route('contributors.show', $other))->assertNotFound();
        $this->get('/contributors/999999999')->assertNotFound();
    }

    public function test_a_move_reads_the_earlier_work_from_the_resolution_or_the_audit_log()
    {
        $from = $this->work('اثر مبدأ');
        $to = $this->work('اثر مقصد');
        $m = $this->manuscript($from);
        $admin = $this->user('admin');

        // Approved through the desk: the earlier work is kept in the resolution now
        $s = $this->suggestion($m, ['kind' => 'relation', 'field_name' => 'move_to_work', 'status' => 'pending', 'standing' => null, 'payload' => ['target_id' => $to->id], 'suggested_value' => 'انتقال']);
        $this->actingAs($admin)->post("/admin/suggestions/{$s->id}/approve")->assertRedirect();
        $this->assertSame(['applied' => true, 'work_id' => $to->id, 'from_work_id' => $from->id], $s->fresh()->resolution);

        $summary = Contributions::summarize([$s->fresh()])->first();
        $this->assertSame("اثر مبدأ {$this->tag}", $summary['before']['label']);
        $this->assertSame("اثر مقصد {$this->tag}", $summary['after']['label']);

        // An older approval (production #12) kept only the target: the audit log tells the rest
        $s->update(['resolution' => ['applied' => true, 'work_id' => $to->id]]);
        $summary = Contributions::summarize([$s->fresh()])->first();
        $this->assertSame("اثر مبدأ {$this->tag}", $summary['before']['label']);
    }

    public function test_avatar_upload_replace_and_remove()
    {
        $user = $this->user();
        $this->actingAs($user)->get('/settings/profile')->assertOk()->assertSee('تصویر کاربر');

        $this->post('/settings/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg', 600, 400)])->assertRedirect();
        $user->refresh();
        $this->assertSame('upload', $user->avatar_source);
        $first = $user->avatar_path;
        $this->assertTrue(Storage::disk('public')->exists($first));
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($first));
        $this->assertSame([UserAvatar::SIZE, UserAvatar::SIZE], [$w, $h]);
        $this->assertStringEndsWith('.webp', $first);

        // A replacement removes the old file
        $this->post('/settings/profile/avatar', ['avatar' => UploadedFile::fake()->image('me2.png', 300, 300)])->assertRedirect();
        $this->assertFalse(Storage::disk('public')->exists($first));

        $this->post('/settings/profile/avatar', ['avatar' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('avatar');

        $path = $user->fresh()->avatar_path;
        $this->delete('/settings/profile/avatar')->assertRedirect();
        $this->assertFalse(Storage::disk('public')->exists($path));
        $this->assertNull($user->fresh()->avatar_path);
        $this->assertNull($user->fresh()->avatar_url);
    }

    public function test_google_picture_is_copied_locally_unless_the_user_chose_their_own()
    {
        $png = UploadedFile::fake()->image('g.png', 96, 96)->getContent();
        Http::fake(['lh3.googleusercontent.com/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
        $user = $this->user();

        UserAvatar::syncGoogle($user, 'https://lh3.googleusercontent.com/a/abc=s96-c');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '=s256-c'));
        $this->assertSame('google', $user->fresh()->avatar_source);
        $this->assertStringStartsWith(asset('storage/' . UserAvatar::DIRECTORY), $user->fresh()->avatar_url);

        // Removed by the user: a later sign-in does not bring it back
        UserAvatar::remove($user);
        UserAvatar::syncGoogle($user->fresh(), 'https://lh3.googleusercontent.com/a/abc=s96-c');
        $this->assertNull($user->fresh()->avatar_path);

        // A failed download leaves things as they were
        Http::fake(['*' => Http::response('', 500)]);
        $other = $this->user();
        UserAvatar::syncGoogle($other, 'https://lh5.example.test/a/x=s96-c');
        $this->assertNull($other->fresh()->avatar_path);
    }

    public function test_the_picture_appears_only_beside_a_credited_name()
    {
        $user = $this->user();
        UserAvatar::upload($user, UploadedFile::fake()->image('me.jpg', 100, 100));
        $url = $user->fresh()->avatar_url;
        $m = $this->manuscript();

        $anonymous = $this->suggestion($m, ['user_id' => $user->id]);
        $this->get('/contributions?type=manuscript')->assertDontSee($url, false);

        $anonymous->update(['credit_name' => "نام {$this->tag}"]);
        $this->get('/contributions?type=manuscript')->assertSee($url, false);
    }
}
