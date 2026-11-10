<?php

namespace App\Console\Commands;

use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Suggestions\RecordMerger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * What the merges of fahares:link-copy-references (step 14.16) left behind (step 14.131):
 *
 * - Fankha's «آغاز: برابر» / «انجام: برابر» of the removed entry. The site reads it from a copy's raw text, and that
 *   went with the removed record, so the kept copy showed no incipit where Fankha gives the work's. It becomes the
 *   metadata flag incipit_matches_work / explicit_matches_work, where the kept copy has no text of its own.
 * - The script links (manuscript_script) of a removed entry whose script names the kept copy took: the merge copied
 *   the column only.
 * - A script reading made from «خط: همان نسخه بالا», which the parser read as the script «نسخ».
 *
 * The removed records come from the backup of that run. Reports only, unless --apply; --backup keeps what it
 * changes, and --revert puts it back. Safe to run again. Merges since go through RecordMerger, which now does this.
 */
class FillCopyMergeGapsCommand extends Command
{
    protected $signature = 'fahares:fill-copy-merge-gaps
        {run : The backup fahares:link-copy-references wrote (its merges hold the removed records)}
        {--apply : Write (without it, only report)}
        {--backup= : Where to keep what changes (with --apply)}
        {--revert= : Undo the changes kept in this backup}';

    protected $description = 'Give copies merged by link-copy-references the «برابر», script links and readings their removed entry had';

    private const SAME_SCRIPT = '/(?:^|[؛\n])\s*خط\s*:\s*همان\s+نسخه\s+بالا/u';

    public function handle(): int
    {
        if ($file = $this->option('revert')) {
            return $this->revert($file);
        }
        $run = json_decode((string) @file_get_contents($this->argument('run')), true);
        if (($run['command'] ?? null) !== 'fahares:link-copy-references') {
            $this->error('Not a backup of fahares:link-copy-references.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $plans = [];
        $counts = ['incipit_matches_work' => 0, 'explicit_matches_work' => 0, 'scripts' => 0, 'readings' => 0];

        foreach ($run['merges'] as $merge) {
            $copy = Manuscript::find($merge['target_id']);
            if (! $copy || Manuscript::whereKey($merge['source_id'])->exists()) {
                continue; // split again since (step 14.76), or gone
            }
            $source = (new Manuscript)->setRawAttributes($merge['result']['source_snapshot']['attributes'] ?? []);
            $metaBefore = $copy->metadata;
            $flags = RecordMerger::carryMatchesWork($source, $copy);

            $scripts = [];
            $names = trim((string) $copy->script_names);
            if ($names !== '' && $names === trim((string) $source->script_names)
                && ! DB::table('manuscript_script')->where('manuscript_id', $copy->id)->exists()) {
                $scripts = array_map(fn ($row) => ['manuscript_id' => $copy->id, 'script_id' => (int) $row['script_id'], 'is_primary' => (bool) ($row['is_primary'] ?? false)],
                    $merge['result']['source_snapshot']['pivots']['manuscript_script'] ?? []);
            }

            $readings = collect();
            if (preg_match(self::SAME_SCRIPT, (string) $source->raw_text)) {
                $readings = FieldSuggestion::where('suggestable_type', Manuscript::class)->where('suggestable_id', $copy->id)
                    ->where('field_name', 'script_names')->where('status', 'approved')->whereNull('user_id')
                    ->whereRaw("JSON_VALUE(resolution, '$.origin') = 'copy_merge'")
                    ->whereRaw("JSON_VALUE(resolution, '$.merged_manuscript_id') = ?", [$merge['source_id']])->get();
            }
            if (! $flags && ! $scripts && $readings->isEmpty()) {
                continue;
            }
            foreach ($flags as $flag) {
                $counts[$flag]++;
            }
            $counts['scripts'] += $scripts ? 1 : 0;
            $counts['readings'] += $readings->count();
            $this->line(sprintf('%d (from %d): %s', $copy->id, $merge['source_id'], implode(', ', array_merge($flags,
                $scripts ? ['scripts ' . implode('/', array_column($scripts, 'script_id'))] : [], $readings->map(fn ($r) => "reading #{$r->id} «{$r->suggested_value}» removed")->all()))));
            $plans[] = ['copy' => $copy, 'meta_before' => $metaBefore, 'flags' => $flags, 'scripts' => $scripts, 'readings' => $readings];
        }

        // the backup first, then the change
        if ($apply && $plans) {
            $path = $this->option('backup') ?: storage_path('app/private/backups/fill-copy-merge-gaps-' . now()->format('Ymd_His') . '.json');
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, json_encode(['command' => $this->getName(), 'copies' => array_map(fn ($p) => [
                'manuscript_id' => $p['copy']->id, 'flags' => $p['flags'], 'scripts' => $p['scripts'],
                'readings' => $p['readings']->map->getAttributes()->all(),
            ], $plans)], JSON_UNESCAPED_UNICODE));
            $this->info("Backup: {$path}");
            foreach ($plans as $plan) {
                DB::transaction(function () use ($plan) {
                    $plan['copy']->save();
                    DB::table('manuscript_script')->insertOrIgnore($plan['scripts']);
                    FieldSuggestion::whereIn('id', $plan['readings']->pluck('id'))->delete();
                });
                $plan['copy']->searchable();
            }
            $this->recountScripts(array_merge(...array_map(fn ($p) => array_column($p['scripts'], 'script_id'), $plans)));
        }
        $this->info(sprintf('%s: %d incipits and %d explicits «برابر», %d copies get their script links, %d readings removed.',
            $apply ? 'Written' : 'Would write', $counts['incipit_matches_work'], $counts['explicit_matches_work'], $counts['scripts'], $counts['readings']));

        return self::SUCCESS;
    }

    private function revert(string $file): int
    {
        $backup = json_decode((string) @file_get_contents($file), true);
        if (($backup['command'] ?? null) !== $this->getName()) {
            $this->error('Not a backup of ' . $this->getName() . '.');

            return self::FAILURE;
        }
        $scripts = [];
        foreach ($backup['copies'] as $entry) {
            DB::transaction(function () use ($entry, &$scripts) {
                if ($copy = Manuscript::find($entry['manuscript_id'])) {
                    $copy->metadata = array_diff_key($copy->metadata ?? [], array_flip($entry['flags'])) ?: null;
                    $copy->save();
                }
                foreach ($entry['scripts'] as $row) {
                    DB::table('manuscript_script')->where(['manuscript_id' => $row['manuscript_id'], 'script_id' => $row['script_id']])->delete();
                    $scripts[] = $row['script_id'];
                }
                if ($entry['readings']) {
                    DB::table('field_suggestions')->insertOrIgnore($entry['readings']);
                }
            });
        }
        $this->recountScripts($scripts);
        $this->info(count($backup['copies']) . ' copies put back.');

        return self::SUCCESS;
    }

    private function recountScripts(array $ids): void
    {
        foreach (array_unique($ids) as $id) {
            DB::table('scripts')->where('id', $id)->update(['manuscripts_count' => DB::table('manuscript_script')->where('script_id', $id)->count()]);
        }
    }
}
