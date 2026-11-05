<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Cataloger;
use App\Models\CatalogVolumePart;
use App\Models\Manuscript;
use App\Sources\SourceEntrySync;
use App\Suggestions\ProtectedColumns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Copies' catalogue citations Fankha printed «page-volume» (step 14.12, item 112), put right in the corpus text by
 * `fankha_reversed_copy_citations.py`, which writes the decision file: each copy by id and shelfmark, its citation
 * and raw text as the site had them and as they are now. A copy is written only when its shelfmark is unchanged,
 * both fields still hold the old value and no reviewed correction protects them. Then its legacy reference and
 * Fankha entry take the volume and page. A copy on another volume of its catalogue than the one its citation now
 * names goes to that volume and its cataloger, when the catalogue has it; its part follows the page
 * (SourceEntrySync::syncCited). A copy whose catalogue lacks that volume is reported, not moved. A copy on no volume
 * goes to the one the file's «tie» names ({source code, volume number}; step 14.41). The file's «category» is the
 * audit rows' (default reversed_citation).
 *
 * Without --apply it only reports. A backup of every row it changes is written first; --revert restores it.
 */
class FixReversedCitationsCommand extends Command
{
    protected $signature = 'fahares:fix-reversed-citations
        {--file= : Decision file (database/data/fankha_reversed_citations_step14_12.json)}
        {--apply : Write the changes; without it the command only reports what it would do}
        {--backup= : Where to write the backup of the rows changed (default storage/app/private/backups/)}
        {--revert= : Restore the rows of a backup this command wrote}';

    protected $description = 'Put back as «volume-page» the copies\' catalogue citations Fankha printed «page-volume»';

