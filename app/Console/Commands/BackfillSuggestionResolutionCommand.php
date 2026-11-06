<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Suggestions\SuggestionRegistry;
use Illuminate\Console\Command;

/**
 * Writes the earlier place into the resolution of approved structural suggestions applied before it was kept
 * there (backlog item 39): the work a copy was moved from (from_work_id, kept since step 13.7) and the library and
 * shelfmark a copy left (from_library_id, from_shelfmark, kept since step 14.66). The earlier value is read from
 * the audit log row the approval wrote (new_values.suggestion_id). Reports only, unless --apply; a second run
 * finds nothing.
 */
class BackfillSuggestionResolutionCommand extends Command
{
    protected $signature = 'fahares:backfill-suggestion-resolution
        {--apply : Write the resolutions (without it, only report)}';

    protected $description = 'Fill from_work_id / from_library_id of earlier approved move and relocate suggestions from the audit log';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $relations = SuggestionRegistry::RELATIONS['manuscript'];
        $fields = fn (string $apply) => array_keys(array_filter($relations, fn ($r) => ($r['apply'] ?? null) === $apply));
        // the resolution key => the audit log column it comes from, per kind of suggestion
        $plans = [
            'move' => ['from_work_id' => 'work_id'],
            'relocate' => ['from_library_id' => 'library_id', 'from_shelfmark' => 'shelfmark'],
        ];

        $rows = [];
        foreach ($plans as $kind => $keys) {
            $suggestions = FieldSuggestion::where('status', 'approved')->where('kind', 'relation')
                ->where('suggestable_type', Manuscript::class)->whereIn('field_name', $fields($kind))->orderBy('id')->get();
            foreach ($suggestions as $s) {
                $resolution = $s->resolution ?? [];
                if (empty($resolution['applied']) && $kind === 'move') {
                    continue;
                }
                $missing = array_diff_key($keys, $resolution);
                if (! $missing) {
                    continue;
                }
                $log = AuditLog::whereRaw("JSON_VALUE(new_values, '$.suggestion_id') = ?", [$s->id])->orderBy('id')->first();
                $old = $log?->old_values ?? [];
                $add = [];
                foreach ($missing as $key => $column) {
                    // relocate keeps only what changed: a shelfmark-only change has no earlier library
                    if (array_key_exists($column, $old) && $old[$column] !== null && $old[$column] !== '') {
                        $add[$key] = $old[$column];
                    }
                }
                $rows[] = [$s->id, $kind, $s->suggestable_id, $log?->id ?? '—', $add ? json_encode($add, JSON_UNESCAPED_UNICODE) : 'nothing in the audit log'];
                if ($apply && $add) {
                    $s->forceFill(['resolution' => $resolution + $add])->saveQuietly();
                }
            }
        }

        $this->table(['suggestion', 'kind', 'manuscript', 'audit log', 'adds'], $rows);
        $written = count(array_filter($rows, fn ($r) => $r[4] !== 'nothing in the audit log'));
        $this->info(($apply ? 'Written: ' : 'Would write: ') . $written . ' of ' . count($rows) . ' suggestions.');

        return self::SUCCESS;
    }
}
