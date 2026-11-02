<?php

namespace App\Console\Commands;

use App\Models\Cataloger;
use App\Models\CatalogVolumePart;
use App\Sources\SourceEntrySync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A catalogue given the volumes its copies cite (step 14.15). The site may hold a catalogue of five volumes as one
 * («فهرست نسخه‌های عکسی کتابخانه مرعشی»: one volume, «جلد 5», with MTIF's volume 1 and the copies of all five), or
 * two volumes both numbered 1 (Senate). The data file gives each volume as Fankha's table and MTIF have it
 * ({sources: [{code, volumes: [{match?, number, title, mtif?, pages?, year?, citation_pattern?, people: [{name, mtif?,
 * role}]}]}]}): a volume of the catalogue whose title is «match» takes those values, or the one volume with that
 * number, or else a volume is made. Its people are the file's. Then a copy on the catalogue whose Fankha entry cites
 * one of these volumes goes to it, with its cataloger, and so does its legacy reference; its entry and part follow. A
 * copy already on a volume whose people changed takes its cataloger too, unless it is of a part or of those people.
 *
 * Found by source code, volume title and cataloger name or MTIF id, never by local id. A cataloger the file names
 * must be there. A backup of every row it changes is written first; --revert restores it and removes what it made.
 */
class SetCatalogueVolumesCommand extends Command
{
    protected $signature = 'catalogs:set-volumes
        {--data= : The data file (database/data/catalogue_volumes_*.json)}
        {--dry-run : Report what would change without saving}
        {--backup= : Where to write the backup of the rows changed (default storage/app/private/backups/)}
        {--revert= : Restore the rows of a backup this command wrote}';

    protected $description = 'Give a catalogue the volumes its copies cite, each with its MTIF record and people, and move the copies to them';

    private const VALUES = ['volume_number', 'title', 'mtif_entry_id', 'pages_count', 'publication_year', 'citation_pattern'];

    public function handle(): int
    {
        if ($file = $this->option('revert')) {
            return $this->revert($file);
        }
        $dry = (bool) $this->option('dry-run');
        if ($dry) {
            $this->warn('Dry run: nothing is saved.');
        }
        $path = (string) $this->option('data');
        $data = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        if (! isset($data['sources'])) {
            $this->error("{$path}: no data file with «sources».");

            return self::FAILURE;
        }

        $plans = [];
        foreach ($data['sources'] as $spec) {
            $plan = $this->plan($spec);
            if ($plan === null) {
                return self::FAILURE;
            }
            $plans[] = $plan;
        }
        $changes = array_sum(array_map(fn ($p) => count($p['volumes']) + count($p['moves']), $plans));
        if (! $changes) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }
        if ($dry) {
            return self::SUCCESS;
        }

        $this->info('Backup: ' . $this->backup($plans));
        DB::transaction(function () use (&$plans) {
            foreach ($plans as &$plan) {
                $this->apply($plan);
            }
        });
        foreach ($plans as $plan) {
            self::forget($plan['source']->id);
        }
        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * What a source's spec changes: [source, volumes => [[id or null, number, values, people]], moves => [[copy, number]]];
     * null when a volume or a cataloger it names is not found.
     */
    private function plan(array $spec): ?array
    {
        $source = DB::table('sources')->where('code', $spec['code'])->first();
        if (! $source) {
            $this->error("No source «{$spec['code']}».");

            return null;
        }
        $have = DB::table('source_volumes')->where('source_id', $source->id)->orderBy('id')->get();
        $this->line(sprintf('«%s» (#%d): %d volumes', $source->title, $source->id, $have->count()));
        $volumes = [];
        $taken = [];
        foreach ($spec['volumes'] as $v) {
            // a volume renamed by an earlier run is found by the title it took
            $match = isset($v['match']) ? $have->whereIn('title', array_unique([$v['match'], $v['title']])) : $have->where('volume_number', $v['number']);
            if ($match->count() > 1 || (isset($v['match']) && $match->isEmpty())) {
                $this->error(sprintf('  volume %d: %d volumes match «%s».', $v['number'], $match->count(), $v['match'] ?? $v['number']));

                return null;
            }
            $row = $match->first();
            if ($row && in_array($row->id, $taken, true)) {
                $this->error("  volume {$v['number']}: #{$row->id} is taken by another volume of the file.");

                return null;
            }
            $row && $taken[] = $row->id;
            $values = array_filter(['volume_number' => $v['number'], 'title' => $v['title'], 'mtif_entry_id' => $v['mtif'] ?? null, 'pages_count' => $v['pages'] ?? null,
                'publication_year' => $v['year'] ?? null, 'citation_pattern' => $v['citation_pattern'] ?? null], fn ($x) => $x !== null);
            $people = [];
            foreach ($v['people'] ?? [] as $p) {
                $id = isset($p['mtif']) ? DB::table('catalogers')->where('mtif_entry_id', $p['mtif'])->value('id') : null;
                $id ??= DB::table('catalogers')->where('name', $p['name'])->value('id');
                if (! $id) {
                    $this->error("  volume {$v['number']}: no cataloger «{$p['name']}».");

                    return null;
                }
                $people[] = [(int) $id, $p['role'] ?? 'cataloger'];
            }
            $differ = ! $row || collect($values)->contains(fn ($x, $column) => (string) $row->{$column} !== (string) $x)
                || DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $row->id)->orderBy('id')->get(['cataloger_id', 'role'])
                    ->map(fn ($r) => [(int) $r->cataloger_id, $r->role])->sort()->values()->all() !== collect($people)->sort()->values()->all();
            if ($differ) {
                $volumes[] = [$row?->id, $v['number'], $values, $people];
                $this->line(sprintf('  volume %d: %s', $v['number'], $row ? "#{$row->id} «{$row->title}» → «{$v['title']}»" : "made, «{$v['title']}»"));
            }
        }

        // the copies on the catalogue whose entry cites one of the file's volumes, on another volume
        $numbers = array_column($spec['volumes'], 'number');
        $byNumber = $have->groupBy('volume_number');
        $moves = [];
        $counts = [];
        DB::table('manuscripts as m')->join('source_entries as e', fn ($j) => $j->on('e.legacy_id', '=', 'm.id')->where('e.legacy_type', 'fankha_citation'))
            ->whereIn('m.catalog_volume_id', $have->pluck('id')->all() ?: [0])->whereIn('e.volume_number', $numbers)
            ->orderBy('m.id')->get(['m.id', 'm.catalog_volume_id', 'e.volume_number'])
            ->each(function ($m) use (&$moves, &$counts, $spec, $have) {
                $target = $this->target($spec, $have, (int) $m->volume_number);
                if ($target === null || $target !== (int) $m->catalog_volume_id) {
                    $moves[] = [(int) $m->id, (int) $m->volume_number];
                    $label = sprintf('volume %d ← #%d', $m->volume_number, $m->catalog_volume_id);
                    $counts[$label] = ($counts[$label] ?? 0) + 1;
                }
            });
        ksort($counts, SORT_NATURAL);
        foreach ($counts as $label => $n) {
            $this->line(sprintf('  %s: %d copies', $label, $n));
        }

        return ['source' => $source, 'volumes' => $volumes, 'moves' => $moves, 'spec' => $spec];
    }

    /** The id of the volume the file numbers so on the catalogue as it is now, or null when it is to be made. */
    private function target(array $spec, $have, int $number): ?int
    {
        $v = collect($spec['volumes'])->firstWhere('number', $number);
        $row = isset($v['match']) ? $have->whereIn('title', array_unique([$v['match'], $v['title']]))->first() : $have->where('volume_number', $number)->first();

        return $row ? (int) $row->id : null;
    }

    private function apply(array &$plan): void
    {
        $source = $plan['source'];
        $first = DB::table('catalog_volumes')->whereIn('id', DB::table('source_volumes')->where('source_id', $source->id)->select('id'))->orderBy('id')->first();
        $ids = [];
        $before = DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', DB::table('source_volumes')->where('source_id', $source->id)->select('id'))->pluck('cataloger_id');
        foreach ($plan['volumes'] as [$id, $number, $values, $people]) {
            if ($id === null) {
                $id = DB::table('catalog_volumes')->insertGetId($values + ['library_id' => $first?->library_id, 'catalog_id' => $first?->catalog_id ?? 1,
                    'publisher' => $first?->publisher, 'manuscripts_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('source_volumes')->insert($values + ['id' => $id, 'source_id' => $source->id, 'publisher' => $first?->publisher, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('source_volumes')->where('id', $id)->update($values + ['updated_at' => now()]);
                DB::table('catalog_volumes')->where('id', $id)->update($values + ['updated_at' => now()]);
            }
            DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $id)->delete();
            foreach ($people as [$cataloger, $role]) {
                DB::table('catalog_volume_cataloger')->insert(['catalog_volume_id' => $id, 'cataloger_id' => $cataloger, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
            }
            $ids[$number] = (int) $id;
        }
        $volumes = DB::table('source_volumes')->where('source_id', $source->id)->get(['id', 'volume_number']);
        foreach (collect($plan['spec']['volumes'])->pluck('number') as $number) {
            $ids[$number] ??= (int) $volumes->firstWhere('volume_number', $number)->id;
        }
        $catalogers = CatalogVolumePart::primaryCatalogers(array_values($ids));
        $copies = [];
        foreach (collect($plan['moves'])->groupBy(1) as $number => $group) {
            $to = $ids[$number];
            foreach ($group->pluck(0)->chunk(1000) as $chunk) {
                $old = DB::table('manuscripts')->whereIn('id', $chunk->all())->pluck('catalog_volume_id')->unique()->all();
                DB::table('manuscripts')->whereIn('id', $chunk->all())->update(['catalog_volume_id' => $to, 'cataloger_id' => $catalogers[$to] ?? null]);
                DB::table('manuscript_catalog_references')->whereIn('manuscript_id', $chunk->all())->whereIn('catalog_volume_id', $old)->update(['catalog_volume_id' => $to]);
                $copies = array_merge($copies, $chunk->all());
            }
        }
        // the copies of a volume whose people the file set are of its cataloger, unless they are of one of its people
        // already or of a part
        foreach ($plan['volumes'] as [, $number, , $people]) {
            $id = $ids[$number];
            if (isset($catalogers[$id])) {
                DB::table('manuscripts')->where('catalog_volume_id', $id)->whereNull('catalog_volume_part_id')
                    ->where(fn ($q) => $q->whereNull('cataloger_id')->orWhereNotIn('cataloger_id', array_column($people, 0)))
                    ->update(['cataloger_id' => $catalogers[$id]]);
            }
        }
        foreach (array_chunk($copies, 1000) as $chunk) {
            SourceEntrySync::syncCited(DB::table('manuscripts')->whereIn('id', $chunk)->get(['id', 'metadata', 'raw_text', 'catalog_volume_id']));
        }
        $all = $volumes->pluck('id')->all();
        foreach ($all as $id) {
            DB::table('catalog_volumes')->where('id', $id)->update(['manuscripts_count' => DB::table('manuscripts')->where('catalog_volume_id', $id)->count()]);
        }
        DB::table('sources')->where('id', $source->id)->update(['volumes_count' => count($all), 'updated_at' => now()]);
        CatalogVolumePart::recount(DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $all)->pluck('id')->all());
        Cataloger::recount($before->merge(DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $all)->pluck('cataloger_id'))->unique());
        $this->info(sprintf('«%s»: %d volumes set, %d copies moved.', $source->title, count($plan['volumes']), count($copies)));
    }

    /** Writes every row the run may change to a JSON file and returns its path. */
    private function backup(array $plans): string
    {
        $sources = array_map(fn ($p) => $p['source']->id, $plans);
        $volumes = DB::table('source_volumes')->whereIn('source_id', $sources)->pluck('id')->all();
        $copies = DB::table('manuscripts')->whereIn('catalog_volume_id', $volumes)->pluck('id')->all();
        $chunked = fn ($table, $column, $ids, $columns) => collect(array_chunk($ids, 1000))->flatMap(fn ($c) => DB::table($table)->whereIn($column, $c)->get($columns))->all();
        $people = DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->pluck('cataloger_id')
            ->merge(collect($plans)->flatMap(fn ($p) => collect($p['volumes'])->flatMap(fn ($v) => array_column($v[3], 0))))
            ->merge(DB::table('catalog_volume_part_cataloger')->whereIn('catalog_volume_part_id', DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $volumes)->select('id'))->pluck('cataloger_id'))
            ->unique()->values()->all();
        $backup = [
            'command' => $this->getName(),
            'written_at' => now()->toIso8601String(),
            'sources' => DB::table('sources')->whereIn('id', $sources)->get(['id', 'volumes_count', 'updated_at']),
            'source_volumes' => DB::table('source_volumes')->whereIn('id', $volumes)->get(),
            'catalog_volumes' => DB::table('catalog_volumes')->whereIn('id', $volumes)->get(),
            'catalog_volume_cataloger' => DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->get(),
            'catalog_volume_parts' => DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $volumes)->get(['id', 'manuscripts_count']),
            'manuscripts' => $chunked('manuscripts', 'id', $copies, ['id', 'catalog_volume_id', 'catalog_volume_part_id', 'cataloger_id']),
            'manuscript_catalog_references' => $chunked('manuscript_catalog_references', 'manuscript_id', $copies, ['id', 'catalog_volume_id']),
            'source_entries' => array_values(array_filter($chunked('source_entries', 'legacy_id', $copies, ['id', 'legacy_type', 'source_id', 'source_volume_id']),
                fn ($e) => $e->legacy_type === 'fankha_citation')),
            'catalogers' => DB::table('catalogers')->whereIn('id', $people)->get(['id', 'volumes_count', 'manuscripts_count', 'supervised_count']),
        ];
        $backup['source_entries'] = array_map(fn ($e) => collect((array) $e)->except('legacy_type')->all(), $backup['source_entries']);

        $path = $this->option('backup') ?: storage_path('app/private/backups/catalogs-set-volumes-' . now()->format('Ymd_His') . '.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($backup, JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /** Puts back every row of a backup and removes the volumes the run made. */
    private function revert(string $file): int
    {
        $backup = is_file($file) ? json_decode(file_get_contents($file), true) : null;
        if (($backup['command'] ?? null) !== $this->getName()) {
            $this->error("{$file}: not a backup of {$this->getName()}.");

            return self::FAILURE;
        }
        $sources = array_column($backup['sources'], 'id');
        $kept = array_column($backup['source_volumes'], 'id');
        $this->info(sprintf('Restoring %d sources, %d volumes and %d copies from %s', count($sources), count($kept), count($backup['manuscripts']), $file));
        DB::transaction(function () use ($backup, $sources, $kept) {
            FixMisnamedIsfahanCataloguesCommand::restoreColumns('manuscripts', $backup['manuscripts']);
            FixMisnamedIsfahanCataloguesCommand::restoreColumns('manuscript_catalog_references', $backup['manuscript_catalog_references']);
            FixMisnamedIsfahanCataloguesCommand::restoreColumns('source_entries', $backup['source_entries']);
            $made = DB::table('source_volumes')->whereIn('source_id', $sources)->whereNotIn('id', $kept)->pluck('id')->all();
            DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', array_merge($kept, $made))->delete();
            DB::table('source_volumes')->whereIn('id', $made ?: [0])->delete();
            DB::table('catalog_volumes')->whereIn('id', $made ?: [0])->delete();
            DB::table('source_volumes')->upsert($backup['source_volumes'], ['id']);
            DB::table('catalog_volumes')->upsert($backup['catalog_volumes'], ['id']);
            $backup['catalog_volume_cataloger'] && DB::table('catalog_volume_cataloger')->insert($backup['catalog_volume_cataloger']);
            foreach (['sources', 'catalog_volume_parts', 'catalogers'] as $table) {
                FixMisnamedIsfahanCataloguesCommand::restoreColumns($table, $backup[$table]);
            }
        });
        foreach ($sources as $id) {
            self::forget((int) $id);
        }
        $this->info('Restored.');

        return self::SUCCESS;
    }

    private static function forget(int $sourceId): void
    {
        Cache::forget('sources:shelf');
        Cache::forget("sources:copies:{$sourceId}");
        foreach (['works', 'manuscripts'] as $type) {
            Cache::forget('sources:linked:' . md5(implode('|', [$sourceId, $type, null, ''])));
        }
    }
}
