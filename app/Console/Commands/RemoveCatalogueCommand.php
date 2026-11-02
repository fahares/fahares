<?php

namespace App\Console\Commands;

use App\Models\Cataloger;
use App\Models\CatalogVolumePart;
use App\Sources\SourceEntrySync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A catalogue that is not a book (step 14.9) goes: its source, its volumes and their catalogers' rows. The copies
 * still on it go first to the book the data file names for each, found by library slug, shelfmark and cited page
 * («copies»: [{library, shelfmark, page, to: source code, volume}]). A copy on it that the file does not name stops the
 * run. Entries follow their copies (SourceEntrySync::syncCited), and so do their parts; counts are taken again.
 *
 * Found by source code and library slug, never by local id. A backup of every row it changes or removes is written
 * first; --revert puts them back. Safe to run again.
 */
class RemoveCatalogueCommand extends Command
{
    protected $signature = 'catalogs:remove-catalogue
        {--data= : The data file (database/data/remove_catalogue_*.json)}
        {--dry-run : Report what would change without saving}
        {--backup= : Where to write the backup of the rows changed (default storage/app/private/backups/)}
        {--revert= : Restore the rows of a backup this command wrote}';

    protected $description = 'Remove a catalogue that is not a book, its copies first taken to the books they are of';

    private bool $dry = false;

    public function handle(): int
    {
        if ($file = $this->option('revert')) {
            return $this->revert($file);
        }
        $this->dry = (bool) $this->option('dry-run');
        if ($this->dry) {
            $this->warn('Dry run: nothing is saved.');
        }
        $path = (string) $this->option('data');
        $data = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        if (! isset($data['code'])) {
            $this->error("{$path}: no data file with a «code».");

            return self::FAILURE;
        }

        $source = DB::table('sources')->where('code', $data['code'])->first();
        if (! $source) {
            $this->info("«{$data['code']}» is not there: nothing to do.");

            return self::SUCCESS;
        }
        $volumes = DB::table('source_volumes')->where('source_id', $source->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->line(sprintf('  «%s» (#%d): %d volumes', $source->title, $source->id, count($volumes)));

        $moves = $this->moves($data['copies'] ?? [], $volumes);
        if ($moves === null) {
            return self::FAILURE;
        }
        if ($this->dry) {
            return self::SUCCESS;
        }

        $this->info('Backup: ' . $this->backup($source, $volumes, $moves));
        DB::transaction(function () use ($source, $volumes, $moves) {
            $people = DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->pluck('cataloger_id');
            foreach ($moves as [$copy, $target]) {
                DB::table('manuscripts')->where('id', $copy)->update(['catalog_volume_id' => $target]);
                // the legacy references follow
                DB::table('manuscript_catalog_references')->where('manuscript_id', $copy)->whereIn('catalog_volume_id', $volumes)->update(['catalog_volume_id' => $target]);
            }
            $copies = array_column($moves, 0);
            SourceEntrySync::syncCited(DB::table('manuscripts')->whereIn('id', $copies)->get(['id', 'metadata', 'raw_text', 'catalog_volume_id']));

            DB::table('source_entries')->where('source_id', $source->id)->update(['source_id' => null, 'source_volume_id' => null]);
            DB::table('manuscript_catalog_references')->whereIn('catalog_volume_id', $volumes)->update(['catalog_volume_id' => null]);
            DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->delete();
            DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $volumes)->delete();
            DB::table('source_volumes')->whereIn('id', $volumes)->delete();
            DB::table('catalog_volumes')->whereIn('id', $volumes)->delete();
            DB::table('sources')->where('id', $source->id)->delete();

            $targets = array_unique(array_column($moves, 1));
            $this->recount($targets, $people->merge(DB::table('manuscripts')->whereIn('id', $copies)->pluck('cataloger_id'))->filter());
        });
        foreach (array_merge([$source->id], DB::table('source_volumes')->whereIn('id', array_column($moves, 1))->pluck('source_id')->all()) as $id) {
            self::forget((int) $id);
        }
        $this->info("«{$source->title}» removed.");

        return self::SUCCESS;
    }

    /**
     * The copies on the volumes and the volume each goes to: [copy id, volume id]; null when one is not named or a
     * named one is missing.
     *
     * @return array<int, array{0: int, 1: int}>|null
     */
    private function moves(array $named, array $volumes): ?array
    {
        $on = DB::table('manuscripts')->whereIn('catalog_volume_id', $volumes ?: [0])->get(['id', 'library_id', 'shelfmark', 'page_start'])->keyBy('id');
        $moves = [];
        foreach ($named as $copy) {
            $library = DB::table('libraries')->where('slug', $copy['library'])->value('id');
            $target = DB::table('source_volumes')->where('source_id', DB::table('sources')->where('code', $copy['to'])->value('id'))
                ->where('volume_number', $copy['volume'])->value('id');
            $found = $on->first(fn ($m) => (int) $m->library_id === (int) $library && (string) $m->shelfmark === (string) $copy['shelfmark']
                && (int) DB::table('manuscript_catalog_references')->where('manuscript_id', $m->id)->whereIn('catalog_volume_id', $volumes)->value('page_start') === (int) $copy['page']);
            if (! $found || ! $target) {
                $this->error(sprintf('Not found: copy %s of %s (p. %d) or «%s» v. %d.', $copy['shelfmark'], $copy['library'], $copy['page'], $copy['to'], $copy['volume']));

                return null;
            }
            $moves[] = [(int) $found->id, (int) $target];
            $this->line(sprintf('  copy #%d (%s, p. %d) → «%s» v. %d (#%d)', $found->id, $copy['shelfmark'], $copy['page'], $copy['to'], $copy['volume'], $target));
        }
        $unnamed = $on->keys()->diff(array_column($moves, 0));
        if ($unnamed->isNotEmpty()) {
            $this->error('Copies on the catalogue the data file does not name: #' . $unnamed->implode(', #'));

            return null;
        }

        return $moves;
    }

