<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApplyCorpusCategoriesCommandTest extends TestCase
{
    private array $works = [];
    private array $manuscripts = [];
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'collection']);
        $this->file = tempnam(sys_get_temp_dir(), 'categories');
    }

    protected function tearDown(): void
    {
        AuditLog::where('auditable_type', Manuscript::class)->whereIn('auditable_id', $this->manuscripts)->delete();
        AuditLog::where('auditable_type', Work::class)->whereIn('auditable_id', $this->works)->delete();
        DB::table('manuscripts')->whereIn('id', $this->manuscripts)->delete();
        DB::table('works')->whereIn('id', $this->works)->delete();
        @unlink($this->file);
        parent::tearDown();
    }

    private function work(string $key, ?string $print = null, ?array $bib = null): int
    {
        return $this->works[] = DB::table('works')->insertGetId([
            'primary_title' => "اثر آزمایشی دسته {$key}", 'clean_title' => "اثر آزمایشی دسته {$key}",
            'slug' => 'test-categories-' . uniqid() . $key, 'volume_number' => 99, 'page_start' => 1,
            'print_info' => $print, 'bibliography' => $bib ? json_encode($bib, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    private function copy(int $work, string $shelfmark, int $facsimile = 0): int
    {
        return $this->manuscripts[] = DB::table('manuscripts')->insertGetId([
            'work_id' => $work, 'sequence_number' => 1, 'volume_number' => 99, 'page_start' => 1, 'page_end' => 1,
            'city' => 'تهران', 'library' => 'دانشگاه', 'shelfmark' => $shelfmark, 'is_facsimile' => $facsimile,
        ]);
    }

    private function plan(array $records): void
    {
        file_put_contents($this->file, json_encode(['records' => $records], JSON_UNESCAPED_UNICODE));
    }

    private function facsimile(int $id, string $key, string $shelfmark): array
    {
        return ['table' => 'manuscripts', 'id' => $id, 'work_key' => $key, 'shelfmark' => $shelfmark,
            'category' => 'facsimile', 'changes' => ['is_facsimile' => ['from' => 0, 'to' => 1]]];
    }

    private function printBib(int $id, string $key, string $from, string $to, array $cites): array
    {
        return ['table' => 'works', 'id' => $id, 'work_key' => $key, 'category' => 'print_bib',
            'changes' => ['print_info' => ['from' => $from, 'to' => $to]], 'require_in_bibliography' => $cites];
    }

    public function test_writes_only_unchanged_unprotected_records_whose_conditions_hold()
    {
        $w1 = $this->work('-v99-1', 'تهران، 1297ق [منزوی 3259/5]', ['منزوی 3259/5']);
        $w2 = $this->work('-v99-2', 'قم، 1300ق [الذریعة 1/2]', ['دنا 3/4']);
        $w3 = $this->work('-v99-3');
        $fresh = $this->copy($w3, '12/1-ف');
        $edited = $this->copy($w3, '12/2-ف');
        $moved = $this->copy($w3, '12/3-ف');
        $done = $this->copy($w3, '12/4-ف', 1);
        $guarded = $this->copy($w3, '12/5-ف');
        AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $guarded,
            'action' => 'verify', 'old_values' => ['is_facsimile' => 1], 'new_values' => ['is_facsimile' => 0]]);
        $this->plan([
            $this->facsimile($fresh, '-v99-3', '12/1-ف'),
            ['changes' => ['is_facsimile' => ['from' => 1, 'to' => 1]]] + $this->facsimile($edited, '-v99-3', '12/2-ف'),
            $this->facsimile($moved, '-v99-8', '12/3-ف'),
            $this->facsimile($done, '-v99-3', '12/4-ف'),
            $this->facsimile($guarded, '-v99-3', '12/5-ف'),
            $this->printBib($w1, '-v99-1', 'تهران، 1297ق [منزوی 3259/5]', 'تهران، 1297ق', ['منزوی 3259/5']),
            $this->printBib($w2, '-v99-2', 'قم، 1300ق [الذریعة 1/2]', 'قم، 1300ق', ['الذریعة 1/2']),
        ]);

        $this->artisan('fahares:apply-corpus-categories', ['--file' => $this->file])
            ->expectsOutputToContain('Would write 2 records.')
            ->assertSuccessful();
        $this->assertSame(0, (int) DB::table('manuscripts')->where('id', $fresh)->value('is_facsimile'));

        $this->artisan('fahares:apply-corpus-categories', ['--file' => $this->file, '--apply' => true])->assertSuccessful();
        $this->assertSame(1, (int) DB::table('manuscripts')->where('id', $fresh)->value('is_facsimile'));
        $this->assertSame(0, (int) DB::table('manuscripts')->where('id', $moved)->value('is_facsimile'));
        $this->assertSame(0, (int) DB::table('manuscripts')->where('id', $guarded)->value('is_facsimile'));
        $this->assertSame('تهران، 1297ق', DB::table('works')->where('id', $w1)->value('print_info'));
        // its citation is not in the bibliography, so «چاپ» keeps the bracket
        $this->assertSame('قم، 1300ق [الذریعة 1/2]', DB::table('works')->where('id', $w2)->value('print_info'));
        $log = AuditLog::where('auditable_id', $w1)->where('auditable_type', Work::class)->sole();
        $this->assertSame('corpus_update', $log->action);
        $this->assertSame('تهران، 1297ق [منزوی 3259/5]', $log->old_values['print_info']);

        // a second run has nothing left to write
        $this->artisan('fahares:apply-corpus-categories', ['--file' => $this->file])
            ->expectsOutputToContain('Would write 0 records.')
            ->assertSuccessful();
    }

    public function test_commissioners_become_people_rows_with_the_catalogues_phrase()
    {
        $w = $this->work('-v99-7');
        $trim = $this->copy($w, '20/1');
        $only = $this->copy($w, '20/2');
        $note = $this->copy($w, '20/3');
        $guarded = $this->copy($w, '20/4');
        DB::table('manuscripts')->where('id', $trim)->update(['copy_date_raw' => '2 شعبان 1335، حسب الامر حاج آقا منیر',
            'raw_text' => "تهران؛ دانشگاه؛ شماره نسخه: 20/1\nخط: نستعلیق، تا: 2 شعبان 1335، حسب الامر حاج آقا منیر"]);
        DB::table('manuscripts')->where('id', $only)->update(['raw_text' => 'خط: نسخ، جا: اصفهان به جهت میرزا ابوتراب']);
        DB::table('manuscripts')->where('id', $note)->update(['copy_date_raw' => 'قرن 12، در حیات مؤلف']);
        DB::table('manuscripts')->where('id', $guarded)->update(['raw_text' => 'برای میرزا حسن']);
        AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $guarded,
            'action' => 'verify', 'old_values' => [], 'new_values' => ['added_person_role' => 1]]);
        $commissioner = fn (int $id, string $shelfmark, string $name, array $changes = []) => ['table' => 'manuscripts', 'id' => $id,
            'work_key' => '-v99-7', 'shelfmark' => $shelfmark, 'category' => 'commissioner', 'changes' => $changes, 'commissioner' => $name];
        $this->plan([
            $commissioner($trim, '20/1', 'حاج آقا منیر', ['copy_date_raw' => ['from' => '2 شعبان 1335، حسب الامر حاج آقا منیر', 'to' => '2 شعبان 1335']]),
            $commissioner($only, '20/2', 'میرزا ابوتراب'),
            $commissioner($guarded, '20/4', 'میرزا حسن'),
            ['table' => 'manuscripts', 'id' => $note, 'work_key' => '-v99-7', 'shelfmark' => '20/3', 'category' => 'copy_date_note',
                'changes' => ['copy_date_raw' => ['from' => 'قرن 12، در حیات مؤلف', 'to' => 'قرن 12'], 'copy_date_note' => ['from' => null, 'to' => 'در حیات مؤلف']]],
        ]);

        $this->artisan('fahares:apply-corpus-categories', ['--file' => $this->file])->assertSuccessful();
        $this->assertSame(0, DB::table('manuscript_people')->count());

        $this->artisan('fahares:apply-corpus-categories', ['--file' => $this->file, '--apply' => true])->assertSuccessful();
        $people = fn (int $id) => DB::table('manuscript_people')->where('manuscript_id', $id)->get(['role', 'name', 'label_raw'])->map(fn ($r) => (array) $r)->all();
        $this->assertSame([['role' => 'patron', 'name' => 'حاج آقا منیر', 'label_raw' => 'حسب الامر']], $people($trim));
        $this->assertSame([['role' => 'patron', 'name' => 'میرزا ابوتراب', 'label_raw' => 'به جهت']], $people($only));
        $this->assertSame([], $people($guarded));
        $copy = Manuscript::find($trim);
        $this->assertSame(['2 شعبان 1335', 1335, 1335, 1335, 'حاج آقا منیر'], [$copy->copy_date_raw, $copy->copy_date_hijri_year, $copy->copy_date_from, $copy->copy_date_to, $copy->commissioned_by]);
        $this->assertSame(['قرن 12', 'در حیات مؤلف', 1101, 1200], [Manuscript::find($note)->copy_date_raw, Manuscript::find($note)->copy_date_note, Manuscript::find($note)->copy_date_from, Manuscript::find($note)->copy_date_to]);

        // The copy's page shows the phrase and the note, and the commissioner once
        $this->get(route('manuscripts.show', $copy))->assertOk()->assertSee('حسب الامر')
            ->assertDontSee('data-suggest-field="commissioned_by"', false);
        $this->get(route('manuscripts.show', Manuscript::find($note)))->assertOk()->assertSee('در حیات مؤلف');

        // A second run writes nothing
        $this->artisan('fahares:apply-corpus-categories', ['--file' => $this->file, '--apply' => true])->assertSuccessful();
        $this->assertSame(2, DB::table('manuscript_people')->count());
    }

    public function test_copy_date_ranges_are_filled_and_kept_in_step()
    {
        $w = $this->work('-v99-8');
        $a = $this->copy($w, '30/1');
        $b = $this->copy($w, '30/2');
        DB::table('manuscripts')->where('id', $a)->update(['copy_date_raw' => 'محتملاً اواخر قرن 12']);
        DB::table('manuscripts')->where('id', $b)->update(['copy_date_raw' => '1082 و 1083ق']);

        $this->artisan('fahares:fill-copy-date-ranges')->assertSuccessful();
        $this->assertNull(DB::table('manuscripts')->where('id', $a)->value('copy_date_from'));
        $this->artisan('fahares:fill-copy-date-ranges', ['--apply' => true])->assertSuccessful();
        $row = fn (int $id) => (array) DB::table('manuscripts')->where('id', $id)->first(['copy_date_from', 'copy_date_to', 'copy_date_certainty', 'copy_date_hedge_raw']);
        $this->assertSame(['copy_date_from' => 1167, 'copy_date_to' => 1200, 'copy_date_certainty' => 'probable', 'copy_date_hedge_raw' => 'محتملاً'], $row($a));
        $this->assertSame(['copy_date_from' => 1082, 'copy_date_to' => 1083, 'copy_date_certainty' => null, 'copy_date_hedge_raw' => null], $row($b));

        // Saving a copy with another date keeps the range in step
        Manuscript::find($b)->update(['copy_date_raw' => 'قرن 13']);
        $this->assertSame(['copy_date_from' => 1201, 'copy_date_to' => 1300, 'copy_date_certainty' => null, 'copy_date_hedge_raw' => null], $row($b));
    }

    public function test_extents_move_pages_out_of_folios_and_keep_what_is_uncertain()
    {
        $w = $this->work('-v99-9');
        $ids = [];
        foreach ([
            'pages' => [28, "خط: نسخ؛ 28ص (1-28)، 20 سطر"],
            'wrong' => [5, "محشی، 5 برگ ابتدایی حاشیه دارد؛ کاغذ: فرنگی، 29گ، مختلف السطر"],
            'missing' => [null, "تا: 1250؛ 3گ (112ر-115ر)"],
            'guarded' => [7, "14ص"],
            'two' => [63, "63گ، 21گ"],
            'none' => [9, "خط: نسخ، 12 سطر"],
        ] as $key => [$folios, $raw]) {
            $ids[$key] = $this->copy($w, "40/{$key}");
            DB::table('manuscripts')->where('id', $ids[$key])->update(['folios' => $folios, 'raw_text' => $raw]);
        }
        AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $ids['guarded'],
            'action' => 'verify', 'old_values' => ['folios' => 14], 'new_values' => ['folios' => 7]]);

        $this->artisan('fahares:fill-extents')->assertSuccessful();
        $this->assertSame(28, (int) DB::table('manuscripts')->where('id', $ids['pages'])->value('folios'));

        $this->artisan('fahares:fill-extents', ['--apply' => true])->assertSuccessful();
        $row = fn (string $k) => array_values((array) DB::table('manuscripts')->where('id', $ids[$k])->first(['folios', 'pages', 'range_from', 'range_to']));
        $this->assertSame([null, 28, '1', '28'], $row('pages'));
        $this->assertSame([29, null, null, null], $row('wrong'));
        $this->assertSame([3, null, '112ر', '115ر'], $row('missing'));
        // A reviewed correction of folios stays; the pages and wording are still filled
        $this->assertSame([7, 14, null, null], $row('guarded'));
        $this->assertSame([63, null, null, null], $row('two'));
        $this->assertSame([9, null, null, null], $row('none'));
        $this->assertSame(['folios' => 28], AuditLog::where('auditable_id', $ids['pages'])->where('action', 'corpus_update')->value('old_values'));

        $this->get(route('manuscripts.show', Manuscript::find($ids['pages'])))->assertOk()->assertSee('شمار صفحه')->assertSee('۲۸ صفحه (۱–۲۸)');
        $this->get(route('manuscripts.show', Manuscript::find($ids['missing'])))->assertOk()->assertSee('۳ برگ (۱۱۲ر–۱۱۵ر)');

        // A second run writes nothing
        $logs = AuditLog::where('action', 'corpus_update')->count();
        $this->artisan('fahares:fill-extents', ['--apply' => true])->expectsOutputToContain('Written.')->assertSuccessful();
        $this->assertSame($logs, AuditLog::where('action', 'corpus_update')->count());
    }

    public function test_dates_of_composition_get_ranges_too()
    {
        $w = $this->work('-v99-10');
        DB::table('works')->where('id', $w)->update(['composition_date_raw' => 'ظاهراً 1310 تا 1315ق']);

        $this->artisan('fahares:fill-copy-date-ranges', ['--works' => true, '--apply' => true])->assertSuccessful();
        $row = fn () => (array) DB::table('works')->where('id', $w)->first(['composition_date_from', 'composition_date_to', 'composition_date_certainty', 'composition_date_hedge_raw']);
        $this->assertSame(['composition_date_from' => 1310, 'composition_date_to' => 1315, 'composition_date_certainty' => 'probable', 'composition_date_hedge_raw' => 'ظاهراً'], $row());

        // A solar date has no Hijri range; saving keeps the columns in step
        Work::find($w)->update(['composition_date_raw' => '1350ش']);
        $this->assertSame(['composition_date_from' => null, 'composition_date_to' => null, 'composition_date_certainty' => null, 'composition_date_hedge_raw' => null], $row());
        Work::find($w)->update(['composition_date_raw' => 'نیمه دوم قرن 11']);
        $this->assertSame([1051, 1100], array_values(array_slice($row(), 0, 2)));
    }
}

