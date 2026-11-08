<?php

namespace Tests\Feature;

use App\Console\Commands\MergeCatalogueTwinsCommand;
use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\Work;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** One copy that Fankha describes twice, from two catalogues of its library, becomes one record (step 14.85) */
class CatalogueTwinsTest extends TestCase
{
    use DatabaseTransactions;

    private string $library;

    private function copy(Work $work, string $shelfmark, string $citation, array $extra = [], string $text = ''): Manuscript
    {
        return Manuscript::create(['work_id' => $work->id, 'volume_number' => 31, 'page_start' => 12, 'sequence_number' => 1, 'library' => $this->library,
            'city' => 'مشهد', 'shelfmark' => $shelfmark, 'raw_text' => "مشهد؛ آزمون؛ شماره نسخه: {$shelfmark} {$text} {$citation}",
            'metadata' => ['catalog_citation' => $citation]] + $extra);
    }

    private function work(): Work
    {
        $title = 'اثر آزمون دوقلو ' . uniqid();

        return Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'twin-' . uniqid(), 'volume_number' => 31, 'page_start' => 12]);
    }

    public function test_the_parts_of_a_twin_are_told_apart()
    {
        $this->assertSame([17.0, 21.5], MergeCatalogueTwinsCommand::size('17×21/5سم'));
        $this->assertSame([13.0, 21.0], MergeCatalogueTwinsCommand::size('خط: نسخ؛ اندازه: ۲۱×۱۳سم'));
        $this->assertNull(MergeCatalogueTwinsCommand::size('2×19'));
        $this->assertSame(['ابراهیم', 'باقر'], MergeCatalogueTwinsCommand::names('محمد ابراهیم بن محمد باقر'));
        $this->assertSame(['موسوی'], MergeCatalogueTwinsCommand::names('الموسوی'));
        $this->assertSame('رحلی', MergeCatalogueTwinsCommand::format('جلد: تیماج، قطع: رحلی کوچک [آستانه قم: -32]'));
    }

    public function test_a_copy_described_twice_is_merged_and_two_copies_are_left_apart_and_a_run_reverts()
    {
        $this->library = 'کتابخانه‌ی آزمون دوقلو ' . uniqid();
        // Razavi's list and its printed catalogue: one copy, a year a little off
        $work = $this->work();
        $printed = $this->copy($work, '12460', '[ف: 15-2]', ['folios' => 128, 'dimensions' => '13/5×21سم', 'lines' => 12, 'copy_date_raw' => '1322ق', 'copy_date_from' => 1322, 'copy_date_to' => 1322,
            'explicit_text' => 'و اهدنی بهم و لاتضلنی بهم و ارزقنی بهم'], 'خط: نسخ؛ بی‌کا؛ کاغذ: نخودی، جلد: تیماج خرمائی، 128گ، 12 سطر، اندازه: 13/5×21سم');
        $listed = $this->copy($work, '12460', '[رایانه]', ['dimensions' => '13×21سم', 'lines' => 12, 'copy_date_raw' => '1325ق', 'copy_date_from' => 1325, 'copy_date_to' => 1325,
            'explicit_text' => 'لاتضلنی بهم و ارزقنی بهم', 'paper' => 'شکری']);
        // Two copies under one number: other leaves and size, another century, another text, another format, a photograph, a reviewer's word
        $other = $this->work();
        $kept = $this->copy($other, '7211', '[ف: 20 - 30]', ['folios' => 8, 'dimensions' => '13×19سم', 'copy_date_raw' => 'قرن 11', 'copy_date_from' => 1001, 'copy_date_to' => 1100,
            'explicit_text' => 'و الحمد لله رب العالمین و صلی الله علی محمد و آله الطاهرین'], 'قطع: رحلی');
        $leaves = $this->copy($other, '7211', '[ف: 5 - 492]', ['folios' => 31, 'dimensions' => '16×26سم']);
        $century = $this->copy($other, '7211', '[الفبائی: 12]', ['copy_date_raw' => '1228ق', 'copy_date_from' => 1228, 'copy_date_to' => 1228]);
        $text = $this->copy($other, '7211', '[رایانه]', ['explicit_text' => 'تمت الرساله الشریفه الموسومه بالبرء الساعه فی شهر رجب']);
        $format = $this->copy($other, '7211', '[ف: 7-1]', [], 'قطع: خشتی');
        $photo = $this->copy($other, '7211', '[عکسی ف: 2-1]', ['is_facsimile' => true]);
        $said = $this->copy($other, '7211', '[ف: 9-3]');
        DB::table('manuscript_relations')->insert(['manuscript_id' => $said->id, 'related_manuscript_id' => $kept->id, 'type' => 'distinct_from', 'source' => 'reader', 'created_at' => now(), 'updated_at' => now()]);
        // A part of a volume («n/m») and an entry that is the copy above are not this command's
        $this->copy($other, '7211/2', '[ف: 1-1]');
        $above = $this->copy($other, '7211', '[ف: 3-3]', [], 'همان نسخه بالا');
        $report = tempnam(sys_get_temp_dir(), 'twins');
        $backup = storage_path('app/test-merge-catalogue-twins-' . uniqid() . '.json');

        $this->artisan('fahares:merge-catalogue-twins', ['--report' => $report, '--library' => $this->library])->expectsOutputToContain('Dry run: nothing merged.')->assertSuccessful();
        $this->assertNotNull(Manuscript::find($listed->id));
        $decisions = collect(array_map('str_getcsv', array_slice(file($report), 1)))->mapWithKeys(fn ($r) => [(int) $r[0] => $r[2]]);
        $this->assertSame(['merge', 'other-folios', 'other-date', 'other-text', 'other-format', 'facsimile', 'distinct'],
            [$decisions[$listed->id], $decisions[$leaves->id], $decisions[$century->id], $decisions[$text->id], $decisions[$format->id], $decisions[$photo->id], $decisions[$said->id]]);
        $this->assertArrayNotHasKey($above->id, $decisions->all());

        $this->artisan('fahares:merge-catalogue-twins', ['--apply' => true, '--backup' => $backup, '--library' => $this->library])->expectsOutputToContain('Merged')->assertSuccessful();
        // The printed catalogue's description stays; what only the list gives fills it; the list's year is a reading citing it
        $this->assertNull(Manuscript::find($listed->id));
        $merged = $printed->fresh();
        $this->assertSame(['[ف: 15-2]', 'شکری', 1322], [$merged->catalog_citation, $merged->paper, (int) $merged->copy_date_from]);
        $reading = FieldSuggestion::where('suggestable_type', Manuscript::class)->where('suggestable_id', $printed->id)->where('field_name', 'copy_date_raw')->firstOrFail();
        $this->assertSame(['alternative', '1325ق', 'فهرست رایانه'], [$reading->standing, $reading->suggested_value, $reading->credit_name]);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => Manuscript::class, 'auditable_id' => $printed->id, 'action' => 'merge']);
        foreach ([$kept, $leaves, $century, $text, $format, $photo, $said, $above] as $copy) {
            $this->assertNotNull(Manuscript::find($copy->id));
        }
        // Nothing is left to merge
        $this->artisan('fahares:merge-catalogue-twins', ['--apply' => true, '--library' => $this->library])->expectsOutputToContain('Nothing to merge.')->assertSuccessful();

        $this->artisan('fahares:merge-catalogue-twins', ['--revert' => $backup])->expectsOutputToContain('Reverted 1 merges.')->assertSuccessful();
        $this->assertSame('[رایانه]', Manuscript::find($listed->id)?->catalog_citation);
        $this->assertNull($printed->fresh()->paper);
        $this->assertSame(0, FieldSuggestion::where('suggestable_id', $printed->id)->where('field_name', 'copy_date_raw')->count());
        @unlink($backup);
        @unlink($report);
    }
}
