<?php

namespace Tests\Feature;

use App\Models\Manuscript;
use App\Models\Script;
use App\Models\Work;
use App\Support\ScriptNameCleaner;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CleanScriptNamesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_notes_are_split_from_the_script_name()
    {
        $r = ScriptNameCleaner::clean('تحریری، کاتب = مؤلف، بی‌تا');
        $this->assertSame(['تحریری'], $r['scripts']);
        $this->assertTrue($r['autograph'] && $r['bita']);
        $this->assertFalse($r['bika']);

        $r = ScriptNameCleaner::clean('نستعليق، بى كا، بى تا');
        $this->assertSame(['نستعلیق'], $r['scripts']);
        $this->assertTrue($r['bika'] && $r['bita']);

        $this->assertSame([], ScriptNameCleaner::clean('<!-- page: 19 --> بی‌کا')['scripts']);
        $this->assertSame(['نستعلیق', 'شکسته'], ScriptNameCleaner::clean('نستعيق شكسته')['scripts']);
        $this->assertSame(['نسخ'], ScriptNameCleaner::clean('نسخ عربی تا')['scripts']);
        $this->assertFalse(ScriptNameCleaner::clean('نسخ عربی تا')['bita']);
        $this->assertSame(['نستعلیق', 'شکسته'], ScriptNameCleaner::clean('نستعليق و شكسته، كا: محمد تقى حسينى')['scripts']);
    }

    public function test_a_hedged_or_translator_note_sets_no_autograph_flag()
    {
        $r = ScriptNameCleaner::clean('تحریری، کاتب = مؤلف (احتمالاً)، بی‌تا');
        $this->assertSame(['تحریری'], $r['scripts']);
        $this->assertFalse($r['autograph']);
        $this->assertTrue($r['bita']);

        $this->assertFalse(ScriptNameCleaner::clean('تحریری، کاتب = مترجم')['autograph']);
    }

    public function test_a_scribe_name_is_not_a_script()
    {
        $r = ScriptNameCleaner::clean('نستعليق ريز، كا: حبيب الله');
        $this->assertSame(['نستعلیق'], $r['scripts']);
        $this->assertSame('حبیب الله', $r['scribe']);

        $r = ScriptNameCleaner::clean('سید حسن حسینی نجف آبادی، بی‌تا');
        $this->assertSame([], $r['scripts']);
        $this->assertSame('سید حسن حسینی نجف آبادی', $r['scribe']);

        $this->assertSame('حسن بن محمد الخطاط', ScriptNameCleaner::clean('حسن بن محمد الخطاط')['scribe']);
    }

    public function test_clean_names_are_left_alone()
    {
        foreach (['نسخ', 'تحریری', 'سیاق', 'شکسته نستعلیق', 'رقعی', 'عبری', 'پهلوی'] as $name) {
            $this->assertTrue(ScriptNameCleaner::isClean($name), $name);
        }
    }

    public function test_qualities_variants_and_print_leave_the_script_list()
    {
        $r = ScriptNameCleaner::clean('تحریری متوسط، بی‌کا');
        $this->assertSame(['تحریری'], $r['scripts']);
        $this->assertSame(['متوسط'], $r['styles']);
        $this->assertSame(['تحریری متوسط'], $r['labels']);

        $r = ScriptNameCleaner::clean('خط زیبا');
        $this->assertSame([], $r['scripts']);
        $this->assertSame(['زیبا'], $r['styles']);

        $this->assertSame(['رقعی'], ScriptNameCleaner::clean('رقعه')['scripts']);
        $this->assertSame(['مختلف'], ScriptNameCleaner::clean('گوناگون، بی‌کا')['scripts']);
        $this->assertSame(['رقعی', 'نسخ'], ScriptNameCleaner::clean('نسخ و رقعه')['scripts']);

        $r = ScriptNameCleaner::clean('چاپی، بی‌کا');
        $this->assertSame([], $r['scripts']);
        $this->assertSame(['چاپی'], $r['labels']);
        $this->assertTrue($r['bika']);

        foreach (['تحریری متوسط', 'خط زیبا', 'چاپی', 'تایپی', 'سیاه مشق', 'رقعه'] as $name) {
            $this->assertFalse(ScriptNameCleaner::isClean($name), $name);
        }
    }

    public function test_the_command_moves_qualities_to_the_style_and_keeps_the_label()
    {
        $work = Work::create(['primary_title' => 'اثر آزمایشی کیفیت خط', 'clean_title' => 'اثر آزمایشی کیفیت خط', 'slug' => 'scripts-q-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $clean = Script::firstOrCreate(['name' => 'تحریری'], ['slug' => 'tahriri-test-' . uniqid()]);
        $quality = Script::firstOrCreate(['name' => 'تحریری متوسط'], ['slug' => 'quality-' . uniqid()]);
        $print = Script::firstOrCreate(['name' => 'چاپی'], ['slug' => 'print-' . uniqid()]);

        $a = Manuscript::create(['work_id' => $work->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1, 'script_names' => 'تحریری متوسط', 'script_style' => 'تحریری']);
        $b = Manuscript::create(['work_id' => $work->id, 'sequence_number' => 2, 'volume_number' => 1, 'page_start' => 1, 'script_names' => 'چاپی']);
        $a->scripts()->attach($quality->id, ['is_primary' => true]);
        $b->scripts()->attach($print->id, ['is_primary' => true]);

        $this->artisan('fahares:clean-script-names')->assertSuccessful();

        $this->assertModelMissing($quality);
        $this->assertModelMissing($print);
        $a->refresh();
        $b->refresh();
        $this->assertSame([$clean->id], $a->scripts()->pluck('scripts.id')->all());
        $this->assertSame('تحریری متوسط', $a->script_names);
        $this->assertSame('تحریری، متوسط', $a->script_style);
        $this->assertSame([], $b->scripts()->pluck('scripts.id')->all());
        $this->assertSame('چاپی', $b->script_names);
    }

    public function test_the_command_relinks_manuscripts_and_fills_flags()
    {
        $work = Work::create(['primary_title' => 'اثر آزمایشی خط', 'clean_title' => 'اثر آزمایشی خط', 'slug' => 'scripts-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $clean = Script::firstOrCreate(['name' => 'تحریری'], ['slug' => 'tahriri-test-' . uniqid()]);
        $noted = Script::create(['name' => 'تحریری، بی‌کا، بی‌تا', 'slug' => 'noted-' . uniqid()]);
        $scribe = Script::create(['name' => 'عباسقلی آزمایشی', 'slug' => 'scribe-' . uniqid()]);

        $a = Manuscript::create(['work_id' => $work->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1, 'script_names' => $noted->name, 'is_bika' => true]);
        $b = Manuscript::create(['work_id' => $work->id, 'sequence_number' => 2, 'volume_number' => 1, 'page_start' => 1, 'script_names' => $scribe->name]);
        $a->scripts()->attach($noted->id, ['is_primary' => true]);
        $b->scripts()->attach($scribe->id, ['is_primary' => true]);

        $this->artisan('fahares:clean-script-names', ['--dry-run' => true])->assertSuccessful();
        $this->assertModelExists($noted);

        $this->artisan('fahares:clean-script-names')->assertSuccessful();

        $this->assertModelMissing($noted);
        $this->assertModelMissing($scribe);
        $a->refresh();
        $b->refresh();
        $this->assertSame([$clean->id], $a->scripts()->pluck('scripts.id')->all());
        $this->assertSame('تحریری', $a->script_names);
        $this->assertTrue($a->is_bika && $a->is_bita);
        $this->assertSame([], $b->scripts()->pluck('scripts.id')->all());
        $this->assertNull($b->script_names);
        $this->assertSame('عباسقلی آزمایشی', $b->scribe_name);
        $this->assertSame($clean->manuscripts()->count(), $clean->fresh()->manuscripts_count);

        $this->artisan('fahares:clean-script-names')->expectsOutput('All script records are clean.')->assertSuccessful();
    }
}
