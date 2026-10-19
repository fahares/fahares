<?php

namespace App\Console\Commands;

use App\Models\Work;
use App\Suggestions\ProtectedColumns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Applies the work fields that changed between two corpus releases, without a full sync. A change is written only
 * where the site still holds the old release's value: a value edited since keeps its edit, and only the changed
 * stretches of a text field are patched into it when each occurs exactly once. Everything else is reported.
 */
class ApplyCorpusDiffCommand extends Command
{
    protected $signature = 'fahares:apply-corpus-diff
                            {--file= : Path to the diff file (database/data/corpus_v1.1.4_bibliography.json)}
                            {--apply : Write the changes; without it the command only reports what it would do}';

    protected $description = 'Apply the bibliography, description and raw_text changes of a corpus release to works the site has not edited since';

    private const FIELDS = ['bibliography', 'description', 'raw_text'];

    public function handle(): int
    {
        $filePath = $this->option('file') ?: database_path('data/corpus_v1.1.4_bibliography.json');
        $diff = File::exists($filePath) ? json_decode(File::get($filePath), true) : null;
        if (! $diff || ! isset($diff['works'])) {
            $this->error("Diff file missing or invalid: {$filePath}");
            return 1;
        }

        $apply = (bool) $this->option('apply');
        $this->info(($apply ? 'Applying' : 'Dry run of') . " corpus {$diff['from']} → {$diff['to']}: " . count($diff['works']) . ' works');

        $guard = ProtectedColumns::for(Work::class);
        $counts = array_fill_keys(['replaced', 'patched', 'already', 'protected', 'conflict'], 0);
        $problems = [];
        $updates = [];

        foreach ($diff['works'] as $w) {
            $label = "v{$w['volume']}-{$w['number']} «{$w['title']}»";
            $rows = DB::table('works')->where('volume_number', $w['volume'])
                ->where('slug', 'like', "%-v{$w['volume']}-{$w['number']}")
                ->get(['id', 'slug', ...self::FIELDS])
                ->filter(fn ($r) => str_ends_with($r->slug, "-v{$w['volume']}-{$w['number']}"));
            if ($rows->count() !== 1) {
                $problems[] = "{$label}: {$rows->count()} matching works (merged or deleted?)";
                $counts['conflict'] += count($w['fields']);
                continue;
            }
            $row = $rows->first();

            $payload = [];
            foreach ($w['fields'] as $field => $change) {
                [$value, $outcome] = $this->resolve($field, $row->$field, $change);
                if ($outcome === 'conflict') {
                    $problems[] = "#{$row->id} {$label}: {$field} was edited on the site and the change can't be placed";
                } elseif ($outcome !== 'already') {
                    $payload[$field] = $value;
                }
                $counts[$outcome]++;
            }

            $kept = $guard->strip($row->id, $payload);
            foreach (array_diff_key($payload, $kept) as $field => $_) {
                $counts['protected']++;
                $problems[] = "#{$row->id} {$label}: {$field} has a reviewed correction, left as is";
            }
            if ($kept) {
                $updates[$row->id] = $kept;
            }
        }

        if ($apply && $updates) {
            DB::transaction(function () use ($updates) {
                foreach ($updates as $id => $payload) {
                    DB::table('works')->where('id', $id)->update($payload);
                }
            });
            // The description is part of the search index
            $reindex = array_keys(array_filter($updates, fn ($p) => array_key_exists('description', $p)));
            foreach (array_chunk($reindex, 500) as $chunk) {
                Work::whereIn('id', $chunk)->get()->searchable();
            }
            $this->info('Reindexed ' . count($reindex) . ' works in the search index.');
        }

        $this->table(['outcome', 'fields'], collect($counts)->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->info(($apply ? 'Updated ' : 'Would update ') . count($updates) . ' works.');
        foreach ($problems as $line) {
            $this->warn($line);
        }

        return 0;
    }

    /**
     * The value to write and how it was reached: «replaced» when the site has the old release's value, «patched»
     * when only the changed stretches could be placed, «already» when it has the new value, otherwise «conflict».
     *
     * @return array{0: mixed, 1: string}
     */
    private function resolve(string $field, ?string $current, array $change): array
    {
        if ($field === 'bibliography') {
            $have = json_decode($current ?? 'null', true) ?? [];
            $new = $change['new'] ?: null;
            return match (true) {
                $have === ($change['new'] ?? []) => [null, 'already'],
                $have === ($change['old'] ?? []) => [$new ? json_encode($new, JSON_UNESCAPED_UNICODE) : null, 'replaced'],
                default => [null, 'conflict'],
            };
        }

        if ($current === $change['new']) {
            return [null, 'already'];
        }
        if ($current === $change['old']) {
            return [$change['new'], 'replaced'];
        }
        $value = $current ?? '';
        $patches = $change['patches'] ?? [];
        if ($patches && collect($patches)->every(fn ($p) => ! str_contains($value, $p['old']) && substr_count($value, $p['new']) === 1)) {
            return [null, 'already'];
        }
        foreach ($patches as $patch) {
            if (substr_count($value, $patch['old']) !== 1) {
                return [null, 'conflict'];
            }
            $value = str_replace($patch['old'], $patch['new'], $value);
        }

        return $patches ? [$value, 'patched'] : [null, 'conflict'];
    }
}
