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

    public function test_the_command_fills_what_the_step_14_16_merges_left_and_reverts()
    {
        $kept = $this->copy('الهیات', '217', "8. مشهد؛ الهیات؛ شماره نسخه: 217\nبی‌کا، بی‌تا [ف: 1- 128]", ['script_names' => 'نستعلیق']);
        $other = $this->copy('الهیات', '111', "مشهد؛ الهیات؛ شماره نسخه: 111\nخط: نستعلیق [ف: 1- 66]", ['script_names' => 'نستعلیق']);
        $nastaliq = $this->script('نستعلیق');
        $naskh = $this->script('نسخ');
        DB::table('manuscript_script')->insert(['manuscript_id' => $other->id, 'script_id' => $nastaliq, 'is_primary' => true]);
        $reading = FieldSuggestion::create(['suggestable_type' => Manuscript::class, 'suggestable_id' => $other->id, 'kind' => 'field',
            'field_name' => 'script_names', 'current_value' => 'نستعلیق', 'suggested_value' => 'نسخ', 'status' => 'approved', 'standing' => 'alternative',
            'resolution' => ['applied' => false, 'origin' => 'copy_merge', 'merged_manuscript_id' => 999000002]]);
        $snapshot = fn (int $id, string $raw, ?string $scripts, array $pivots) => ['attributes' => [
            'id' => $id, 'work_id' => $this->work->id, 'raw_text' => $raw, 'script_names' => $scripts, 'incipit_text' => null, 'explicit_text' => null, 'metadata' => null,
        ], 'pivots' => ['manuscript_script' => $pivots, 'manuscript_people' => []]];
        $run = storage_path('app/test-link-copy-run-' . uniqid() . '.json');
        file_put_contents($run, json_encode(['command' => 'fahares:link-copy-references', 'merges' => [
            ['source_id' => 999000001, 'target_id' => $kept->id, 'result' => ['source_snapshot' => $snapshot(999000001,
                "مشهد؛ مولوی؛ شماره نسخه: 217\nآغاز: برابر؛ انجام: کند مجنون\nخط: نستعلیق", 'نستعلیق', [['manuscript_id' => 999000001, 'script_id' => $nastaliq, 'is_primary' => 1]])]],
            ['source_id' => 999000002, 'target_id' => $other->id, 'result' => ['source_snapshot' => $snapshot(999000002,
                "مشهد؛ مولوی؛ شماره نسخه: 111\nخط: همان نسخه بالا [نشریه: 5 - 20]", 'نسخ', [['manuscript_id' => 999000002, 'script_id' => $naskh, 'is_primary' => 1]])]],
        ]]));
        $backup = storage_path('app/test-fill-copy-merge-gaps-' . uniqid() . '.json');

        $this->artisan('fahares:fill-copy-merge-gaps', ['run' => $run])
            ->expectsOutputToContain('Would write: 1 incipits and 0 explicits «برابر», 1 copies get their script links, 1 readings removed.')->assertSuccessful();
        $this->assertFalse($kept->fresh()->incipit_matches_work);

        $this->artisan('fahares:fill-copy-merge-gaps', ['run' => $run, '--apply' => true, '--backup' => $backup])->assertSuccessful();
        $kept = $kept->fresh();
        $this->assertTrue($kept->incipit_matches_work);
        $this->assertArrayNotHasKey('explicit_matches_work', $kept->metadata);
        $this->assertSame([$nastaliq], DB::table('manuscript_script')->where('manuscript_id', $kept->id)->pluck('script_id')->map(fn ($id) => (int) $id)->all());
        $this->assertNull(FieldSuggestion::find($reading->id));
        // the other copy's own script link stays as it was
        $this->assertSame([$nastaliq], DB::table('manuscript_script')->where('manuscript_id', $other->id)->pluck('script_id')->map(fn ($id) => (int) $id)->all());

        $this->artisan('fahares:fill-copy-merge-gaps', ['run' => $run])
            ->expectsOutputToContain('Would write: 0 incipits and 0 explicits «برابر», 0 copies get their script links, 0 readings removed.')->assertSuccessful();

        $this->artisan('fahares:fill-copy-merge-gaps', ['run' => $run, '--revert' => $backup])->assertSuccessful();
        $this->assertFalse($kept->fresh()->incipit_matches_work);
        $this->assertFalse(DB::table('manuscript_script')->where('manuscript_id', $kept->id)->exists());
        $this->assertNotNull(FieldSuggestion::find($reading->id));
        @unlink($run);
        @unlink($backup);
    }
}
