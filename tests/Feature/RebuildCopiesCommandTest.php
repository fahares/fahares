<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Manuscript;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RebuildCopiesCommandTest extends TestCase
{
    private array $works = [];
    private array $manuscripts = [];
    private ?int $library = null;
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'collection']);
        $this->file = tempnam(sys_get_temp_dir(), 'rebuild');
    }

    protected function tearDown(): void
    {
        AuditLog::where('auditable_type', Manuscript::class)->whereIn('auditable_id', $this->manuscripts)->delete();
        DB::table('source_entries')->where('citable_type', Manuscript::class)->whereIn('citable_id', $this->manuscripts)->delete();
        DB::table('manuscript_people')->where('role', 'scribe')->whereIn('manuscript_id', $this->manuscripts)->delete();
        DB::table('manuscripts')->whereIn('id', $this->manuscripts)->delete();
        DB::table('works')->whereIn('id', $this->works)->delete();
        DB::table('libraries')->where('id', $this->library)->delete();
        @unlink($this->file);
        parent::tearDown();
    }

    private function copy(?string $shelfmark, string $raw, ?string $date, array $metadata): int
    {
        $work = $this->works[] = \Tests\Support\InsertRecords::work([
            'primary_title' => 'اثر آزمایشی بازسازی', 'clean_title' => 'اثر آزمایشی بازسازی',
            'slug' => 'test-rebuild-' . uniqid(), 'volume_number' => 99, 'page_start' => 1,
        ]);

        $id = $this->manuscripts[] = \Tests\Support\InsertRecords::copy([
            'work_id' => $work, 'sequence_number' => 2, 'volume_number' => 99, 'page_start' => 1, 'page_end' => 1,
            'city' => 'تهران', 'library' => 'دانشگاه', 'shelfmark' => $shelfmark, 'raw_text' => $raw,
            'copy_date_raw' => $date, 'is_bita' => 0, 'scribe_name' => 'کاتب دیگر', 'script_names' => 'نستعلیق',
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ]);
        // the command reads the text, the row and the source from the copy's entry (step 14.399)
        \App\Sources\SourceEntrySync::syncIds(Manuscript::class, [$id]);

        return $id;
    }

    private function record(int $id, ?string $shelfmark, array $changes): array
    {
        return ['table' => 'manuscripts', 'id' => $id, 'shelfmark' => $shelfmark, 'category' => 'rebuild', 'v2' => 'v99-1#1', 'changes' => $changes];
    }

    public function test_rebuilds_a_copy_still_holding_the_neighbours_content_and_leaves_an_edited_one()
    {
        $oldMeta = ['incipits' => [], 'explicits' => [], 'editorial_notes' => [], 'ownership_and_seals' => [], 'catalog_citation' => '[عکسی - ف: 3-665]', 'residual_notes' => null];
        $newMeta = ['incipits' => [], 'explicits' => [], 'editorial_notes' => [], 'ownership_and_seals' => [], 'catalog_citation' => '[فیلم‌ها - ف: 3-193]', 'residual_notes' => null];
        $wrong = $this->copy('6030/8-ف', "قم؛ مرعشی؛ شماره نسخه: 1296/21\nخط: نستعلیق؛ بی‌کا، بی‌تا", '1100ق', $oldMeta);
        $edited = $this->copy('6031/8-ف', 'متن ویرایش‌شده', '1100ق', $oldMeta);
        DB::table('manuscript_people')->insert(['role' => 'scribe', 'manuscript_id' => $wrong, 'name' => 'کاتب دیگر', 'position' => 0]);
        $changes = fn ($raw) => [
            'raw_text' => ['from' => $raw, 'to' => "تهران؛ دانشگاه؛ شماره نسخه: 6030/8-ف\nنسخه اصل: همان نسخه بالا [فیلم‌ها - ف: 3-193]"],
            'copy_date_raw' => ['from' => '1100ق', 'to' => null],
            'scribe_name' => ['from' => 'کاتب دیگر', 'to' => null],
            'metadata' => ['from' => $oldMeta, 'to' => $newMeta],
        ];
        file_put_contents($this->file, json_encode(['records' => [
            $this->record($wrong, '6030/8-ف', $changes("قم؛ مرعشی؛ شماره نسخه: 1296/21\nخط: نستعلیق؛ بی‌کا، بی‌تا")),
            $this->record($edited, '6031/8-ف', $changes('متن پیش از ویرایش')),
        ]], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:rebuild-copies', ['--file' => $this->file])
            ->expectsOutputToContain('Would rebuild 1 copies.')
            ->assertSuccessful();
        $this->artisan('fahares:rebuild-copies', ['--file' => $this->file, '--apply' => true])->assertSuccessful();

        $row = \App\Support\EntryColumns::query('manuscripts', ['*', 'raw_text'])->where('manuscripts.id', $wrong)->first();
        $this->assertStringStartsWith('تهران؛ دانشگاه؛ شماره نسخه: 6030/8-ف', $row->raw_text);
        $this->assertNull($row->copy_date_raw);
        $this->assertNull($row->scribe_name);
        // the citation is the copy's citation entry's, not its metadata's (step 14.413)
        $this->assertSame('[فیلم‌ها - ف: 3-193]', Manuscript::find($wrong)->catalog_citation);
        $this->assertArrayNotHasKey('catalog_citation', json_decode($row->metadata, true));
        $this->assertFalse(DB::table('manuscript_people')->where('role', 'scribe')->where('manuscript_id', $wrong)->exists());
        // the copy's own text has no «خط:» line, so the other copy's script goes too
        $this->assertNull($row->script_names);
        $this->assertSame('corpus_update', AuditLog::where('auditable_id', $wrong)->where('auditable_type', Manuscript::class)->sole()->action);
        $this->assertSame('متن ویرایش‌شده', $this->fankhaOf(Manuscript::find($edited), 'text')[0]);

        $this->artisan('fahares:rebuild-copies', ['--file' => $this->file])
            ->expectsOutputToContain('Would rebuild 0 copies.')
            ->assertSuccessful();
    }

    public function test_a_same_as_above_copy_keeps_no_extent_of_the_other_copy()
    {
        // step 14.110: «همان نسخه بالا» has no extent, so the neighbour's «13گ (84-96)» goes with its folios
        $meta = ['incipits' => [], 'explicits' => [], 'editorial_notes' => [], 'ownership_and_seals' => [], 'catalog_citation' => '[عکسی ف: 2-231]', 'residual_notes' => null];
        $raw = "4. تهران؛ دائرة المعارف؛ شماره نسخه: 267/7-عکسی\nخط: نستعلیق تحریری، کا: محمد امین، تا: 1280ق؛ 13گ (84-96) [عکسی ف: 2-231]";
        $id = $this->copy('735', $raw, '1280ق', $meta);
        DB::table('manuscripts')->where('id', $id)->update(['folios' => 13, 'extent_raw' => '13گ (84-96)', 'range_from' => '84', 'range_to' => '96', 'range_from_number' => 84, 'range_to_number' => 96]);
        file_put_contents($this->file, json_encode(['records' => [$this->record($id, '735', [
            'raw_text' => ['from' => $raw, 'to' => "تهران؛ مفتاح؛ شماره نسخه: 735\nهمان نسخه بالا [نشریه: 7-214]"],
            'folios' => ['from' => 13, 'to' => null],
            'metadata' => ['from' => $meta, 'to' => ['catalog_citation' => '[نشریه: 7-214]'] + $meta],
        ])]], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:rebuild-copies', ['--file' => $this->file, '--apply' => true])->assertSuccessful();

        $row = DB::table('manuscripts')->where('id', $id)->first();
        $this->assertSame([null, null, null, null, null, null],
            [$row->folios, $row->extent_raw, $row->range_from, $row->range_to, $row->range_from_number, $row->range_to_number]);
        $old = AuditLog::where('auditable_id', $id)->where('auditable_type', Manuscript::class)->sole()->old_values;
        $this->assertSame('13گ (84-96)', $old['extent_raw']);
    }

    public function test_a_misread_shelfmark_is_corrected_with_its_sort_key()
    {
        $meta = ['incipits' => [], 'explicits' => [], 'editorial_notes' => [], 'ownership_and_seals' => [], 'catalog_citation' => '[ف: 44 - 112]', 'residual_notes' => null];
        $id = $this->copy('15872/8', "تهران؛ مجلس؛ شماره نسخه: 15872/8\nبی‌کا", null, $meta);
        file_put_contents($this->file, json_encode(['records' => [$this->record($id, '15872/8', [
            'shelfmark' => ['from' => '15872/8', 'to' => '15877/8'],
            'raw_text' => ['from' => "تهران؛ مجلس؛ شماره نسخه: 15872/8\nبی‌کا", 'to' => "تهران؛ مجلس؛ شماره نسخه: 15877/8\nبی‌کا"],
        ])]], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:rebuild-copies', ['--file' => $this->file, '--apply' => true])->assertSuccessful();

        $row = DB::table('manuscripts')->where('id', $id)->first();
        $this->assertSame(['15877/8', '15877/8'], [$row->shelfmark, $row->shelfmark_key]);
        // the record now names the new shelfmark, so a second run finds it changed and leaves it
        $this->artisan('fahares:rebuild-copies', ['--file' => $this->file])
            ->expectsOutputToContain('Would rebuild 0 copies.')
            ->assertSuccessful();
    }

    public function test_a_copy_that_lost_its_place_gets_its_city_library_and_shelfmark()
    {
        $meta = ['incipits' => [], 'explicits' => [], 'editorial_notes' => [], 'ownership_and_seals' => [], 'catalog_citation' => '[نشریه: 5 - 640]', 'residual_notes' => null];
        $id = $this->copy(null, "شماره نسخه: 90/4\nبی‌کا", null, $meta);
        DB::table('manuscripts')->where('id', $id)->update(['city' => null, 'library' => null, 'library_id' => null]);
        $library = $this->library = DB::table('libraries')->insertGetId([
            'city' => 'تهران', 'name' => 'کتابخانۀ آزمایشی ' . uniqid(), 'slug' => 'test-rebuild-library-' . uniqid(), 'manuscripts_count' => 0,
        ]);
        file_put_contents($this->file, json_encode(['records' => [$this->record($id, null, [
            'city' => ['from' => null, 'to' => 'تهران'],
            'library' => ['from' => null, 'to' => 'دانش‌سرای عالی'],
            'library_id' => ['from' => null, 'to' => $library],
            'shelfmark' => ['from' => null, 'to' => '90/4'],
        ])]], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:rebuild-copies', ['--file' => $this->file, '--apply' => true])->assertSuccessful();

        $row = DB::table('manuscripts')->where('id', $id)->first();
        $this->assertSame(['تهران', 'دانش‌سرای عالی', $library, '90/4', '90/4'],
            [$row->city, $row->library, $row->library_id, $row->shelfmark, $row->shelfmark_key]);
        $this->assertSame(1, (int) DB::table('libraries')->where('id', $library)->value('manuscripts_count'));
    }

    public function test_a_cut_date_takes_its_note_and_its_year_from_the_new_text()
    {
        $meta = ['incipits' => [], 'explicits' => [], 'editorial_notes' => [], 'ownership_and_seals' => [], 'catalog_citation' => null, 'residual_notes' => null];
        $id = $this->copy('77/1', "تهران؛ دانشگاه؛ شماره نسخه: 77/1\nتا: قرن 12، اهدایی: رهبری، خرداد 1373", 'قرن 12، اهدایی: رهبری، خرداد 1373', $meta);
        DB::table('manuscripts')->where('id', $id)->update(['copy_date_hijri_year' => 1373]);
        file_put_contents($this->file, json_encode(['records' => [$this->record($id, '77/1', [
            'copy_date_raw' => ['from' => 'قرن 12، اهدایی: رهبری، خرداد 1373', 'to' => 'قرن 12'],
            'copy_date_note' => ['from' => null, 'to' => 'به فرموده آقا بابا خان'],
        ])]], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:rebuild-copies', ['--file' => $this->file, '--apply' => true])->assertSuccessful();

        $row = DB::table('manuscripts')->where('id', $id)->first();
        $this->assertSame(['قرن 12', 'به فرموده آقا بابا خان'], [$row->copy_date_raw, $row->copy_date_note]);
        $this->assertSame(\App\Support\HijriYear::parse('قرن 12'), $row->copy_date_hijri_year === null ? null : (int) $row->copy_date_hijri_year);
        $this->assertNotSame(1373, (int) $row->copy_date_hijri_year);
    }

    public function test_a_scribe_whose_name_is_only_read_right_keeps_the_copy_under_the_new_name()
    {
        $meta = ['incipits' => [], 'explicits' => [], 'editorial_notes' => [], 'ownership_and_seals' => [], 'catalog_citation' => null, 'residual_notes' => null];
        $make = function (string $name) use ($meta) {
            $id = $this->copy('88/' . count($this->manuscripts), "تهران؛ دانشگاه؛ شماره نسخه: 88\nکا: {$name}", null, $meta);
            $person = \App\Models\Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'scribe-' . uniqid()]);
            DB::table('manuscripts')->where('id', $id)->update(['scribe_name' => $name, 'scribe_id' => $person->id]);

            return [$id, $person->id];
        };
        [$own, $renamed] = $make('کاتب آزمایشی یکم، تا: 1085ق');
        [$first, $shared] = $make('کاتب آزمایشی مشترک');
        // the shared person wrote another copy, which the file does not hold: that person is not renamed
        $other = $this->copy('88/x', 'تهران؛ دانشگاه؛ شماره نسخه: 88', null, $meta);
        DB::table('manuscripts')->where('id', $other)->update(['scribe_name' => 'کاتب آزمایشی مشترک', 'scribe_id' => $shared]);
        $records = [
            $this->record($own, DB::table('manuscripts')->where('id', $own)->value('shelfmark'), ['scribe_name' => ['from' => 'کاتب آزمایشی یکم، تا: 1085ق', 'to' => 'کاتب آزمایشی یکم'], 'copy_date_raw' => ['from' => null, 'to' => '1085ق']]) + ['scribe' => 'rename'],
            $this->record($first, DB::table('manuscripts')->where('id', $first)->value('shelfmark'), ['scribe_name' => ['from' => 'کاتب آزمایشی مشترک', 'to' => 'کاتب آزمایشی مشترک [تبریزی]']]) + ['scribe' => 'rename'],
        ];
        file_put_contents($this->file, json_encode(['records' => $records], JSON_UNESCAPED_UNICODE));

        try {
            $this->artisan('fahares:rebuild-copies', ['--file' => $this->file, '--apply' => true])->assertSuccessful();

            $this->assertSame('کاتب آزمایشی یکم', DB::table('people')->where('id', $renamed)->value('name'));
            $this->assertSame($renamed, (int) DB::table('manuscripts')->where('id', $own)->value('scribe_id'));
            $this->assertSame(1085, (int) DB::table('manuscripts')->where('id', $own)->value('copy_date_hijri_year'));
            $this->assertSame('کاتب آزمایشی مشترک', DB::table('people')->where('id', $shared)->value('name'));
            $this->assertNull(DB::table('manuscripts')->where('id', $first)->value('scribe_id'));
            $this->assertSame($shared, (int) DB::table('manuscripts')->where('id', $other)->value('scribe_id'));
        } finally {
            AuditLog::where('auditable_type', \App\Models\Person::class)->whereIn('auditable_id', [$renamed, $shared])->delete();
            DB::table('manuscripts')->whereIn('id', [$own, $first, $other])->update(['scribe_id' => null]);
            DB::table('people')->whereIn('id', [$renamed, $shared])->delete();
        }
    }
}
