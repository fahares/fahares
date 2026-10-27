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
        $this->assertSame(SearchLog::KIND_EXPORT, SearchLog::sole()->kind, 'an export is logged as an export, not as a search');

        $xlsx = $this->get(route('search.export', $params));
        $xlsx->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', $xlsx->streamedContent());
    }
    public function test_a_larger_export_is_built_in_the_queue_and_its_link_is_sent(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Notification::fake();
        $user = \App\Models\User::create(['name' => 'پژوهشگر خروجی', 'email' => 'queued-export@fahares.net', 'password' => 'password123', 'role' => 'user']);
        $library = Library::create(['name' => 'کتابخانه‌ی صف', 'city' => 'قم', 'slug' => 'queue-lib-' . uniqid()]);
        $work = Work::create(['primary_title' => 'اثر صف', 'clean_title' => 'اثر صف', 'slug' => 'queue-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        foreach (['Q-2' => 1100, 'Q-1' => 900] as $shelfmark => $year) {
            Manuscript::create(['work_id' => $work->id, 'library_id' => $library->id, 'shelfmark' => $shelfmark, 'copy_date_hijri_year' => $year,
                'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1, 'raw_text' => 'نسخه']);
        }

        $this->post(route('search.export.queue', ['type' => 'manuscripts', 'library_id' => $library->id, 'sort' => 'oldest']), ['format' => 'csv'])
            ->assertRedirect(route('login'));
        $this->actingAs($user)->from(route('search', ['type' => 'manuscripts']))
            ->post(route('search.export.queue', ['type' => 'manuscripts', 'library_id' => $library->id, 'sort' => 'oldest']), ['format' => 'csv', 'total' => 2])
            ->assertRedirect()->assertSessionHas('success');

        $files = \Illuminate\Support\Facades\Storage::disk('public')->files(\App\Support\SearchExport::DIRECTORY);
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^exports\/[a-f0-9]{32}\.csv$/', $files[0]);
        $this->assertMatchesRegularExpression('/Q-1.*\n.*Q-2/u', \Illuminate\Support\Facades\Storage::disk('public')->get($files[0]), 'in the chosen order');

        $link = null;
        \Illuminate\Support\Facades\Notification::assertSentTo($user, \App\Notifications\Notice::class, function ($notice) use (&$link) {
            $link = $notice->link;

            return $notice->noticeType === 'search.export_ready';
        });
        $this->get($link)->assertOk()->assertDownload();
        $this->get(route('search.export.file', ['file' => basename($files[0])]))->assertForbidden();
        $this->assertSame('csv-queued', SearchLog::where('kind', SearchLog::KIND_EXPORT)->value('format'));
    }
}
