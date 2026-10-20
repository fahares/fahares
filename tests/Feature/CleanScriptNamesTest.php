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
        foreach (['نسخ', 'تحریری', 'تحریری متوسط', 'خط زیبا', 'سیاق', 'شکسته نستعلیق'] as $name) {
            $this->assertTrue(ScriptNameCleaner::isClean($name), $name);
        }
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
