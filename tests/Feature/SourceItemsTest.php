<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A volume's page texts split into its printed entries (plan docs/plans/dharia-text.md), and kept across re-runs. */
class SourceItemsTest extends TestCase
{
    protected SourceVolume $volume;

    protected function setUp(): void
    {
        parent::setUp();
        $source = Source::create(['code' => 'dharia-test', 'kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 20, 'title' => 'الذریعة']);
        $this->volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 3, 'facsimile_code' => 'dharia-test']);
        $this->pages([
            402 => "( 1446 : التحفة ) في أصول الفقه للمولى مراد بن علي خان التفريشي .\n\n( 1447 : التحفة ) في الطب وكثير",
            403 => "من مجرباته .\n( تحفة المؤمنين ) يأتي .\n\n( 1448 : التحفة ) أرجوزة في المبدأ والمعاد .",
        ]);
    }

    private function pages(array $pages): void
    {
        foreach ($pages as $page => $text) {
            DB::table('source_pages')->updateOrInsert(['source_volume_id' => $this->volume->id, 'page' => $page], ['text' => $text]);
        }
    }

    public function test_segment_saves_the_entries_with_their_places_and_parents()
    {
        $this->artisan('sources:segment', ['volume' => 'dharia-test'])->assertSuccessful();

        $items = SourceItem::where('source_volume_id', $this->volume->id)->orderBy('position')->get();
        $this->assertSame(['1446', '1447', '1447/1', '1448'], $items->pluck('item_key')->all());
        $this->assertSame([402, 403], [$items[1]->page_start, $items[1]->page_end]);
        $this->assertSame($items[1]->id, $items[2]->parent_id);
        $this->assertSame([3, 'ablibrary', 'auto'], [$items[0]->volume_number, $items[0]->text_origin, $items[0]->text_status]);
    }

