<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Suggestions\MergeComparison;
use App\Suggestions\RecordMerger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One copy with two records, one under Fankha's wrong shelfmark and one under the printed one (backlog item 159, step
 * 14.387). sources:shelfmark-claims (step 14.266) left 71 such pairs uncorrected, since the printed shelfmark already
 * had a record. Each was judged from the printed catalogue's page; the verdicts are database/data/shelfmark_twins.json,
 * keyed by library slug, shelfmark and work slug.
 *
 * - merges: the record with the wrong shelfmark is merged into the one with the printed shelfmark through RecordMerger,
 *   as an admin's merge: the merge comparison's defaults, or the choice the file gives for a field («source» takes the
 *   removed record's value), a redirect, and an AuditLog «merge». The removed record's entry in the same printed
 *   catalogue, which the kept record already has, is dropped after the merge; the audit log keeps its row.
 * - corrections: a record that is another part of the printed collection (a second work of one part, or a part with
 *   no record) takes the printed shelfmark, with an AuditLog «update». sources:take-texts then takes its text.
 *
 * Without --apply it only reports. Safe to run again: a pair already done is reported as such. --revert undoes a run.
 */
class MergeShelfmarkTwinsCommand extends Command
{
    protected $signature = 'fahares:merge-shelfmark-twins
        {--apply : Merge and correct; without it the command only reports}
        {--data= : The data file (default database/data/shelfmark_twins.json)}
        {--backup= : Where to write the backup (default storage/app/private/backups/)}
        {--revert= : Undo the changes of a backup this command wrote}';

    protected $description = 'Merge the copies Fankha gives under a wrong shelfmark into their record under the printed one';

    private const FIX = 'step 14.387 merge-shelfmark-twins';

    public function handle(): int
    {
        if ($file = $this->option('revert')) {
            return $this->revert($file);
        }
        $data = json_decode((string) file_get_contents($this->option('data') ?: database_path('data/shelfmark_twins.json')), true);
        $apply = (bool) $this->option('apply');
        $path = $this->option('backup') ?: storage_path('app/private/backups/merge-shelfmark-twins-' . now()->format('Ymd_His') . '.json');
        $backup = ['command' => $this->getName(), 'merges' => [], 'corrections' => []];
        $counts = ['merged' => 0, 'corrected' => 0, 'done before' => 0, 'not found' => 0];
        $merger = app(RecordMerger::class);

        foreach ($data['merges'] ?? [] as $pair) {
            $library = $pair['library'];
            $kept = $this->find($library, $pair['kept']);
            $removed = $this->find($library, $pair['removed']);
            $label = "{$library} {$pair['removed']['shelfmark']} → {$pair['kept']['shelfmark']}";
            if (! $kept || ! $removed) {
                $key = $kept && ! $removed && $this->mergedBefore($kept) ? 'done before' : 'not found';
                $counts[$key]++;
                $this->line("  {$key}: {$label}");

                continue;
            }
            $choices = MergeComparison::choices(MergeComparison::rows($removed, $kept), $pair['fields'] ?? []);
            $this->line("  merge #{$removed->id} → #{$kept->id} {$label} " . json_encode($choices, JSON_UNESCAPED_UNICODE));
            $counts['merged']++;
            if (! $apply) {
                continue;
            }
            DB::transaction(function () use ($merger, $removed, $kept, $pair, &$backup) {
                // the kept copy's own entry in the printed catalogue makes the removed one's a repeat; Fankha's two
                // entries are two places in Fankha and both stay
                $fankha = Source::where('code', 'fankha')->value('id');
                $entries = fn (Manuscript $m) => SourceEntry::where('citable_type', Manuscript::class)->where('citable_id', $m->id)->where('source_id', '!=', $fankha);
                $repeats = $entries($removed)->whereIn('source_id', $entries($kept)->pluck('source_id'))->get();
                $sourceId = $removed->id;
                // saving the kept copy's metadata writes its cited entry again from the citation, without the page
                // and text the print gave it (SourceEntrySync::syncCited); the citation is the same, so they come back
                $cited = fn () => DB::table('source_entries')->where('legacy_type', 'fankha_citation')->where('legacy_id', $kept->id)->where('legacy_index', 0)->first();
                $before = $cited();
                $result = $merger->mergeManuscript($removed, $kept, $pair['fields'] ?? []);
                $after = $cited();
                if ($before && $after && $after->id === $before->id && $after->citation_text === $before->citation_text) {
                    DB::table('source_entries')->where('id', $before->id)->update(array_diff_key((array) $before, array_flip(['id', 'created_at'])));
                }
                $dropped = [];
                foreach ($repeats as $entry) {
                    $dropped[] = (array) DB::table('source_entries')->where('id', $entry->id)->first();
                    DB::table('source_entries')->where('id', $entry->id)->delete();
                }
                $log = AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $kept->id, 'action' => 'merge',
                    'old_values' => ['source_id' => $sourceId] + $result,
                    'new_values' => ['target_id' => $kept->id, 'corpus_fix' => self::FIX, 'dropped_entries' => $dropped]]);
                $backup['merges'][] = ['source_id' => $sourceId, 'target_id' => $kept->id, 'log_id' => $log->id, 'result' => $result, 'dropped_entries' => $dropped];
            });
            file_put_contents($path, json_encode($backup, JSON_UNESCAPED_UNICODE));
        }

        foreach ($data['corrections'] ?? [] as $fix) {
            $copy = $this->find($fix['library'], $fix);
            $label = "{$fix['library']} {$fix['shelfmark']} → {$fix['to']}";
            if (! $copy) {
                $key = $this->find($fix['library'], ['shelfmark' => $fix['to'], 'work' => $fix['work']]) ? 'done before' : 'not found';
                $counts[$key]++;
                $this->line("  {$key}: {$label}");

                continue;
            }
            $this->line("  correct #{$copy->id} {$label}");
            $counts['corrected']++;
            if (! $apply) {
                continue;
            }
            DB::transaction(function () use ($copy, $fix, &$backup) {
                $old = $copy->shelfmark;
                $copy->shelfmark = $fix['to'];
                $copy->save();
                $log = AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $copy->id, 'action' => 'update',
                    'old_values' => ['shelfmark' => $old], 'new_values' => ['shelfmark' => $fix['to'], 'corpus_fix' => self::FIX]]);
                $backup['corrections'][] = ['id' => $copy->id, 'old' => $old, 'new' => $fix['to'], 'log_id' => $log->id];
            });
            file_put_contents($path, json_encode($backup, JSON_UNESCAPED_UNICODE));
        }

        foreach ($counts as $kind => $n) {
            $this->line(sprintf('  %-12s %4d', $kind, $n));
        }
        if ($apply && ($backup['merges'] || $backup['corrections'])) {
            $this->info("Backup: {$path}");
        } else {
            $this->info($apply ? 'Nothing to change.' : 'Dry run: nothing changed.');
        }

        return self::SUCCESS;
    }

    /** The one copy of a library with this shelfmark and work, or null */
    private function find(string $library, array $key): ?Manuscript
    {
        $libraryId = Library::where('slug', $library)->value('id');
        $workId = Work::where('slug', $key['work'])->value('id');
        if (! $libraryId || ! $workId) {
            return null;
        }
        $copies = Manuscript::where('library_id', $libraryId)->where('work_id', $workId)->where('shelfmark', $key['shelfmark'])->get();

        return $copies->count() === 1 ? $copies->first() : null;
    }

    private function mergedBefore(Manuscript $kept): bool
    {
        return AuditLog::where('auditable_type', Manuscript::class)->where('auditable_id', $kept->id)->where('action', 'merge')
            ->where('new_values->corpus_fix', self::FIX)->exists();
    }

    /** Undoes a run, last first */
    private function revert(string $file): int
    {
        $backup = is_file($file) ? json_decode(file_get_contents($file), true) : null;
        if (($backup['command'] ?? null) !== $this->getName()) {
            $this->error("{$file}: not a backup of {$this->getName()}.");

            return self::FAILURE;
        }
        $merger = app(RecordMerger::class);
        foreach (array_reverse($backup['corrections']) as $fix) {
            $copy = Manuscript::find($fix['id']);
            if (! $copy || $copy->shelfmark !== $fix['new']) {
                $this->warn("#{$fix['id']}: gone or edited since; left as it is.");

                continue;
            }
            $copy->shelfmark = $fix['old'];
            $copy->save();
            AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $copy->id, 'action' => 'update',
                'old_values' => ['shelfmark' => $fix['new']], 'new_values' => ['shelfmark' => $fix['old'], 'corpus_fix' => self::FIX . ' --revert']]);
        }
        $done = 0;
        foreach (array_reverse($backup['merges']) as $merge) {
            $target = Manuscript::find($merge['target_id']);
            if (! $target || Manuscript::whereKey($merge['source_id'])->exists()) {
                $this->warn("{$merge['source_id']} → {$merge['target_id']}: already split, or the kept copy is gone.");

                continue;
            }
            DB::transaction(function () use ($merger, $target, $merge) {
                // back on the kept copy first, so the undo moves it with the other entries
                foreach ($merge['dropped_entries'] as $row) {
                    DB::table('source_entries')->insertOrIgnore($row);
                }
                $merger->undoManuscriptMerge($target, $merge['result']);
                AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $target->id, 'action' => 'rollback_merge',
                    'old_values' => ['rolled_back_log_id' => $merge['log_id'] ?? null, 'target_id' => $target->id],
                    'new_values' => ['restored_manuscript_id' => $merge['source_id'], 'corpus_fix' => self::FIX . ' --revert']]);
            });
            $done++;
        }
        $this->info(sprintf('Reverted %d merges and %d corrections.', $done, count($backup['corrections'])));

        return self::SUCCESS;
    }
}
