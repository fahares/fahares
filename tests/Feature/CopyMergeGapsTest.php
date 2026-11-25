<?php

namespace Tests\Feature;

use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\Script;
use App\Models\Work;
use App\Suggestions\RecordMerger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A merged copy keeps the «برابر» and script links of the entry merged into it (step 14.131) */
class CopyMergeGapsTest extends TestCase
{
    use DatabaseTransactions;

    private Work $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = Work::create(['primary_title' => 'اثر آزمون شکاف ادغام', 'clean_title' => 'اثر آزمون شکاف ادغام', 'slug' => 'merge-gaps-' . uniqid(), 'volume_number' => 16, 'page_start' => 649]);
    }

    private function copy(string $library, string $shelfmark, string $text, array $extra = []): Manuscript
    {
        return Manuscript::create(['work_id' => $this->work->id, 'volume_number' => 16, 'page_start' => 649, 'sequence_number' => 8,
            'library' => $library, 'city' => 'مشهد', 'shelfmark' => $shelfmark, 'raw_text' => $text] + $extra);
    }

    private function script(string $name): int
    {
        return Script::firstOrCreate(['name' => $name], ['slug' => 'test-script-' . uniqid()])->id;
    }

    public function test_a_merge_carries_the_removed_entrys_barabar_and_script_links_and_undoes_them()
    {
        $kept = $this->copy('الهیات', '217', "مشهد؛ الهیات؛ شماره نسخه: 217\nبی‌کا، بی‌تا؛ 11گ [ف: 1- 128]", ['folios' => 11]);
        $removed = $this->copy('مولوی', '217', "مشهد؛ مولوی؛ شماره نسخه: 217\nآغاز و انجام: برابر\nخط: نستعلیق [نشریه: 5 - 31]", ['script_names' => 'نستعلیق']);
        $nastaliq = $this->script('نستعلیق');
        DB::table('manuscript_script')->insert(['manuscript_id' => $removed->id, 'script_id' => $nastaliq, 'is_primary' => true]);
        $this->assertFalse($kept->incipit_matches_work);

        $merger = app(RecordMerger::class);
        $old = $merger->mergeManuscript($removed, $kept);

        $kept = $kept->fresh();
        $this->assertTrue($kept->incipit_matches_work);
        $this->assertTrue($kept->explicit_matches_work);
        $this->assertSame('نستعلیق', $kept->script_names);
        $this->assertSame([$nastaliq], DB::table('manuscript_script')->where('manuscript_id', $kept->id)->pluck('script_id')->map(fn ($id) => (int) $id)->all());

        $merger->undoManuscriptMerge($kept, $old);
        $kept = $kept->fresh();
        $this->assertFalse($kept->incipit_matches_work);
        $this->assertArrayNotHasKey('incipit_matches_work', $kept->metadata ?? []);
        $this->assertFalse(DB::table('manuscript_script')->where('manuscript_id', $kept->id)->exists());
        $this->assertTrue(DB::table('manuscript_script')->where('manuscript_id', $removed->id)->exists());
    }

    public function test_a_merge_keeps_the_kept_copys_own_incipit_and_scripts()
    {
        $kept = $this->copy('الهیات', '218', "مشهد؛ الهیات؛ شماره نسخه: 218\nآغاز: بسمله\nخط: نسخ", ['incipit_text' => 'بسمله', 'script_names' => 'نسخ']);
        DB::table('manuscript_script')->insert(['manuscript_id' => $kept->id, 'script_id' => $this->script('نسخ'), 'is_primary' => true]);
        $removed = $this->copy('مولوی', '218', "مشهد؛ مولوی؛ شماره نسخه: 218\nآغاز: برابر\nخط: نستعلیق", ['script_names' => 'نستعلیق']);
        DB::table('manuscript_script')->insert(['manuscript_id' => $removed->id, 'script_id' => $this->script('نستعلیق'), 'is_primary' => true]);

        app(RecordMerger::class)->mergeManuscript($removed, $kept);

        $kept = $kept->fresh();
        $this->assertArrayNotHasKey('incipit_matches_work', $kept->metadata ?? []);
        $this->assertSame(['نسخ'], DB::table('manuscript_script')->join('scripts', 'scripts.id', '=', 'script_id')->where('manuscript_id', $kept->id)->pluck('name')->all());
    }
}
