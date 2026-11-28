<?php

namespace Tests\Feature;

use App\Console\Commands\DropRepeatedCitationsCommand;
use App\Models\AuditLog;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Suggestions\RecordMerger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** One copy citing one catalogue page twice after a merge (backlog item 178, step 14.455) */
class DropRepeatedCitationsTest extends TestCase
{
    use DatabaseTransactions;

    private Work $work;

    private Library $library;

    private int $source;

    private int $volume;

    private int $otherVolume;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'الهیات آزمون تکرار', 'city' => 'مشهد', 'slug' => 'repeat-lib-' . uniqid()]);
        $this->work = Work::create(['primary_title' => 'اثر آزمون ارجاع تکراری', 'clean_title' => 'اثر آزمون ارجاع تکراری', 'slug' => 'repeat-' . uniqid(),
            'volume_number' => 16, 'page_start' => 649]);
        $this->source = Source::create(['code' => 'repeat-cat-' . uniqid(), 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'rank' => 10,
            'title' => 'فهرست آزمون ارجاع تکراری', 'library_id' => $this->library->id])->id;
        $this->volume = DB::table('source_volumes')->insertGetId(['source_id' => $this->source, 'volume_number' => 1, 'title' => 'جلد ۱']);
        $this->otherVolume = DB::table('source_volumes')->insertGetId(['source_id' => $this->source, 'volume_number' => 2, 'title' => 'جلد ۲']);
    }

    private function copy(string $shelfmark): Manuscript
    {
        return Manuscript::create(['work_id' => $this->work->id, 'library_id' => $this->library->id, 'shelfmark' => $shelfmark,
            'volume_number' => 16, 'page_start' => 649, 'sequence_number' => 8, 'raw_text' => "شماره نسخه: {$shelfmark}"]);
    }

    /** The copy's Fankha citation entry, as SourceEntrySync writes it, or a moved one without legacy_type. */
    private function citation(Manuscript $copy, string $text, array $values = [], bool $legacy = true): SourceEntry
    {
        SourceEntry::where('citable_type', Manuscript::class)->where('citable_id', $copy->id)->where('origin', SourceEntry::ORIGIN_FANKHA_CITATION)
            ->where('legacy_type', 'fankha_citation')->when($legacy, fn ($q) => $q->delete());

        return SourceEntry::create($values + ['citable_type' => Manuscript::class, 'citable_id' => $copy->id, 'origin' => SourceEntry::ORIGIN_FANKHA_CITATION,
            'source_id' => $this->source, 'source_volume_id' => $this->volume, 'volume_number' => 1, 'page_start' => 509, 'citation_text' => $text]
            + ($legacy ? ['legacy_type' => 'fankha_citation', 'legacy_id' => $copy->id] : []));
    }

    private function backup(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'repeat');
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    private function citations(Manuscript $copy)
    {
        return SourceEntry::where('citable_type', Manuscript::class)->where('citable_id', $copy->id)->where('origin', SourceEntry::ORIGIN_FANKHA_CITATION)->orderBy('id')->get();
    }

    public function test_a_merge_drops_the_removed_copys_repeated_citation_and_its_undo_puts_it_back()
    {
        $kept = $this->copy('684/8');
        $removed = $this->copy('688/8');
        $this->citation($kept, '[ف: 1-509]', ['cataloger_id' => null]);
        $repeat = $this->citation($removed, '[ف: 1-509] ');

        $merger = app(RecordMerger::class);
        $old = $merger->mergeManuscript($removed, $kept);

        $this->assertSame(['[ف: 1-509]'], $this->citations($kept)->pluck('citation_text')->all());
        $this->assertSame([$repeat->id], array_column($old['dropped_source_entries'], 'id'));
        $this->assertNull(SourceEntry::find($repeat->id));

        $merger->undoManuscriptMerge($kept, $old);
        $back = SourceEntry::find($repeat->id);
        $this->assertSame($removed->id, (int) $back->citable_id);
        $this->assertSame('fankha_citation', $back->legacy_type);
        $this->assertCount(1, $this->citations($kept));
    }

    public function test_a_merge_moves_a_citation_of_the_same_text_at_another_place_or_with_more_data()
    {
        $kept = $this->copy('217');
        $removed = $this->copy('218');
        $this->citation($kept, '[ف: 1-128]');
        $elsewhere = $this->citation($removed, '[ف: 1-128]', ['source_volume_id' => $this->otherVolume, 'volume_number' => 2]);
        $more = $this->citation($removed, '[ف: 1-128]', ['copy_text' => 'سطر نسخه در فهرست'], false);

        $old = app(RecordMerger::class)->mergeManuscript($removed, $kept);

        $this->assertArrayNotHasKey('dropped_source_entries', $old);
        $this->assertCount(3, $this->citations($kept));
        $this->assertSame($kept->id, (int) $elsewhere->fresh()->citable_id);
        $this->assertSame($kept->id, (int) $more->fresh()->citable_id);
    }

    public function test_the_command_removes_the_repeats_after_a_dry_run_with_an_audit_log_and_finds_none_again()
    {
        $copy = $this->copy('700');
        $keeper = $this->citation($copy, '[نشریه: 13-264]', ['notes' => null]);
        $same = $this->citation($copy, '[نشریه: 13-264]', ['page_start' => null], false);
        $older = $this->citation($copy, ' [نشریه: 13-264]', ['source_volume_id' => $this->otherVolume], false);
        $richer = $this->citation($copy, '[نشریه: 13-264]', ['entry_text' => 'متن مدخل'], false);
        $another = $this->citation($copy, '[ف: 2-40]', [], false);

        Artisan::call('sources:drop-repeated-citations');
        $this->assertSame(5, $this->citations($copy)->count());
        $this->assertStringContainsString('Dry run', Artisan::output());

        Artisan::call('sources:drop-repeated-citations', ['--apply' => true, '--backup' => $this->backup()]);
        $this->assertEqualsCanonicalizing([$keeper->id, $richer->id, $another->id], $this->citations($copy)->pluck('id')->all());
        $logs = AuditLog::where('auditable_type', SourceEntry::class)->whereIn('auditable_id', [$same->id, $older->id])->where('action', 'delete')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($keeper->id, $logs->firstWhere('auditable_id', $older->id)->new_values['kept_entry_id']);
        $this->assertSame(['source_volume_id'], $logs->firstWhere('auditable_id', $older->id)->new_values['other_place']);
        $this->assertSame('[نشریه: 13-264]', DropRepeatedCitationsCommand::droppedRow($same->id)['citation_text']);

        Artisan::call('sources:drop-repeated-citations', ['--apply' => true]);
        $this->assertStringContainsString('Nothing to remove', Artisan::output());
        $this->assertSame(3, $this->citations($copy)->count());
    }

    public function test_undoing_an_earlier_merge_puts_back_a_repeat_the_command_removed()
    {
        $kept = $this->copy('684/8');
        $removed = $this->copy('688/8');
        $this->citation($kept, '[ف: 1-509]');
        // a merge of before step 14.455 moved the repeat (here: at another page, which a merge still moves)
        $moved = $this->citation($removed, '[ف: 1-509]', ['page_start' => 510]);
        $merger = app(RecordMerger::class);
        $old = $merger->mergeManuscript($removed, $kept);
        $this->assertNull($moved->fresh()->legacy_type);

        Artisan::call('sources:drop-repeated-citations', ['--apply' => true, '--backup' => $this->backup()]);
        $this->assertNull(SourceEntry::find($moved->id));

        $merger->undoManuscriptMerge($kept, $old);
        $back = SourceEntry::find($moved->id);
        $this->assertSame($removed->id, (int) $back->citable_id);
        $this->assertSame('fankha_citation', $back->legacy_type);
        $this->assertSame(510, (int) $back->page_start);
    }
}
