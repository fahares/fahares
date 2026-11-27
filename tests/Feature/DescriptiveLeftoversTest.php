<?php

namespace Tests\Feature;

use App\Console\Commands\RebuildScriptsCommand;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Script;
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
}
