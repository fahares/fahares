<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\SearchLog;
use App\Models\Work;
use Tests\TestCase;

/** Search results as Excel and CSV files (backlog item 83, phase 4) */
class SearchExportTest extends TestCase
{
    public function test_the_results_are_exported_with_their_filter_and_order(): void
    {
        $library = Library::create(['name' => 'کتابخانه‌ی خروجی', 'city' => 'قم', 'slug' => 'export-lib-' . uniqid()]);
        $work = Work::create(['primary_title' => 'اثر خروجی', 'clean_title' => 'اثر خروجی', 'slug' => 'export-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        foreach (['E-900' => 900, 'E-500' => 500] as $shelfmark => $year) {
            Manuscript::create(['work_id' => $work->id, 'library_id' => $library->id, 'shelfmark' => $shelfmark, 'copy_date_hijri_year' => $year,
                'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1, 'raw_text' => 'نسخه']);
        }
        Manuscript::create(['work_id' => $work->id, 'shelfmark' => 'E-elsewhere', 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1, 'raw_text' => 'نسخه']);
        $params = ['type' => 'manuscripts', 'library_id' => $library->id, 'sort' => 'oldest'];

        $csv = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 Firefox/131.0'])->get(route('search.export', $params + ['format' => 'csv']));
        $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $text = $csv->streamedContent();
        $this->assertStringStartsWith("\u{FEFF}شناسه,", $text, 'a BOM, so Excel reads the Persian');
        $this->assertMatchesRegularExpression('/E-500.*\n.*E-900/u', $text);
        $this->assertStringNotContainsString('E-elsewhere', $text);
        $this->assertStringContainsString(url('/m/'), $text);
        $this->assertSame(0, SearchLog::count(), 'an export is not a search');

        $xlsx = $this->get(route('search.export', $params));
        $xlsx->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', $xlsx->streamedContent());
    }
}
