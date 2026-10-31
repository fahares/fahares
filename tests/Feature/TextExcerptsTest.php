<?php

namespace Tests\Feature;

use App\Console\Commands\FillTextExcerptsCommand;
use App\Models\Manuscript;
use App\Models\TextExcerpt;
use App\Models\Work;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A work's numbered and part incipits and explicits, and copies' «برابر 2» pointing at them (backlog item 98)
 */
class TextExcerptsTest extends TestCase
{
    public function test_labels_compare_by_number_whatever_the_wording()
    {
        $this->assertSame(FillTextExcerptsCommand::labelKey('جلد دوم'), FillTextExcerptsCommand::labelKey('جلد 2'));
        $this->assertSame(FillTextExcerptsCommand::labelKey('جلد ۲'), FillTextExcerptsCommand::labelKey('ج 2'));
        $this->assertSame('دیباچه', FillTextExcerptsCommand::labelKey('دیباچه'));
    }

    public function test_excerpts_are_filled_and_copies_point_at_them()
    {
        $work = Work::create(['primary_title' => 'روضة آزمایشی', 'clean_title' => 'روضة آزمایشی', 'slug' => 'test-excerpts-v5-20', 'volume_number' => 5, 'page_start' => 40,
            'incipit_text' => 'آغاز 1: الحمد لله الاول', 'explicit_text' => 'تمت']);
        $copy = fn (string $incipit, ?string $explicit = null) => Manuscript::create(['work_id' => $work->id, 'sequence_number' => 1, 'volume_number' => 5, 'page_start' => 40,
            'incipit_text' => $incipit, 'explicit_text' => $explicit]);
        $second = $copy('برابر 2', 'برابر1');
        $part = $copy('برابر جلد دوم');
        $glued = $copy('برابر2 خط: نستعلیق، بی‌کا');
        $missing = $copy('برابر 5');
        $plain = $copy('برابر');
        $file = tempnam(sys_get_temp_dir(), 'excerpts');
        file_put_contents($file, json_encode(['works' => [[
            'key' => ['vol' => 5, 'ordinal' => 21], 'title' => 'روضة آزمایشی', 'provenance' => ['page_start' => 40],
            'incipit_numbered' => [['n' => 1, 'label' => null, 'text' => 'الحمد لله الاول'], ['n' => 2, 'label' => 'دیباچه', 'text' => 'سپاس بی‌حد دوم']],
            'explicit_numbered' => [['n' => 1, 'label' => null, 'text' => 'تمت الکتاب']],
            'incipit_parts' => [['label' => 'جلد دوم', 'text' => 'هذا الجزء الثانی']],
        ]], 'peers' => []], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:fill-text-excerpts', ['--file' => $file])->assertSuccessful();
        $this->assertSame(0, TextExcerpt::count());

        $this->artisan('fahares:fill-text-excerpts', ['--file' => $file, '--apply' => true])->assertSuccessful();
        $this->assertSame(4, TextExcerpt::where('owner_type', Work::class)->where('owner_id', $work->id)->count());
        $target = fn (Manuscript $m, string $kind) => TextExcerpt::where('owner_type', Manuscript::class)->where('owner_id', $m->id)->where('kind', $kind)->first()?->sameAs?->text;
        $this->assertSame('سپاس بی‌حد دوم', $target($second, 'incipit'));
        $this->assertSame('تمت الکتاب', $target($second, 'explicit'));
        $this->assertSame('هذا الجزء الثانی', $target($part, 'incipit'));
        // The number of a pointer the old parser glued a physical line onto still counts; a missing number and a plain «برابر» have none
        $this->assertSame('سپاس بی‌حد دوم', $target($glued, 'incipit'));
        $this->assertNull($target($missing, 'incipit'));
        $this->assertNull($target($plain, 'incipit'));
        // The copy's own text is not touched
        $this->assertSame('برابر 2', $second->fresh()->incipit_text);

        // The copy's page shows the excerpt it points at, the work's page its numbered and part incipits
        $this->get(route('manuscripts.show', $second))->assertOk()->assertSee('منطبق بر آغاز ۲ (دیباچه) اثر')->assertSee('سپاس بی‌حد دوم')->assertSee('در صفحه‌ی اثر');
        $this->get(route('works.show', $work))->assertOk()->assertSee('آغاز ۱')->assertSee('سپاس بی‌حد دوم')->assertSee('آغاز جلد دوم')->assertSee('هذا الجزء الثانی')
            ->assertSee('آغاز ۲ (دیباچه)')->assertSee('id="excerpt-', false);

        // A second run writes no new rows
        $this->artisan('fahares:fill-text-excerpts', ['--file' => $file, '--apply' => true])->assertSuccessful();
        $this->assertSame(8, TextExcerpt::count());
        @unlink($file);
    }
}
