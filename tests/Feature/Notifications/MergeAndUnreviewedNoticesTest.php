<?php

namespace Tests\Feature\Notifications;

use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use App\Models\User;
use App\Models\Work;
use App\Notifications\Notice;
use App\Services\PersonMerger;
use App\Suggestions\RecordMerger;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Backlog item 82 (step 14.102): the merge of a record with a pending suggestion, and the weekly unreviewed-data count. */
class MergeAndUnreviewedNoticesTest extends TestCase
{
    private function user(string $role = 'user'): User
    {
        return User::create(['name' => "کاربر {$role}", 'email' => $role . '-' . uniqid() . '@example.com', 'password' => 'password123', 'role' => $role]);
    }

    private function work(string $title): Work
    {
        return Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'merge-notice-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function suggest(string $type, int $id, ?User $user, string $status = 'pending'): FieldSuggestion
    {
        return FieldSuggestion::create(['suggestable_type' => $type, 'suggestable_id' => $id, 'kind' => 'field', 'field_name' => 'notes',
            'suggested_value' => 'یادداشت', 'status' => $status, 'user_id' => $user?->id]);
    }

    private function mergeNotices(User $user)
    {
        return Notification::sent($user, Notice::class)->filter(fn (Notice $n) => $n->type() === 'suggestion.record_merged');
    }

    public function test_the_researcher_hears_that_the_record_of_his_pending_suggestion_was_merged(): void
    {
        Notification::fake();
        $admin = $this->user('admin');
        $researcher = $this->user();
        $other = $this->user();
        $source = $this->work('اثر تکراری');
        $kept = $this->work('اثر ماندگار');
        $this->suggest(Work::class, $source->id, $researcher);
        $this->suggest(Work::class, $source->id, $researcher);
        $this->suggest(Work::class, $source->id, $other, 'rejected');
        $this->suggest(Work::class, $source->id, $admin);

        $this->actingAs($admin);
        $result = app(RecordMerger::class)->mergeWork($source, $kept);
        \App\Models\AuditLog::create(['user_id' => $admin->id, 'auditable_type' => Work::class, 'auditable_id' => $kept->id, 'action' => 'merge',
            'old_values' => ['source_id' => $source->id] + $result, 'new_values' => ['target_id' => $kept->id]]);

        $sent = $this->mergeNotices($researcher);
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('«اثر تکراری» در «اثر ماندگار» ادغام شد', $sent->first()->lines[0]);
        $this->assertStringContainsString('۲ پیشنهاد', $sent->first()->lines[1]);
        $this->assertCount(0, $this->mergeNotices($other));
        $this->assertCount(0, $this->mergeNotices($admin));
    }

    public function test_a_person_merge_tells_the_researcher_too(): void
    {
        Notification::fake();
        $researcher = $this->user();
        $source = Person::create(['name' => 'شخص تکراری', 'normalized_name' => 'شخص تکراری', 'slug' => 'p-' . uniqid()]);
        $kept = Person::create(['name' => 'شخص ماندگار', 'normalized_name' => 'شخص ماندگار', 'slug' => 'p-' . uniqid()]);
        $this->suggest(Person::class, $source->id, $researcher);

        app(PersonMerger::class)->merge($source, $kept);

        $sent = $this->mergeNotices($researcher);
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('شخص «شخص تکراری» در «شخص ماندگار» ادغام شد', $sent->first()->lines[0]);
        $this->assertStringContainsString('به رکورد ماندگار پیوسته است', $sent->first()->lines[1]);
    }

    public function test_the_weekly_count_of_unreviewed_machine_matches(): void
    {
        Notification::fake();
        $editor = $this->user('editor');
        $plain = $this->user();
        $this->artisan('notifications:unreviewed-data')->expectsOutput('Nothing unreviewed.')->assertSuccessful();

        $source = Source::create(['code' => 'unrev-' . uniqid(), 'kind' => Source::KIND_BIOGRAPHICAL, 'rank' => 30, 'title' => 'منبع آزمون', 'short_name' => 'آزمون']);
        $volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 1, 'facsimile_code' => 'unrev']);
        $item = SourceItem::create(['source_id' => $source->id, 'source_volume_id' => $volume->id, 'volume_number' => 1, 'item_key' => '1#1',
            'position' => 1, 'kind' => 'entry', 'page_start' => 1, 'page_end' => 1, 'title' => 'مدخل', 'title_normalized' => 'مدخل', 'text' => 'متن', 'text_status' => 'auto']);
        $link = fn (Work $w, string $status) => SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $w->id, 'source_id' => $source->id,
            'source_item_id' => $item->id, 'volume_number' => 1, 'page_start' => 1, 'role' => SourceEntry::ROLE_ENTRY, 'origin' => SourceEntry::ORIGIN_MATCH,
            'match_method' => 'title_author', 'confidence' => 80, 'status' => $status]);
        $link($this->work('یک'), SourceEntry::STATUS_UNVERIFIED);
        $link($this->work('دو'), SourceEntry::STATUS_UNVERIFIED);
        $link($this->work('سه'), SourceEntry::STATUS_VERIFIED);

        $this->artisan('notifications:unreviewed-data')->assertSuccessful();
        Notification::assertSentTo($editor, Notice::class, fn (Notice $n) => $n->type() === 'review.unreviewed_data'
            && count($n->lines) === 2 && str_contains($n->lines[0], 'پیوند آثار به مدخل منابع: ۲ در انتظار بازبینی') && str_contains($n->lines[0], 'آزمون ۲'));
        Notification::assertNotSentTo($plain, Notice::class);
    }
}
