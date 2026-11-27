<?php

namespace Tests\Feature;

use App\Console\Commands\RebuildScriptsCommand;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Script;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceVolume;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Backlog items 52 and 15 (steps 14.434 and 14.435): fahares:fix-script-scribe-leftovers mends «تایپی» and «پهلوی»
 * script fields and «= مؤلف» scribe names; fahares:fix-descriptive-people mends people whose name is descriptive text.
 */
class DescriptiveLeftoversTest extends TestCase
{
    private function work(array $values = []): Work
    {
        return Work::create($values + ['primary_title' => 'اثر آزمایشی', 'clean_title' => 'اثر آزمایشی', 'slug' => 'leftover-' . uniqid(), 'volume_number' => 99, 'page_start' => 1]);
    }

    private function copy(Work $work, string $shelfmark, array $values = []): Manuscript
    {
        return Manuscript::create($values + ['work_id' => $work->id, 'city' => 'قم', 'library' => 'مرعشی', 'shelfmark' => $shelfmark, 'volume_number' => 99, 'page_start' => 1]);
    }

    private function person(string $name): Person
    {
        return Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'p-' . uniqid()]);
    }

    private function file(array $data): string
    {
        $file = 'storage/framework/testing-' . uniqid() . '.json';
        file_put_contents(base_path($file), json_encode($data, JSON_UNESCAPED_UNICODE));

        return $file;
    }

    public function test_item_52_mends_scripts_and_author_scribes_once()
    {
        config(['scout.driver' => 'collection']);
        $nastaliq = Script::firstOrCreate(['name' => 'نستعلیق'], ['slug' => 'nastaliq-' . uniqid()]);
        $pahlavi = Script::firstOrCreate(['name' => 'پهلوی'], ['slug' => 'pahlavi-' . uniqid()]);
        $naskh = Script::firstOrCreate(['name' => 'نسخ'], ['slug' => 'naskh-' . uniqid()]);
        $author = $this->person('مؤلف آزمایشی، محمد');
        $work = $this->work(['author_id' => $author->id, 'author_name' => $author->name]);
        $typed = $this->copy($work, '11158', ['script_names' => 'تایپی']);
        $long = $this->copy($work, '6210', ['script_names' => 'پهلوی، دین دبیره، فارسی. دستورهای قرائت …']);
        DB::table('manuscript_script')->insert([['manuscript_id' => $long->id, 'script_id' => $naskh->id, 'is_primary' => 1], ['manuscript_id' => $long->id, 'script_id' => $pahlavi->id, 'is_primary' => 0]]);

        $placeholder = $this->person('کاتب = مؤلف');
        $sure = $this->copy($work, '5695/5', ['scribe_name' => 'کاتب = مؤلف', 'scribe_id' => $placeholder->id]);
        DB::table('manuscript_people')->insert(['manuscript_id' => $sure->id, 'person_id' => $placeholder->id, 'role' => 'scribe', 'position' => 0]);
        $doubted = $this->copy($work, '13750', ['scribe_name' => '= مؤلف (شاید)']);
        $dated = $this->copy($work, '12423', ['scribe_name' => '= مؤلف تا پنجشنبه دهه سوم محرم 1293ق']);

        $key = fn (Manuscript $m) => ['city' => 'قم', 'library' => 'مرعشی', 'shelfmark' => $m->shelfmark, 'work' => $work->slug];
        $file = $this->file([
            'scripts' => [
                $key($typed) + ['from' => 'تایپی', 'script_names' => 'نستعلیق', 'script_style' => 'تحریری', 'scripts' => ['نستعلیق'], 'page' => 'test'],
                $key($long) + ['from' => 'پهلوی، دین دبیره', 'script_names' => 'پهلوی', 'scripts' => ['پهلوی'], 'page' => 'test'],
            ],
            'autographs' => [
                $key($sure) + ['from' => 'کاتب = مؤلف', 'hedge' => null],
                $key($doubted) + ['from' => '= مؤلف (شاید)', 'hedge' => 'شاید'],
                $key($dated) + ['from' => '= مؤلف تا پنجشنبه دهه سوم محرم 1293ق', 'hedge' => null, 'copy_date_raw' => 'پنجشنبه دهه سوم محرم 1293ق'],
            ],
        ]);

        $this->artisan('fahares:fix-script-scribe-leftovers', ['--file' => $file])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame('تایپی', $typed->fresh()->script_names);

        $this->artisan('fahares:fix-script-scribe-leftovers', ['--file' => $file, '--apply' => true])->assertSuccessful();

        $scripts = fn (Manuscript $m) => DB::table('manuscript_script')->where('manuscript_id', $m->id)->orderByDesc('is_primary')->pluck('script_id')->all();
        $this->assertSame(['نستعلیق', 'تحریری'], [$typed->fresh()->script_names, $typed->fresh()->script_style]);
        $this->assertSame([$nastaliq->id], $scripts($typed));
        $this->assertSame(['پهلوی', [$pahlavi->id]], [$long->fresh()->script_names, $scripts($long)]);

        $this->assertSame([null, true, null, $author->id], [$sure->fresh()->scribe_name, $sure->fresh()->is_autograph, $sure->fresh()->autograph_hedge, $sure->fresh()->scribe_id]);
        $this->assertSame([$author->id], DB::table('manuscript_people')->where('manuscript_id', $sure->id)->where('role', 'scribe')->pluck('person_id')->all());
        $this->assertNull(Person::find($placeholder->id), 'the made-up «کاتب = مؤلف» person is removed');
        $this->assertSame([null, true, 'شاید'], [$doubted->fresh()->scribe_name, $doubted->fresh()->is_autograph, $doubted->fresh()->autograph_hedge]);
        $this->assertSame(['پنجشنبه دهه سوم محرم 1293ق', 1293], [$dated->fresh()->copy_date_raw, $dated->fresh()->copy_date_from]);
        $this->assertSame('دستخط مؤلف (شاید)', $doubted->fresh()->autograph_label);
        $this->followingRedirects()->get("/manuscripts/{$doubted->id}")->assertOk()->assertSee('دستخط مؤلف (شاید)');

        // a second run finds nothing left
        $this->artisan('fahares:fix-script-scribe-leftovers', ['--file' => $file])->expectsOutputToContain('already             5')->assertSuccessful();
        @unlink(base_path($file));
    }

    public function test_the_script_line_stops_before_a_description()
    {
        $line = function (string $text): ?string {
            return preg_match(RebuildScriptsCommand::LINE, $text, $m) ? $m[1] : null;
        };
        $this->assertSame('پهلوی', $line('خط: پهلوی، اشعار چهار ستونی فارسی ثبت شده در تعرف زراتشت بدین سرآغاز؛ جلد: تیماج'));
        $this->assertSame('پهلوی، دین دبیره', $line('خط: پهلوی، دین دبیره، فارسی. دستورهای قرائت با جوهر قرمز و به فارسی؛ کاغذ'));
        $this->assertSame('نستعلیق خوش', $line('خط: نستعلیق خوش. دستورهای قرائت با جوهر قرمز؛ کاغذ'));
        $this->assertSame('نستعلیق. آیات نسخ معرب، بی‌کا', $line('خط: نستعلیق. آیات نسخ معرب، بی‌کا، تا: قرن 11؛'));
        $this->assertSame('نستعلیق تحریری، بی‌کا، بی‌تا', $line('خط: نستعلیق تحریری، بی‌کا، بی‌تا، (سه کاتب) گویا در عصر مؤلف تحریر شده؛'));
        $this->assertSame('نسخ و نستعلیق، شکسته', $line('خط: نسخ و نستعلیق، شکسته، کا: علی؛'));
        $this->assertSame('نستعلیق خوش', $line('خط: نستعلیق خوش، 12 سطر'));
    }

    public function test_item_15_splits_names_from_descriptions_once()
    {
        config(['scout.driver' => 'collection']);
        // a work's author with the work's description glued to the name, linked only through work_author
        $glued = $this->person('مولانا زیرک آزمایشی (؟). دو تن را به نام زیرک می‌شناسیم.');
        $zirak = $this->work(['author_name' => 'مولانا زیرک آزمایشی (؟)', 'description' => 'دو تن را به نام زیرک می‌شناسیم.']);
        DB::table('work_author')->insert(['work_id' => $zirak->id, 'person_id' => $glued->id, 'position' => 0]);
        // the same, for a person the site already has
        $known = $this->person('بغوی آزمایشی، حسین بن مسعود');
        $copyOf = $this->person('امام بغوی آزمایشی. نویسنده می‌گوید');
        $iman = $this->work(['author_name' => 'امام بغوی آزمایشی']);
        DB::table('work_author')->insert(['work_id' => $iman->id, 'person_id' => $copyOf->id, 'position' => 0]);

        $work = $this->work();
        $scribed = function (string $name, string $shelfmark) use ($work) {
            $p = $this->person($name);
            $m = $this->copy($work, $shelfmark, ['scribe_name' => $name, 'scribe_id' => $p->id]);
            DB::table('manuscript_people')->insert(['manuscript_id' => $m->id, 'person_id' => $p->id, 'role' => 'scribe', 'position' => 0]);

            return [$p, $m];
        };
        [$scribe, $forCopy] = $scribed('محمد مهدی آزمایشی برای سید احمد آزمایشی', '1');
        [$patron, $ordered] = $scribed('به امر آقا محمد مهدی آزمایشی', '2');
        [$none, $noName] = $scribed('قرن 10 و 11', '3');

        $file = $this->file(['people' => [
            ['name' => 'مولانا زیرک آزمایشی (؟). دو تن', 'prefix' => true, 'action' => 'author', 'to' => 'مولانا زیرک آزمایشی', 'hedge' => '؟'],
            ['name' => 'امام بغوی آزمایشی. نویسنده می‌گوید', 'action' => 'author', 'link_to' => 'بغوی آزمایشی، حسین بن مسعود'],
            ['name' => 'محمد مهدی آزمایشی برای سید احمد آزمایشی', 'action' => 'scribe', 'to' => 'محمد مهدی آزمایشی', 'patron' => 'سید احمد آزمایشی', 'patron_label' => 'برای', 'note' => 'یادداشت آزمایشی کتابت'],
            ['name' => 'به امر آقا محمد مهدی آزمایشی', 'action' => 'patron', 'to' => 'آقا محمد مهدی آزمایشی', 'label_raw' => 'به امر'],
            ['name' => 'قرن 10 و 11', 'action' => 'remove', 'copy_date_raw' => 'قرن 10 و 11'],
        ]]);

        $this->artisan('fahares:fix-descriptive-people', ['--file' => $file])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame('قرن 10 و 11', $none->fresh()->name);

        $this->artisan('fahares:fix-descriptive-people', ['--file' => $file, '--apply' => true])->assertSuccessful();

        $author = fn (Work $w) => DB::table('work_people')->where('work_id', $w->id)->where('role', 'author')->first();
        $this->assertSame('مولانا زیرک آزمایشی', $glued->fresh()->name);
        $this->assertSame([$glued->id, 'doubtful', '؟'], [$author($zirak)->person_id, $author($zirak)->certainty, $author($zirak)->hedge_raw]);
        $this->assertSame(['مولانا زیرک آزمایشی', $glued->id], [$zirak->fresh()->author_name, $zirak->fresh()->author_id]);
        $this->assertSame([$known->id, $known->id], [$author($iman)->person_id, $iman->fresh()->author_id]);
        $this->assertNull(Person::find($copyOf->id), 'the glued copy of a known person is removed');

        $this->assertSame(['محمد مهدی آزمایشی', 'محمد مهدی آزمایشی', 'سید احمد آزمایشی'], [$scribe->fresh()->name, $forCopy->fresh()->scribe_name, $forCopy->fresh()->commissioned_by]);
        $this->assertTrue(DB::table('manuscript_people')->where('manuscript_id', $forCopy->id)->where('role', 'patron')->where('name', 'سید احمد آزمایشی')->where('label_raw', 'برای')->exists());
        $this->assertTrue(DB::table('manuscript_notes')->where('manuscript_id', $forCopy->id)->where('type', 'transcription')->where('text', 'یادداشت آزمایشی کتابت')->exists());

        $this->assertNull(Person::find($patron->id));
        $this->assertSame([null, null, 'آقا محمد مهدی آزمایشی'], [$ordered->fresh()->scribe_id, $ordered->fresh()->scribe_name, $ordered->fresh()->commissioned_by]);
        $this->assertSame(['patron'], DB::table('manuscript_people')->where('manuscript_id', $ordered->id)->pluck('role')->all());

        $this->assertNull(Person::find($none->id));
        $this->assertSame([null, null, 'قرن 10 و 11', 901, 1100], [$noName->fresh()->scribe_id, $noName->fresh()->scribe_name, $noName->fresh()->copy_date_raw, $noName->fresh()->copy_date_from, $noName->fresh()->copy_date_to]);
        $this->assertSame(0, DB::table('manuscript_people')->where('manuscript_id', $noName->id)->count());

        // a second run finds nothing left
        $this->artisan('fahares:fix-descriptive-people', ['--file' => $file])->expectsOutputToContain('already             5')->assertSuccessful();
        @unlink(base_path($file));
    }
    /** Step 14.444: dates and a scribe name from the printed page, and photographic-copy citations moved to their catalogue. */
    public function test_side_findings_mend_dates_a_scribe_and_photo_citations_once()
    {
        config(['scout.driver' => 'collection']);
        $work = $this->work();
        $typed = $this->copy($work, '130/1 سرود', ['city' => 'تهران', 'library' => 'مجلس', 'copy_date_raw' => '1314ق', 'copy_date_from' => 1314, 'copy_date_to' => 1314]);
        $set = $this->copy($work, '11677', ['city' => 'تهران', 'library' => 'مجلس', 'scribe_name' => 'حروچینی']);
        $photo = $this->copy($work, '1609 عکسی');
        $main = Source::create(['code' => 'test-marashi-main', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'rank' => 10, 'title' => 'فهرست خطی مرعشی']);
        $akasi = Source::create(['code' => 'test-marashi-akasi', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'rank' => 10, 'title' => 'فهرست عکسی مرعشی']);
        $mainVolume = SourceVolume::create(['source_id' => $main->id, 'volume_number' => 5]);
        $akasiVolume = SourceVolume::create(['source_id' => $akasi->id, 'volume_number' => 5]);
        $cataloger = DB::table('catalogers')->insertGetId(['name' => 'فهرست‌نگار آزمایشی', 'slug' => 'cataloger-' . uniqid()]);
        $other = $this->copy($work, '1602 عکسی');
        $entry = fn (Manuscript $m, int $source, int $volume, string $text, ?int $by = null) => SourceEntry::create(['citable_type' => Manuscript::class, 'citable_id' => $m->id,
            'source_id' => $source, 'source_volume_id' => $volume, 'volume_number' => 5, 'page_start' => 16, 'citation_text' => $text,
            'legacy_type' => 'fankha_citation', 'cataloger_id' => $by, 'origin' => SourceEntry::ORIGIN_MANUAL]);
        $entry($other, $akasi->id, $akasiVolume->id, '[عکسی: ف: 5-5]', $cataloger);
        $wrong = $entry($photo, $main->id, $mainVolume->id, '[عکسی مرعشی: ف: 5-16]');
        $mixed = $entry($this->copy($work, '10966/4'), $main->id, $mainVolume->id, '[ف: 5 - 377 و عکسی ف: 4 - 274]');

        $file = $this->file([
            'dates' => [['city' => 'تهران', 'library' => 'مجلس', 'shelfmark' => '130/1 سرود', 'work' => $work->slug, 'from' => '1314ق', 'copy_date_raw' => '1313ش و 1314ش', 'page' => 'test']],
            'scribe_names' => [['city' => 'تهران', 'library' => 'مجلس', 'shelfmark' => '11677', 'work' => $work->slug, 'from' => 'حروچینی', 'page' => 'test']],
            'photo_citations' => [['library' => 'مرعشی', 'from' => 'test-marashi-main', 'to' => 'test-marashi-akasi']],
        ]);

        $this->artisan('fahares:fix-copy-side-findings', ['--file' => $file])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->artisan('fahares:fix-copy-side-findings', ['--file' => $file, '--apply' => true])->assertSuccessful();

        $this->assertSame(['1313ش و 1314ش', 1352, 1353, 1352], [$typed->fresh()->copy_date_raw, $typed->fresh()->copy_date_from, $typed->fresh()->copy_date_to, $typed->fresh()->copy_date_hijri_year]);
        $this->assertNull($set->fresh()->scribe_name);
        $this->assertSame([$akasi->id, $akasiVolume->id, $cataloger], [$wrong->fresh()->source_id, $wrong->fresh()->source_volume_id, $wrong->fresh()->cataloger_id]);
        $this->assertSame($main->id, $mixed->fresh()->source_id, 'a citation that names the main catalogue first stays');

        $this->artisan('fahares:fix-copy-side-findings', ['--file' => $file])->expectsOutputToContain('citations         0')->assertSuccessful();
        @unlink(base_path($file));
    }
}
