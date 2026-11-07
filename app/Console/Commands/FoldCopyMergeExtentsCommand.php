<?php

namespace App\Console\Commands;

use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The leaves a merged copy had in its former volume belong to its former location, not to a «قول دیگر» on its leaf
 * count (step 14.80). fahares:link-copy-references (step 14.16) made one record of a copy two catalogues describe,
 * and where the leaf counts differed it kept the former catalogue's count as an alternative reading. But that count
 * is of one treatise inside a collection («34گ (213پ-246پ)»), which the present library split into volumes of their
 * own, so it does not contradict the present count. This writes the former range and count (from the removed
 * record, in the backup of that run) into the former location, and removes the leaf-count readings.
 *
 * Reports only, unless --apply; --backup keeps what it changes. Safe to run again.
 */
class FoldCopyMergeExtentsCommand extends Command
{
    protected $signature = 'fahares:fold-copy-merge-extents
        {run : The backup fahares:link-copy-references wrote (its merges hold the removed records)}
        {--apply : Write (without it, only report)}
        {--backup= : Where to keep the readings and former locations before the change (with --apply)}';

    protected $description = 'Move the former leaf range of copies merged by link-copy-references into their former location, and drop the leaf-count readings';

    public function handle(): int
    {
        $run = json_decode((string) @file_get_contents($this->argument('run')), true);
        if (($run['command'] ?? null) !== 'fahares:link-copy-references') {
            $this->error('Not a backup of fahares:link-copy-references.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $kept = [];
        $plans = [];
        $located = 0;
        $readings = 0;
        $missing = [];

        foreach ($run['merges'] as $merge) {
            $source = $merge['result']['source_snapshot']['attributes'] ?? [];
            $copy = Manuscript::find($merge['target_id']);
            if (! $copy || Manuscript::whereKey($merge['source_id'])->exists()) {
                continue; // split again since (step 14.76), or gone
            }
            $extent = array_filter([
                'folios' => (int) ($source['folios'] ?? 0) ?: null,
                'range_from' => trim((string) ($source['range_from'] ?? '')) ?: null,
                'range_to' => trim((string) ($source['range_to'] ?? '')) ?: null,
            ]);
            $meta = $copy->metadata ?? [];
            $formers = (array) ($meta['former_locations'] ?? []);
            $index = collect($formers)->search(fn ($f) => is_array($f) && empty($f['suggestion_id'])
                && (string) ($f['shelfmark'] ?? '') === (string) ($source['shelfmark'] ?? '')
                && trim((string) ($f['library'] ?? '')) === trim((string) ($source['library'] ?? '')));
            if ($index === false) {
                $missing[] = $copy->id;

                continue;
            }
            $add = array_diff_key($extent, $formers[$index]);
            $folioReadings = FieldSuggestion::where('suggestable_type', Manuscript::class)->where('suggestable_id', $copy->id)
                ->where('field_name', 'folios')->where('status', 'approved')->whereNull('user_id')
                ->whereRaw("JSON_VALUE(resolution, '$.origin') = 'copy_merge'")
                ->whereRaw("JSON_VALUE(resolution, '$.merged_manuscript_id') = ?", [$merge['source_id']])->get();
            if (! $add && $folioReadings->isEmpty()) {
                continue;
            }
            $located += $add ? 1 : 0;
            $readings += $folioReadings->count();
            $kept[] = ['manuscript_id' => $copy->id, 'former_locations' => $formers, 'readings' => $folioReadings->map->getAttributes()->all()];
            $plans[] = compact('copy', 'meta', 'formers', 'index', 'add', 'folioReadings');
        }

        // the backup first, then the change
        if ($apply && $kept) {
            $path = $this->option('backup') ?: storage_path('app/private/backups/fold-copy-merge-extents-' . now()->format('Ymd_His') . '.json');
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, json_encode(['command' => $this->getName(), 'copies' => $kept], JSON_UNESCAPED_UNICODE));
            $this->info("Backup: {$path}");
            foreach ($plans as $plan) {
                DB::transaction(function () use ($plan) {
                    $formers = $plan['formers'];
                    $formers[$plan['index']] = $formers[$plan['index']] + $plan['add'];
                    $plan['copy']->forceFill(['metadata' => ['former_locations' => $formers] + $plan['meta']])->saveQuietly();
                    FieldSuggestion::whereIn('id', $plan['folioReadings']->pluck('id'))->delete();
                });
            }
        }
        if ($missing) {
            $this->warn(count($missing) . ' merged copies have no matching former location: ' . implode(', ', array_slice($missing, 0, 10)));
        }
        $this->info(sprintf('%s: %d former locations get their leaves, %d leaf-count readings removed.', $apply ? 'Written' : 'Would write', $located, $readings));

        return self::SUCCESS;
    }
}