    public function handle(): int
    {
        if ($file = $this->option('revert')) {
            return $this->revert($file);
        }
        $path = $this->option('file') ?: database_path('data/fankha_reversed_citations_step14_12.json');
        $plan = File::exists($path) ? json_decode(File::get($path), true) : null;
        if (! isset($plan['records'])) {
            $this->error("Decision file missing or invalid: {$path}");

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $guard = ProtectedColumns::for(Manuscript::class);
        $rows = DB::table('manuscripts')->whereIn('id', array_column($plan['records'], 'id'))->get(['id', 'shelfmark', 'raw_text', 'metadata'])->keyBy('id');

        $counts = [];
        $writes = [];
        foreach ($plan['records'] as $rec) {
            $outcome = $this->check($rec, $rows[$rec['id']] ?? null, $guard);
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            if ($outcome === 'write') {
                $writes[] = $rec;
            } elseif ($outcome !== 'already') {
                $this->warn("#{$rec['id']}: {$outcome}");
            }
        }
        $this->table(['outcome', 'copies'], collect($counts)->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->info(($apply ? 'Writing ' : 'Would write ') . count($writes) . ' copies.');
        if (! $apply || ! $writes) {
            return self::SUCCESS;
        }

        $ids = array_column($writes, 'id');
        // a copy on no volume the file ties to one: {source code, volume number}
        $ties = [];
        foreach ($writes as $rec) {
            if (isset($rec['tie'])) {
                $volume = DB::table('source_volumes')->where('source_id', DB::table('sources')->where('code', $rec['tie']['source'])->value('id'))
                    ->where('volume_number', $rec['tie']['volume'])->pluck('id');
                $volume->count() === 1 ? $ties[$rec['id']] = (int) $volume->first() : $this->warn("#{$rec['id']}: no one volume {$rec['tie']['volume']} of {$rec['tie']['source']}");
            }
        }
        $backup = $this->backup($ids, array_values($ties));
        $this->info("Backup: {$backup}");
        $note = basename($path);
        $logs = [];
        $category = $plan['category'] ?? 'reversed_citation';
        DB::transaction(function () use ($writes, $rows, $note, $ids, $ties, $category, &$logs) {
            $before = $this->people($ids);
            foreach ($writes as $rec) {
                $row = $rows[$rec['id']];
                $meta = json_decode($row->metadata ?? '{}', true) ?: [];
                $meta['catalog_citation'] = $rec['changes']['catalog_citation']['to'];
                $new = ['raw_text' => $rec['changes']['raw_text']['to'], 'metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE)];
                DB::table('manuscripts')->where('id', $rec['id'])->update($new + ['updated_at' => now()]);

                $parsed = ImportFankhaCatalogReferencesCommand::parse($meta['catalog_citation']);
                DB::table('manuscript_catalog_references')->where('manuscript_id', $rec['id'])->where('source', 'fankha')
                    ->where('raw_text', $rec['changes']['catalog_citation']['from'])
                    ->update(['raw_text' => $meta['catalog_citation'], 'volume_number' => $parsed['volume'], 'page_start' => $parsed['page'], 'updated_at' => now()]);

                $logs[] = AuditLog::create([
                    'user_id' => null,
                    'auditable_type' => Manuscript::class,
                    'auditable_id' => $rec['id'],
                    'action' => 'corpus_update',
                    'old_values' => ['raw_text' => $row->raw_text, 'catalog_citation' => $rec['changes']['catalog_citation']['from']],
                    'new_values' => ['raw_text' => $new['raw_text'], 'catalog_citation' => $meta['catalog_citation'], 'corpus_fix' => $note, 'category' => $category],
                ])->id;
            }
            $moved = array_merge($this->move($ids), $this->tie($ties));
            SourceEntrySync::syncCited(DB::table('manuscripts')->whereIn('id', $ids)->get(['id', 'metadata', 'raw_text', 'catalog_volume_id']));
            foreach ($moved as $id) {
                DB::table('catalog_volumes')->where('id', $id)->update(['manuscripts_count' => DB::table('manuscripts')->where('catalog_volume_id', $id)->count()]);
            }
            CatalogVolumePart::recount(DB::table('catalog_volume_parts')->whereIn('id', array_merge($before['parts'], $this->people($ids)['parts']))->pluck('id')->all());
            Cataloger::recount(array_merge($before['catalogers'], $this->people($ids)['catalogers']));
        });
        $this->addLogs($backup, $logs);
        // DB::table runs no model hook: the search documents follow here
        Manuscript::whereIn('id', $ids)->searchable();

        $elsewhere = DB::table('source_entries as e')->join('source_volumes as v', 'v.id', '=', 'e.source_volume_id')
            ->where('e.legacy_type', 'fankha_citation')->whereIn('e.legacy_id', $ids)->whereColumn('v.volume_number', '!=', 'e.volume_number')
            ->get(['e.legacy_id', 'e.citation_text', 'v.volume_number']);
        foreach ($elsewhere as $e) {
            $this->line(sprintf('  #%d %s: on volume %d', $e->legacy_id, $e->citation_text, $e->volume_number));
        }
        $this->info(sprintf('Done: %d copies; %d on another volume than their citation names.', count($ids), $elsewhere->count()));

        return self::SUCCESS;
    }

    /**
     * A copy on another volume of its catalogue than the one its citation now names goes to that volume, with its
     * cataloger, when the catalogue has it once and the citation names one volume. Returns the volumes it touched.
     */
    private function move(array $ids): array
    {
        $copies = DB::table('manuscripts as m')->join('source_volumes as v', 'v.id', '=', 'm.catalog_volume_id')
            ->whereIn('m.id', $ids)->get(['m.id', 'm.metadata', 'm.catalog_volume_id', 'v.source_id', 'v.volume_number']);
        $moves = [];
        foreach ($copies as $m) {
            $citation = json_decode($m->metadata ?? '{}', true)['catalog_citation'] ?? '';
            $cited = ImportFankhaCatalogReferencesCommand::parse($citation)['volume'];
            if ($cited === null || (int) $cited === (int) $m->volume_number || preg_match('/\s+و\s+/u', $citation)) {
                continue;
            }
            $targets = DB::table('source_volumes')->where('source_id', $m->source_id)->where('volume_number', $cited)->pluck('id');
            if ($targets->count() === 1) {
                $moves[$m->id] = [(int) $m->catalog_volume_id, (int) $targets->first()];
            }
        }
        $catalogers = CatalogVolumePart::primaryCatalogers(array_values(array_unique(array_column($moves, 1))));
        foreach ($moves as $id => [$from, $to]) {
            DB::table('manuscripts')->where('id', $id)->update(['catalog_volume_id' => $to, 'cataloger_id' => $catalogers[$to] ?? null]);
            DB::table('manuscript_catalog_references')->where('manuscript_id', $id)->where('catalog_volume_id', $from)->update(['catalog_volume_id' => $to]);
        }
        $this->info(sprintf('%d copies moved to the volume their citation names.', count($moves)));

        return array_values(array_unique(array_merge(...array_values($moves) ?: [[]])));
    }

    /** The copies on no volume go to the volume the file names for them, with its cataloger. Returns the volumes. */
    private function tie(array $ties): array
    {
        $catalogers = CatalogVolumePart::primaryCatalogers(array_values(array_unique($ties)));
        $done = [];
        foreach ($ties as $id => $volume) {
            if (DB::table('manuscripts')->where('id', $id)->whereNull('catalog_volume_id')->update(['catalog_volume_id' => $volume, 'cataloger_id' => $catalogers[$volume] ?? null])) {
                DB::table('manuscript_catalog_references')->where('manuscript_id', $id)->where('source', 'fankha')->whereNull('catalog_volume_id')->update(['catalog_volume_id' => $volume]);
                $done[] = $volume;
            }
        }
        $this->info(sprintf('%d copies on no volume tied to the one their citation names.', count($done)));

        return array_values(array_unique($done));
    }

    private function check(array $rec, ?object $row, ProtectedColumns $guard): string
    {
        if (! $row) {
            return 'copy gone (merged or deleted)';
        }
        if ($row->shelfmark !== $rec['shelfmark']) {
            return 'shelfmark differs';
        }
        $current = ['catalog_citation' => json_decode($row->metadata ?? '{}', true)['catalog_citation'] ?? null, 'raw_text' => $row->raw_text];
        if ($current === array_map(fn ($c) => $c['to'], $rec['changes'])) {
            return 'already';
        }
        if ($current !== array_map(fn ($c) => $c['from'], $rec['changes'])) {
            return 'changed on the site since the export';
        }
        $fields = ['raw_text' => true, 'metadata' => true];
        if (array_diff_key($fields, $guard->strip($rec['id'], $fields))) {
            return 'protected by a reviewed correction';
        }

        return 'write';
    }

    /** The parts and catalogers the copies are of: their counts are taken again. */
    private function people(array $ids): array
    {
        $parts = DB::table('manuscripts')->whereIn('id', $ids)->whereNotNull('catalog_volume_part_id')->distinct()->pluck('catalog_volume_part_id')->all();

        return [
            'parts' => $parts,
            'catalogers' => DB::table('manuscripts')->whereIn('id', $ids)->whereNotNull('cataloger_id')->distinct()->pluck('cataloger_id')
                ->merge(DB::table('catalog_volume_part_cataloger')->whereIn('catalog_volume_part_id', $parts ?: [0])->pluck('cataloger_id'))->unique()->values()->all(),
        ];
    }

    /** Writes every row the run may change to a JSON file and returns its path. */
    private function backup(array $ids, array $ties = []): string
    {
        $people = $this->people($ids);
        // every volume of the copies' catalogues, and of those the file ties copies to: a copy may move to another
        $volumes = DB::table('source_volumes')->whereIn('source_id', DB::table('source_volumes')
            ->whereIn('id', DB::table('manuscripts')->whereIn('id', $ids)->whereNotNull('catalog_volume_id')->select('catalog_volume_id'))->select('source_id'))
            ->orWhereIn('source_id', DB::table('source_volumes')->whereIn('id', $ties ?: [0])->select('source_id'))->pluck('id')->all();
        $backup = [
            'command' => $this->getName(),
            'written_at' => now()->toIso8601String(),
            'manuscripts' => DB::table('manuscripts')->whereIn('id', $ids)->get(['id', 'raw_text', 'metadata', 'catalog_volume_id', 'catalog_volume_part_id', 'cataloger_id']),
            'manuscript_catalog_references' => DB::table('manuscript_catalog_references')->whereIn('manuscript_id', $ids)->where('source', 'fankha')
                ->get(['id', 'raw_text', 'volume_number', 'page_start', 'catalog_volume_id']),
            'source_entries' => DB::table('source_entries')->where('legacy_type', 'fankha_citation')->whereIn('legacy_id', $ids)
                ->get(['id', 'citation_text', 'volume_number', 'page_start', 'source_id', 'source_volume_id']),
            // a copy moves within its catalogue, or to the one the file ties it to
            'catalog_volumes' => DB::table('catalog_volumes')->whereIn('id', $volumes ?: [0])->get(['id', 'manuscripts_count']),
            // the parts a copy may move to are of its own volume
            'catalog_volume_parts' => DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $volumes)->get(['id', 'manuscripts_count']),
            'catalogers' => DB::table('catalogers')->whereIn('id', DB::table('catalog_volume_part_cataloger')->whereIn('catalog_volume_part_id',
                DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $volumes)->select('id'))->select('cataloger_id'))
                ->orWhereIn('id', DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->select('cataloger_id'))
                ->orWhereIn('id', $people['catalogers'])->get(['id', 'volumes_count', 'manuscripts_count', 'supervised_count']),
            'audit_logs' => [],
        ];

        $path = $this->option('backup') ?: storage_path('app/private/backups/fahares-fix-reversed-citations-' . now()->format('Ymd_His') . '.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($backup, JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /** The audit rows the run wrote, kept in its backup so a revert takes them away too. */
    private function addLogs(string $path, array $logs): void
    {
        $backup = json_decode(file_get_contents($path), true);
        $backup['audit_logs'] = $logs;
        file_put_contents($path, json_encode($backup, JSON_UNESCAPED_UNICODE));
    }

    /** Puts back every row of a backup. */
    private function revert(string $file): int
    {
        $backup = is_file($file) ? json_decode(file_get_contents($file), true) : null;
        if (($backup['command'] ?? null) !== $this->getName()) {
            $this->error("{$file}: not a backup of {$this->getName()}.");

            return self::FAILURE;
        }
        $this->info(sprintf('Restoring %d copies from %s', count($backup['manuscripts']), $file));
        DB::transaction(function () use ($backup) {
            foreach (['manuscripts', 'manuscript_catalog_references', 'source_entries', 'catalog_volumes', 'catalog_volume_parts', 'catalogers'] as $table) {
                foreach ($backup[$table] as $row) {
                    DB::table($table)->where('id', $row['id'])->update(collect($row)->except('id')->all());
                }
            }
            DB::table('audit_logs')->whereIn('id', $backup['audit_logs'] ?: [0])->delete();
        });
        Manuscript::whereIn('id', array_column($backup['manuscripts'], 'id'))->searchable();
        $this->info('Restored.');

        return self::SUCCESS;
    }
}
