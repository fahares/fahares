<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use App\Models\Work;
use Tests\TestCase;

/** The tab «در منابع»: the printed entries of al-Dhari'a and other sources (backlog item 83, phase 7) */
class SearchSourcesTest extends TestCase
{
    private Source $source;

    private SourceVolume $volume;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        $this->source = Source::create(['code' => 'dharia-s-' . uniqid(), 'kind' => Source::KIND_BIBLIOGRAPHY, 'title' => 'الذریعة الی تصانیف الشیعة', 'short_name' => 'الذریعة']);
        $this->volume = SourceVolume::create(['source_id' => $this->source->id, 'volume_number' => 3]);
    }

    private function item(string $key, string $title, int $page, string $kind = SourceItem::KIND_ENTRY): SourceItem
    {
        return SourceItem::create(['source_id' => $this->source->id, 'source_volume_id' => $this->volume->id, 'volume_number' => 3, 'item_key' => $key,
            'position' => (int) $key, 'entry_number' => (int) $key, 'kind' => $kind, 'page_start' => $page, 'page_end' => $page, 'title' => $title, 'title_normalized' => $title,
            'text' => "( {$key} : {$title} ) للشيخ الطوسي في أصول الفقه."]);
    }

    public function test_entries_are_indexed_without_prefaces_and_browsed_with_their_works(): void
    {
        $entry = $this->item('1442', 'التحفة', 402);
        $preface = $this->item('0', 'مقدمة الناشر', 1, 'preface');
        $work = Work::create(['primary_title' => 'التحفه فی الاصول', 'clean_title' => 'التحفه فی الاصول', 'slug' => 's-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $work->id, 'source_id' => $this->source->id, 'source_item_id' => $entry->id,
            'role' => SourceEntry::ROLE_CITATION, 'origin' => SourceEntry::ORIGIN_BIBLIOGRAPHY, 'status' => SourceEntry::STATUS_VERIFIED]);

        $this->assertTrue($entry->shouldBeSearchable());
        $this->assertFalse($preface->shouldBeSearchable());
        $this->assertSame('تحفه التحفه التحفت', $entry->toSearchableArray()['title']);

        $this->get(route('search', ['type' => 'sources', 'source_id' => $this->source->id, 'volume' => 3]))->assertOk()
            ->assertSee('التحفة')->assertSee('ج ۳، ص ۴۰۲، ش ۱۴۴۲')->assertSee('در فهارس:')->assertSee('التحفه فی الاصول')
            ->assertSee(e($entry->url()), false)->assertDontSee('مقدمة الناشر')->assertSee('در الذریعة')->assertSee('جلد ۳');
    }

    public function test_a_source_page_is_answered_with_its_entries_and_exported(): void
    {
        $this->item('1441', 'التحفة', 401);
        $this->item('1442', 'التحفة الرضویة', 402);
        $this->item('1450', 'تحفة الأبرار', 405);

        $this->get(route('search', ['q' => 'ذریعه ج ۳ ص ۴۰۲']))->assertOk()
            ->assertSee('الذریعة، ج ۳، ص ۴۰۲')->assertSee('التحفة الرضویة')->assertDontSee('تحفة الأبرار');

        $csv = $this->get(route('search.export', ['type' => 'sources', 'source_id' => $this->source->id, 'format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\u{FEFF}شناسه,منبع,جلد,صفحه", $csv);
        $this->assertStringContainsString('تحفة الأبرار', $csv);
    }
}
