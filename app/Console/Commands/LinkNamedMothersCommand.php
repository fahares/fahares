<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A photograph or film whose Fankha text names its original («نسخه اصل: مینوی ش 144») when that original is on the
 * site under the same work: a facsimile_of relation to it (backlog item 71, solution 3; step 14.133). The judged
 * pairs are in a file, each with both copies' shelfmarks, so a pair that no longer matches the site is skipped.
 * A photograph that already has a mother or a «possibly the same» link keeps it. Each relation is logged as
 * `corpus_update`, so it can be found and removed. Without --apply it only reports.
 */
class LinkNamedMothersCommand extends Command
{
    protected $signature = 'fahares:link-named-mothers
        {--file= : The judged pairs (records: facsimile, facsimile_shelfmark, mother, mother_shelfmark, note)}
        {--apply : Write the relations}';

    protected $description = 'Links photographs to the original they name when that original is on the site';

    public function handle(): int
    {
        $plan = json_decode((string) @file_get_contents((string) $this->option('file')), true);
        if (! isset($plan['records'])) {
            $this->error('Decision file missing or invalid');

            return 1;
        }
        $ids = array_merge(array_column($plan['records'], 'facsimile'), array_column($plan['records'], 'mother'));
        $copies = DB::table('manuscripts')->whereIn('id', $ids)->get(['id', 'work_id', 'shelfmark', 'is_facsimile'])->keyBy('id');
        $counts = [];
        foreach ($plan['records'] as $rec) {
            $outcome = $this->check($rec, $copies[$rec['facsimile']] ?? null, $copies[$rec['mother']] ?? null);
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            if ($outcome !== 'link') {
                if ($outcome !== 'already') {
                    $this->line("#{$rec['facsimile']} → #{$rec['mother']}: {$outcome}");
                }
                continue;
            }
            if ($this->option('apply')) {
                DB::transaction(function () use ($rec) {
                    $id = DB::table('manuscript_relations')->insertGetId(['manuscript_id' => $rec['facsimile'],
                        'related_manuscript_id' => $rec['mother'], 'type' => 'facsimile_of',
                        'note' => $rec['note'] ? mb_substr($rec['note'], 0, 500) : null, 'source' => 'fankha',
                        'created_at' => now(), 'updated_at' => now()]);
                    AuditLog::create(['auditable_type' => \App\Models\Manuscript::class, 'auditable_id' => $rec['facsimile'],
                        'action' => 'corpus_update', 'old_values' => null,
                        'new_values' => ['manuscript_relation' => $id, 'facsimile_of' => $rec['mother'], 'category' => 'named_mother']]);
                });
            }
        }
        $this->table(['outcome', 'pairs'], collect($counts)->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->info(($this->option('apply') ? 'Linked ' : 'Would link ') . ($counts['link'] ?? 0) . ' photographs to their original.');

        return 0;
    }

    private function check(array $rec, ?object $photo, ?object $mother): string
    {
        if (! $photo || ! $mother) {
            return 'copy gone (merged or deleted)';
        }
        if ($photo->shelfmark !== $rec['facsimile_shelfmark'] || $mother->shelfmark !== $rec['mother_shelfmark']) {
            return 'shelfmark differs';
        }
        if ($photo->work_id !== $mother->work_id) {
            return 'not the same work';
        }
        if (! $photo->is_facsimile || $mother->is_facsimile) {
            return 'the photograph is not marked as one, or the original is';
        }
        $links = DB::table('manuscript_relations')->where('manuscript_id', $photo->id)->whereIn('type', ['facsimile_of', 'possibly_same'])->get();
        if ($links->contains(fn ($l) => $l->type === 'facsimile_of' && (int) $l->related_manuscript_id === (int) $mother->id)) {
            return 'already';
        }

        return $links->isEmpty() ? 'link' : 'has another link';
    }
}