    public function test_link_items_ties_fankhas_citation_to_the_entry_and_keeps_a_reviewed_tie()
    {
        $this->artisan('sources:segment', ['volume' => 'dharia-test'])->assertSuccessful();
        $work = Work::create(['primary_title' => 'التحفة', 'clean_title' => 'التحفة', 'slug' => 'tuhfa-' . uniqid(), 'volume_number' => 1,
            'page_start' => 1, 'author_name' => 'تفرشی، مراد بن علی خان']);
        $citation = SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $work->id, 'source_id' => $this->volume->source_id,
            'volume_number' => 3, 'page_start' => 402, 'role' => SourceEntry::ROLE_CITATION, 'origin' => SourceEntry::ORIGIN_BIBLIOGRAPHY,
            'status' => SourceEntry::STATUS_VERIFIED, 'citation_text' => 'الذریعة 402/3']);

        $this->artisan('sources:link-items', ['volume' => 'dharia-test'])->assertSuccessful();

        $tie = SourceEntry::where('origin', SourceEntry::ORIGIN_MATCH)->sole();
        $item = SourceItem::where('item_key', '1446')->sole();
        $this->assertSame([$work->id, $item->id, SourceEntry::ROLE_ENTRY, SourceEntry::STATUS_UNVERIFIED, 'title_author'],
            [$tie->citable_id, $tie->source_item_id, $tie->role, $tie->status, $tie->match_method]);
        $this->assertSame([$item->text, SourceEntry::TEXT_AUTO, 1446], [$tie->entry_text, $tie->text_status, $tie->entry_number]);
        $this->assertSame(SourceEntry::ROLE_CITATION, $citation->fresh()->role);

        // a reviewer moved it; a second run leaves it alone
        $tie->update(['status' => SourceEntry::STATUS_REJECTED]);
        $this->artisan('sources:link-items', ['volume' => 'dharia-test'])->assertSuccessful();
        $this->assertSame(SourceEntry::STATUS_REJECTED, $tie->fresh()->status);
        $this->assertSame(1, SourceEntry::where('origin', SourceEntry::ORIGIN_MATCH)->count());
    }

    public function test_the_work_page_shows_the_entry_text_and_a_mention_as_one_line_and_the_entry_has_its_page()
    {
        $this->artisan('sources:segment', ['volume' => 'dharia-test'])->assertSuccessful();
        $item = SourceItem::where('item_key', '1447')->sole();
        $sub = SourceItem::where('item_key', '1447/1')->sole();
        $work = Work::create(['primary_title' => 'تحفه حکیم مؤمن', 'clean_title' => 'تحفه حکیم مؤمن', 'slug' => 'tuhfa-' . uniqid(),
            'volume_number' => 1, 'page_start' => 1]);
        $other = Work::create(['primary_title' => 'تحفة المؤمنين', 'clean_title' => 'تحفة المؤمنين', 'slug' => 'tm-' . uniqid(),
            'volume_number' => 1, 'page_start' => 1]);
        $tie = fn (Work $w, SourceItem $i, string $role) => SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $w->id,
            'source_id' => $this->volume->source_id, 'source_volume_id' => $this->volume->id, 'source_item_id' => $i->id, 'volume_number' => 3,
            'page_start' => $i->page_start, 'role' => $role, 'origin' => SourceEntry::ORIGIN_MATCH, 'match_method' => 'title_author',
            'entry_text' => $role === SourceEntry::ROLE_ENTRY ? $i->text : null, 'text_status' => $role === SourceEntry::ROLE_ENTRY ? 'auto' : 'none']);
        $tie($work, $item, SourceEntry::ROLE_ENTRY);
        $tie($other, $item, SourceEntry::ROLE_MENTION);

        $this->get(route('works.show', $work))->assertOk()
            ->assertSee('ش ۱۴۴۷', false)
            ->assertSee($item->url(), false)
            ->assertSee('صفحه‌ی مدخل', false)
            ->assertSee('في الطب', false);
        // a mention is one line under the bibliography, never the other entry's text
        $this->get(route('works.show', $other))->assertOk()
            ->assertSee('data-source-mention', false)
            ->assertSee('یاد شده در', false)
            ->assertDontSee('في الطب', false);

        $this->get('/sources/dharia-test/3/1447')->assertOk()
            ->assertSee('التحفة', false)
            ->assertSee('data-page-turn', false)
            ->assertSee('ص ۴۰۳', false)
            ->assertSee('تحفه حکیم مؤمن', false)
            ->assertSee('فقط در متن این مدخل یاد شده است', false)
            ->assertSee($sub->url(), false);
        $this->get($sub->url())->assertOk()->assertSee('ذیل', false);
        $this->get('/sources/dharia-test/3/9999')->assertNotFound();
    }

    public function test_a_second_run_keeps_ids_edited_text_and_linked_entries()
    {
        $this->artisan('sources:segment', ['volume' => 'dharia-test'])->assertSuccessful();
        $edited = SourceItem::where('item_key', '1446')->sole();
        $edited->update(['text' => 'متن ویراسته', 'text_status' => SourceItem::TEXT_EDITED]);
        $linked = SourceItem::where('item_key', '1448')->sole();
        $work = Work::create(['primary_title' => 'التحفة', 'clean_title' => 'التحفة', 'slug' => 'tuhfa-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $work->id, 'source_id' => $this->volume->source_id,
            'source_item_id' => $linked->id, 'origin' => 'match']);
        $dropped = SourceItem::where('item_key', '1447/1')->sole();

        // 1448 is gone from the pages and the sub-entry with it; 1446 changed
        $this->pages([
            402 => "( 1446 : التحفة ) في أصول الفقه للمولى مراد بن علي خان التفريشي المتوفى 1051 .\n\n( 1447 : التحفة ) في الطب وكثير",
            403 => 'من مجرباته .',
        ]);
        $this->artisan('sources:segment', ['volume' => 'dharia-test'])->assertSuccessful();

        $this->assertSame('متن ویراسته', $edited->fresh()->text);
        $this->assertTrue($linked->fresh()->flags['not_found']);
        $this->assertNull($dropped->fresh());
        $this->assertSame($edited->id, SourceItem::where('item_key', '1446')->sole()->id);
    }
}