    /** The copies of the volumes and parts the copies went to, and the catalogers of them. */
    private function recount(array $volumes, iterable $people): void
    {
        foreach ($volumes as $id) {
            DB::table('catalog_volumes')->where('id', $id)->update(['manuscripts_count' => DB::table('manuscripts')->where('catalog_volume_id', $id)->count()]);
        }
        $parts = DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $volumes ?: [0])->pluck('id')->all();
        CatalogVolumePart::recount($parts);
        Cataloger::recount(collect($people)->merge(DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes ?: [0])->pluck('cataloger_id'))
            ->merge(DB::table('catalog_volume_part_cataloger')->whereIn('catalog_volume_part_id', $parts ?: [0])->pluck('cataloger_id')));
    }

    /** Writes every row the run may change or remove to a JSON file and returns its path. */
    private function backup(object $source, array $volumes, array $moves): string
    {
        $copies = array_column($moves, 0);
        $targets = array_values(array_unique(array_column($moves, 1)));
        $parts = DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $targets ?: [0])->pluck('id')->all();
        $people = DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', array_merge($volumes, $targets))->pluck('cataloger_id')
            ->merge(DB::table('catalog_volume_part_cataloger')->whereIn('catalog_volume_part_id', $parts ?: [0])->pluck('cataloger_id'))
            ->merge(DB::table('manuscripts')->whereIn('id', $copies ?: [0])->pluck('cataloger_id'))->filter()->unique()->values()->all();
        $backup = [
            'command' => $this->getName(),
            'written_at' => now()->toIso8601String(),
            'source' => $source,
            'source_volumes' => DB::table('source_volumes')->whereIn('id', $volumes)->get(),
            'catalog_volumes' => DB::table('catalog_volumes')->whereIn('id', $volumes)->get(),
            'catalog_volume_cataloger' => DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->get(),
            'catalog_volume_parts' => DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $volumes)->get(),
            'target_volumes' => DB::table('catalog_volumes')->whereIn('id', $targets)->get(['id', 'manuscripts_count']),
            'target_parts' => DB::table('catalog_volume_parts')->whereIn('id', $parts)->get(['id', 'manuscripts_count']),
            'manuscripts' => DB::table('manuscripts')->whereIn('id', $copies)->get(['id', 'catalog_volume_id', 'catalog_volume_part_id', 'cataloger_id']),
            'source_entries' => DB::table('source_entries')->where('source_id', $source->id)
                ->orWhere(fn ($q) => $q->where('legacy_type', 'fankha_citation')->whereIn('legacy_id', $copies ?: [0]))
                ->get(['id', 'source_id', 'source_volume_id', 'volume_number', 'page_start']),
            'manuscript_catalog_references' => DB::table('manuscript_catalog_references')->whereIn('catalog_volume_id', $volumes)->get(['id', 'catalog_volume_id']),
            'catalogers' => DB::table('catalogers')->whereIn('id', $people)->get(['id', 'volumes_count', 'manuscripts_count', 'supervised_count']),
        ];

        $path = $this->option('backup') ?: storage_path('app/private/backups/catalogs-remove-catalogue-' . now()->format('Ymd_His') . '.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($backup, JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /** Puts back the catalogue and every row of a backup. */
    private function revert(string $file): int
    {
        $backup = is_file($file) ? json_decode(file_get_contents($file), true) : null;
        if (($backup['command'] ?? null) !== $this->getName()) {
            $this->error("{$file}: not a backup of {$this->getName()}.");

            return self::FAILURE;
        }
        $this->info(sprintf('Restoring «%s» and %d copies from %s', $backup['source']['title'], count($backup['manuscripts']), $file));
        DB::transaction(function () use ($backup) {
            DB::table('sources')->upsert([$backup['source']], ['id']);
            foreach (['catalog_volumes', 'source_volumes', 'catalog_volume_cataloger', 'catalog_volume_parts'] as $table) {
                $backup[$table] && DB::table($table)->upsert($backup[$table], ['id']);
            }
            foreach (['manuscripts', 'source_entries', 'manuscript_catalog_references', 'catalogers'] as $table) {
                FixMisnamedIsfahanCataloguesCommand::restoreColumns($table, $backup[$table]);
            }
            FixMisnamedIsfahanCataloguesCommand::restoreColumns('catalog_volumes', $backup['target_volumes']);
            FixMisnamedIsfahanCataloguesCommand::restoreColumns('catalog_volume_parts', $backup['target_parts']);
        });
        self::forget((int) $backup['source']['id']);
        foreach (array_column($backup['target_volumes'], 'id') as $id) {
            self::forget((int) DB::table('source_volumes')->where('id', $id)->value('source_id'));
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
