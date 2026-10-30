<?php

namespace Tests\Feature;

use App\Console\Commands\LinkSourceItemsCommand;
use App\Models\AuditLog;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The queue of works tied by machine to a bibliography's printed entries (step 13.72): verify, reject, or move. */
class SourceLinksReviewTest extends TestCase
{
    protected SourceVolume $volume;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $source = Source::firstOrCreate(['code' => 'monzavi'], ['kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 30, 'title' => 'فهرستواره کتاب‌های فارسی', 'short_name' => 'فهرستواره']);
        $this->volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 2, 'facsimile_code' => 'monzavi-test-' . uniqid()]);
        DB::table('source_pages')->insert([
            ['source_volume_id' => $this->volume->id, 'page' => 10, 'text' => 'متن صفحه‌ی ده: تحفة الملوک در اخلاق'],
            ['source_volume_id' => $this->volume->id, 'page' => 11, 'text' => 'متن صفحه‌ی یازده: تحفه شاهی'],
        ]);
        $this->admin = User::firstOrCreate(['email' => 'links-admin@fahares.net'], ['name' => 'داور پیوندها', 'password' => 'password123', 'role' => 'admin']);
        $this->admin->forceFill(['role' => 'admin'])->save();
    }

    private function item(string $key, int $page, string $title, string $text): SourceItem
    {
        return SourceItem::create(['source_id' => $this->volume->source_id, 'source_volume_id' => $this->volume->id, 'volume_number' => 2,
            'item_key' => $key, 'position' => (int) $key, 'entry_number' => (int) $key, 'kind' => 'entry', 'page_start' => $page, 'page_end' => $page,
            'title' => $title, 'title_normalized' => $title, 'text' => $text, 'text_status' => 'auto']);
    }

    private function tie(SourceItem $item, string $method, int $confidence, string $title, string $role = SourceEntry::ROLE_ENTRY): SourceEntry
    {
        $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'link-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $citation = SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $work->id, 'source_id' => $this->volume->source_id,
            'volume_number' => 2, 'page_start' => $item->page_start, 'role' => SourceEntry::ROLE_CITATION, 'origin' => SourceEntry::ORIGIN_BIBLIOGRAPHY,
            'status' => SourceEntry::STATUS_VERIFIED, 'citation_text' => 'فهرستواره 2/' . $item->page_start]);

        return SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $work->id, 'source_id' => $this->volume->source_id,
            'source_volume_id' => $this->volume->id, 'source_item_id' => $item->id, 'volume_number' => 2, 'page_start' => $item->page_start,
            'entry_number' => $item->entry_number, 'role' => $role, 'origin' => SourceEntry::ORIGIN_MATCH, 'match_method' => $method,
            'confidence' => $confidence, 'citation_text' => $citation->citation_text, 'entry_text' => $role === SourceEntry::ROLE_ENTRY ? $item->text : null,
            'text_status' => $role === SourceEntry::ROLE_ENTRY ? SourceEntry::TEXT_AUTO : SourceEntry::TEXT_NONE,
            'legacy_type' => LinkSourceItemsCommand::LEGACY_TYPE, 'legacy_id' => $citation->id, 'legacy_index' => 0]);
    }

    public function test_the_queue_lists_the_uncertain_ties_first_and_filters_by_source_and_method()
    {
        $a = $this->item('1', 10, 'تحفة الملوک', 'در اخلاق');
        $b = $this->item('2', 11, 'تحفه شاهی', 'در طب');
        $sure = $this->tie($a, 'title_author', 90, 'اثر مطمئن');
        $vague = $this->tie($b, 'ambiguous', 30, 'اثر مبهم');
        $near = $this->tie($b, 'near_title_only', 55, 'اثر همسایه');
        $user = User::firstOrCreate(['email' => 'links-user@fahares.net'], ['name' => 'کاربر', 'password' => 'password123', 'role' => 'user']);

        $this->actingAs($user)->get(route('admin.source-links.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.source-links.index'))->assertOk()
            ->assertSeeInOrder(['اثر مبهم', 'اثر همسایه', 'اثر مطمئن'])->assertSee('تحفه شاهی')
            ->assertSee('مبهم (چند مدخل هم‌عنوان)')->assertSee('data-tab-count="unverified" data-n="3"', false);
        $this->actingAs($this->admin)->get(route('admin.source-links.index', ['source' => 'monzavi', 'method' => 'near']))->assertOk()
            ->assertSee('اثر همسایه')->assertDontSee('اثر مبهم')->assertDontSee('اثر مطمئن');

        // the rows' inline buttons answer for a work
        $this->actingAs($this->admin)->postJson(route('admin.catalog-references.verify', $sure))->assertOk()
            ->assertJson(['status' => SourceEntry::STATUS_VERIFIED, 'message' => 'پیوند «اثر مطمئن» تأیید شد.']);

        // the copies' queue keeps to copies
        $this->actingAs($this->admin)->get(route('admin.catalog-references.index'))->assertOk()->assertDontSee('اثر مبهم');
    }

    public function test_the_tie_page_shows_the_entry_and_page_texts_and_the_neighbouring_entries()
    {
        $a = $this->item('1', 10, 'تحفة الملوک', 'در اخلاق');
        $b = $this->item('2', 11, 'تحفه شاهی', 'در طب');
        $far = $this->item('3', 20, 'دور', 'دور');
        $tie = $this->tie($a, 'ambiguous', 30, 'تحفه');

        $this->actingAs($this->admin)->get(route('admin.source-links.show', $tie))->assertOk()
            ->assertSee('متن صفحه‌ی ده')->assertSee('متن صفحه‌ی یازده')->assertSee('تحفه شاهی')->assertSee('(پیوند کنونی)')
            ->assertSee('value="' . $b->id . '"', false)->assertDontSee('value="' . $far->id . '"', false);
    }

    public function test_a_reviewer_verifies_or_rejects_and_goes_on_to_the_next_tie()
    {
        $a = $this->item('1', 10, 'تحفة الملوک', 'در اخلاق');
        $first = $this->tie($a, 'ambiguous', 30, 'نخست');
        $second = $this->tie($a, 'author_death', 50, 'دوم');

        $this->actingAs($this->admin)->post(route('admin.source-links.review', [$first, 'source' => 'monzavi']), ['decision' => SourceEntry::STATUS_REJECTED])
            ->assertRedirect(route('admin.source-links.show', [$second, 'source' => 'monzavi']));
        $this->assertSame([SourceEntry::STATUS_REJECTED, $this->admin->id], [$first->fresh()->status, $first->fresh()->reviewed_by]);
        $this->actingAs($this->admin)->post(route('admin.source-links.review', $second), ['decision' => SourceEntry::STATUS_VERIFIED])
            ->assertRedirect(route('admin.source-links.index', ['source' => 'monzavi']));
        $this->assertSame(SourceEntry::STATUS_VERIFIED, $second->fresh()->status);
    }

    public function test_a_tie_moved_to_another_entry_takes_its_place_and_text_and_is_logged()
    {
        $a = $this->item('1', 10, 'تحفة الملوک', 'در اخلاق');
        $b = $this->item('2', 11, 'تحفه شاهی', 'در طب');
        $tie = $this->tie($a, 'ambiguous', 30, 'تحفه شاهی');

        $this->actingAs($this->admin)->post(route('admin.source-links.move', $tie), ['item' => $b->id, 'role' => SourceEntry::ROLE_ENTRY])->assertRedirect();

        $tie->refresh();
        $this->assertSame([$b->id, 11, 2, SourceEntry::ROLE_ENTRY, 'در طب', SourceEntry::STATUS_VERIFIED, $this->admin->id],
            [$tie->source_item_id, $tie->page_start, $tie->entry_number, $tie->role, $tie->entry_text, $tie->status, $tie->reviewed_by]);
        $log = AuditLog::where('auditable_type', SourceEntry::class)->where('auditable_id', $tie->id)->where('action', SourceEntry::ACTION_MOVE_ITEM)->sole();
        $this->assertSame([$a->id, $b->id], [$log->old_values['source_item_id'], $log->new_values['source_item_id']]);

        // as a mention the entry's text is not the work's
        $this->actingAs($this->admin)->post(route('admin.source-links.move', $tie), ['item' => $a->id, 'role' => SourceEntry::ROLE_MENTION])->assertRedirect();
        $this->assertSame([$a->id, SourceEntry::ROLE_MENTION, null], [$tie->fresh()->source_item_id, $tie->fresh()->role, $tie->fresh()->entry_text]);
    }

    public function test_a_move_stays_in_the_volume_and_never_doubles_a_tie()
    {
        $a = $this->item('1', 10, 'تحفة الملوک', 'در اخلاق');
        $b = $this->item('2', 11, 'تحفه شاهی', 'در طب');
        $other = SourceVolume::create(['source_id' => $this->volume->source_id, 'volume_number' => 3, 'facsimile_code' => 'monzavi-other-' . uniqid()]);
        $elsewhere = SourceItem::create(['source_id' => $this->volume->source_id, 'source_volume_id' => $other->id, 'volume_number' => 3, 'item_key' => '9',
            'position' => 9, 'kind' => 'entry', 'page_start' => 5, 'page_end' => 5, 'title' => 'جای دیگر', 'title_normalized' => 'جای دیگر', 'text' => '…', 'text_status' => 'auto']);
        $tie = $this->tie($a, 'ambiguous', 30, 'تحفه');
        SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $tie->citable_id, 'source_id' => $this->volume->source_id,
            'source_volume_id' => $this->volume->id, 'source_item_id' => $b->id, 'origin' => SourceEntry::ORIGIN_MATCH, 'match_method' => 'title_author',
            'confidence' => 90, 'legacy_type' => LinkSourceItemsCommand::LEGACY_TYPE, 'legacy_id' => $tie->legacy_id, 'legacy_index' => 1]);

        $this->actingAs($this->admin)->post(route('admin.source-links.move', $tie), ['item' => $elsewhere->id, 'role' => 'entry'])->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('admin.source-links.move', $tie), ['item' => $b->id, 'role' => 'entry'])->assertSessionHas('error');
        $this->assertSame([$a->id, SourceEntry::STATUS_UNVERIFIED], [$tie->fresh()->source_item_id, $tie->fresh()->status]);
    }
}
