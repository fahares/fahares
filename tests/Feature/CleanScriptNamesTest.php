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
        $this->assertSame(['شکسته نستعلیق'], ScriptNameCleaner::clean('نستعيق شكسته')['scripts']);
        $this->assertSame(['نسخ'], ScriptNameCleaner::clean('نسخ عربی تا')['scripts']);
        $this->assertFalse(ScriptNameCleaner::clean('نسخ عربی تا')['bita']);
        $this->assertSame(['نستعلیق', 'شکسته نستعلیق'], ScriptNameCleaner::clean('نستعليق و شكسته، كا: محمد تقى حسينى')['scripts']);
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

    public function test_shekasteh_is_shekasteh_nastaliq()
    {
        $this->assertSame(['شکسته نستعلیق'], ScriptNameCleaner::clean('نستعلیق شکسته، بی‌کا')['scripts']);
        $this->assertSame('نستعلیق شکسته', ScriptNameCleaner::clean('نستعلیق شکسته، بی‌کا')['wording']);
        $this->assertSame(['شکسته نستعلیق'], ScriptNameCleaner::clean('شکسته، نستعلیق')['scripts']);
        $this->assertSame(['شکسته نستعلیق'], ScriptNameCleaner::clean('شکسته')['scripts']);
        $this->assertSame(['نستعلیق', 'شکسته نستعلیق'], ScriptNameCleaner::clean('نستعلیق و شکسته')['scripts']);
        $this->assertSame(['نستعلیق', 'شکسته نستعلیق'], ScriptNameCleaner::clean('نستعلیق مخلوط به شکسته')['scripts']);
        $this->assertSame(['شکسته تعلیق'], ScriptNameCleaner::clean('شکسته تعلیق')['scripts']);
        $this->assertTrue(ScriptNameCleaner::isClean('شکسته نستعلیق'));
        $this->assertFalse(ScriptNameCleaner::isClean('شکسته'));
    }

    public function test_a_second_hand_beside_a_known_script_is_kept()
    {
        $this->assertSame(['نستعلیق', 'سیاق'], ScriptNameCleaner::clean('نستعلیق و سیاق')['scripts']);
        $this->assertSame(['نسخ', 'رقاع'], ScriptNameCleaner::clean('نسخ و رقاع')['scripts']);
        $this->assertSame(['نسخ', 'تحریری'], ScriptNameCleaner::clean('نسخ و تحریری')['scripts']);
        // «تحریری» describing the same hand stays a quality
        $this->assertSame(['نستعلیق'], ScriptNameCleaner::clean('نستعلیق تحریری')['scripts']);
        $this->assertSame(['شکسته نستعلیق'], ScriptNameCleaner::clean('شکسته نستعلیق، تحریری')['scripts']);
        // a segment that names no hand is no script
        $this->assertSame([], ScriptNameCleaner::clean('چلیپایی، مجدول زرین و مشکی')['scripts']);
        $this->assertSame(['تحریری'], ScriptNameCleaner::clean('تحریری، مصحح')['scripts']);
    }

    public function test_copies_are_rebuilt_from_the_raw_text_and_link_to_their_scripts()
    {
        $work = Work::create(['primary_title' => 'اثر آزمایشی شکسته', 'clean_title' => 'اثر آزمایشی شکسته', 'slug' => 'scripts-s-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $shekasteh = Script::firstOrCreate(['name' => 'شکسته'], ['slug' => 'shekasteh-' . uniqid()]);
        $nastaliq = Script::firstOrCreate(['name' => 'نستعلیق'], ['slug' => 'nastaliq-' . uniqid()]);
        $taliq = Script::firstOrCreate(['name' => 'تعلیق'], ['slug' => 'taliq-' . uniqid()]);
        $copy = fn (int $n, string $line, array $scripts, ?string $style = null) => tap(Manuscript::create([
            'work_id' => $work->id, 'sequence_number' => $n, 'volume_number' => 1, 'page_start' => 1, 'script_style' => $style,
            'script_names' => implode('، ', array_map(fn ($s) => $s->name, $scripts)), 'raw_text' => "قم؛ آزمایشی؛ شماره نسخه: {$n}\n{$line}",
        ]), fn ($m) => $m->scripts()->attach(collect($scripts)->mapWithKeys(fn ($s, $i) => [$s->id => ['is_primary' => $i === 0]])->all()));

        $reversed = $copy(1, 'خط: نستعلیق شکسته، بی‌کا، تا: قرن 13؛ 40گ', [$nastaliq, $shekasteh]);
        $mixed = $copy(2, 'خط: نستعلیق مخلوط به شکسته، کا: آزمایشی، تا: 1250ق؛ 10گ', [$nastaliq, $shekasteh]);
        $taliqCopy = $copy(3, 'خط: شکسته تعلیق، بی‌کا؛ 5گ', [$shekasteh, $taliq]);
        $tahriri = $copy(4, 'خط: تحریری شکسته، بی‌تا؛ 3گ', [$shekasteh]);
        $siyaq = $copy(5, 'خط: نسخ و سیاق، بی‌کا؛ قطع: بیاض رقعی', [Script::firstOrCreate(['name' => 'نسخ'], ['slug' => 'naskh-' . uniqid()])]);
        $unlinked = $copy(6, 'خط: نسخ و تحریری، تا: 1250ق؛ مجدول', []);

        $this->artisan('fahares:rebuild-scripts', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(['شکسته', 'نستعلیق'], $reversed->fresh()->scripts()->orderBy('name')->pluck('name')->all());

        $this->artisan('fahares:rebuild-scripts')->assertSuccessful();

        $names = fn ($m) => $m->fresh()->scripts()->orderByDesc('is_primary')->pluck('name')->all();
        $this->assertSame(['شکسته نستعلیق'], $names($reversed));
        $this->assertSame('نستعلیق شکسته', $reversed->fresh()->script_names);
        $this->assertSame(['نستعلیق', 'شکسته نستعلیق'], $names($mixed));
        $this->assertSame('نستعلیق مخلوط به شکسته', $mixed->fresh()->script_names);
        $this->assertSame(['شکسته تعلیق'], $names($taliqCopy));
        $this->assertSame(['شکسته نستعلیق'], $names($tahriri));
        $this->assertSame(['نسخ', 'سیاق'], $names($siyaq));
        $this->assertSame(['نسخ', 'تحریری'], $names($unlinked));
        $this->assertSame('نسخ و تحریری', $unlinked->fresh()->script_names);
        $this->assertModelMissing($shekasteh);

        $this->get(route('manuscripts.show', $mixed->fresh()))
            ->assertOk()
            ->assertSee('نستعلیق مخلوط به شکسته')
            ->assertSee('در فهرست خطوط')
            ->assertSee(e(route('search', ['type' => 'manuscripts', 'script_id' => Script::where('name', 'شکسته نستعلیق')->value('id')])), false);

        $this->artisan('fahares:rebuild-scripts')->expectsOutput('Nothing to change.')->assertSuccessful();
    }
}
