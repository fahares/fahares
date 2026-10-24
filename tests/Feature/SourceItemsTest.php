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
