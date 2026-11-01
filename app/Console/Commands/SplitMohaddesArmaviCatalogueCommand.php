<?php

namespace App\Console\Commands;

use App\Console\Commands\ImportFankhaCatalogReferencesCommand as References;
use App\Models\Source;
use App\Sources\SourceEntrySync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Markaz-e Ehya's handwritten catalogue of the Mohaddes Armavi collection («فهرست محدث ارموی - مرکز احیاء التراث
 * العربي», three volumes in Fankha's table, rows 395–397) was one volume (step 13.127): «… - جلد سوم», numbered 1,
 * holding 3,555 copies, of which 1,208 cite «محدث ارموی مخ: 1 - P» and 1,299 «… مخ: 2 - P».
 *
 *  - The catalogue gets volumes 1 and 2, the one it had becoming 3, each with its pages (one run of pages, 1-537,
 *    539-1087, 1089-1691), numbers and the catalogers of the one it had (Ja'far Hosseini Eshkevari, Mostafa Derayati).
 *    It is not printed: MTIF has no record of it (its «مجموعه محدث ارموی» records are volumes 6 to 8 of Ehya's printed
 *    catalogue).
 *  - Each Ehya copy whose first citation is «محدث ارموی مخ: V» («محدث ارموی ف مخ», «محدث ارموی - مخ», «محدث ارموی»)
 *    for V from 1 to 3 goes to volume V, wherever it was, or none.
 *  Entries follow their copies (SourceEntrySync::syncCited); volume and cataloger counts are taken again.
 *
 * A citation with no volume or a page for one («مخ: 1145»), or «محدث ارموی ف: 1» (Ehya's printed catalogue), is left
 * where it is. Found by source code, library and citation pattern, never by local id. A backup of every row it
 * changes is written first; --revert restores it, removing the volumes it made. Safe to run again.
 */
class SplitMohaddesArmaviCatalogueCommand extends Command
{
    protected $signature = 'sources:split-mohaddes-armavi
        {--dry-run : Report what would change without saving}
        {--backup= : Where to write the backup of the rows changed (default storage/app/private/backups/)}
        {--revert= : Restore the rows of a backup this command wrote}';

    protected $description = 'Give the Mohaddes Armavi catalogue its three volumes and the copies of each';

    public const CODE = 'catalogue-b54dee88e953';

    public const TITLE = 'فهرست محدث ارموی - مرکز احیاء التراث العربي';

    /** Each volume: its name, pages, first and last page and manuscripts (Fankha's table). */
    public const VOLUMES = [
        1 => ['اول', 537, 1, 537, '۱-۵۰۰'],
        2 => ['دوم', 549, 539, 1087, '۵۰۱-۱۰۰۰'],
        3 => ['سوم', 603, 1089, 1691, '۱۰۰۱-۱۵۰۰'],
    ];

    /** The series its copies are cited by, dashes and spaces aside. */
    public const SERIES = ['محدث ارموی مخ', 'محدث ارموی ف مخ', 'محدث ارموی', 'محدث ارموی مرکز احیاء میراث اسلامی'];

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

        $source = FillEmptyCataloguesCommand::source(self::CODE);
        if (! $source) {
            $this->error('The source is missing: ' . self::CODE);

            return self::FAILURE;
        }

        $volumes = $this->volumes($source);
        $moves = $this->moves($source);
        if (! $volumes && ! $moves) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }
        if ($this->dry) {
            return self::SUCCESS;
        }

        $this->info('Backup: ' . $this->backup($source, $moves));
        DB::transaction(function () use ($source, $volumes) {
            $this->writeVolumes($source, $volumes);
            // the volumes the moves go to exist now
            $moves = $this->moves($source, false);
            foreach (collect($moves)->groupBy(fn ($m) => $m[1]) as $target => $group) {
                foreach ($group->pluck(0)->chunk(1000) as $ids) {
                    DB::table('manuscripts')->whereIn('id', $ids->all())->update(['catalog_volume_id' => $target]);
                }
            }
            foreach (array_chunk(array_column($moves, 0), 1000) as $ids) {
                SourceEntrySync::syncCited(DB::table('manuscripts')->whereIn('id', $ids)->get(['id', 'metadata', 'raw_text', 'catalog_volume_id']));
            }
            $this->recount($source, array_column($moves, 2));
        });
        self::forget($source->id);

        return self::SUCCESS;
    }

    /** The volume of each number as it stands, known by its citation pattern. */
    private function have(Source $source): array
    {
        $have = DB::table('source_volumes')->where('source_id', $source->id)->get();
        $numbers = [];
        foreach (array_keys(self::VOLUMES) as $number) {
            if ($volume = $have->firstWhere('citation_pattern', self::pattern($number))) {
                $numbers[$number] = $volume;
            }
        }

        return $numbers;
    }

    private static function pattern(int $number): string
    {
        return 'محدث ارموی مخ: ' . $number;
    }

    /**
     * The volumes as they should be: [number => [existing volume or null, values]], only the ones that differ.
     *
     * @return array<int, array{0: ?object, 1: array}>
     */
    private function volumes(Source $source): array
    {
        $have = $this->have($source);
        $changes = [];
        foreach (self::VOLUMES as $number => [$name, $pages, $first, $last, $range]) {
            $volume = $have[$number] ?? null;
            $values = ['title' => self::TITLE . ' - جلد ' . $name, 'volume_number' => $number, 'pages_count' => $pages,
                'page_first' => $first, 'page_last' => $last, 'manuscripts_range' => $range, 'citation_pattern' => self::pattern($number)];
            if (! $volume || collect($values)->contains(fn ($value, $column) => (string) $volume->{$column} !== (string) $value)) {
                $changes[$number] = [$volume, $values];
                $this->line(sprintf('  volume %d: %s', $number, $volume ? "#{$volume->id} «{$volume->title}» (number {$volume->volume_number}) set" : 'made'));
            }
        }

        return $changes;
    }

    private function writeVolumes(Source $source, array $changes): void
    {
        // the one it had gives the others its publisher, year, metadata and catalogers
        $model = DB::table('catalog_volumes')->whereIn('id', DB::table('source_volumes')->where('source_id', $source->id)->select('id'))->orderBy('id')->first();
        $catalogers = $model ? DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $model->id)->get(['cataloger_id', 'role']) : collect();
        foreach ($changes as [$volume, $values]) {
            if ($volume) {
                DB::table('catalog_volumes')->where('id', $volume->id)->update($values + ['updated_at' => now()]);
                DB::table('source_volumes')->where('id', $volume->id)->update($values + ['updated_at' => now()]);

                continue;
            }
            $values += ['publisher' => $model->publisher ?? null, 'publication_year' => $model->publication_year ?? null];
            $id = DB::table('catalog_volumes')->insertGetId($values + ['library_id' => $source->library_id, 'catalog_id' => $model->catalog_id ?? null,
                'metadata' => $model->metadata ?? null, 'manuscripts_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('source_volumes')->updateOrInsert(['id' => $id], $values + ['source_id' => $source->id, 'metadata' => $model->metadata ?? null,
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('catalog_volume_cataloger')->insert($catalogers->map(fn ($c) => ['catalog_volume_id' => $id, 'cataloger_id' => $c->cataloger_id,
                'role' => $c->role, 'created_at' => now(), 'updated_at' => now()])->all());
        }
        DB::table('sources')->where('id', $source->id)->update(['volumes_count' => DB::table('source_volumes')->where('source_id', $source->id)->count(), 'updated_at' => now()]);
    }

    /**
     * The copies to tie anew: [copy id, volume id it goes to (null in a dry run before the volume is made), volume id it
     * is on].
     *
     * @return array<int, array{0: int, 1: ?int, 2: ?int}>
     */
    private function moves(Source $source, bool $report = true): array
    {
        $have = array_map(fn ($v) => (int) $v->id, $this->have($source));
        $moves = [];
        $counts = [];
        DB::table('manuscripts')->select('id', 'metadata', 'raw_text', 'catalog_volume_id')->where('library_id', $source->library_id)
            ->where(fn ($q) => $q->where('metadata->catalog_citation', 'like', '%ارمو%')->orWhere('raw_text', 'like', '%ارمو%'))
            ->orderBy('id')->chunk(5000, function ($rows) use (&$moves, &$counts, $have) {
                foreach ($rows as $m) {
                    $raw = References::rawCitation($m->metadata, $m->raw_text);
                    if ($raw === null) {
                        continue;
                    }
                    $cited = References::parse($raw);
                    $series = trim(preg_replace('/[\s\-–]+/u', ' ', strtr((string) $cited['series'], ['ك' => 'ک', 'ى' => 'ی', 'ي' => 'ی'])));
                    $number = $cited['volume'];
                    if (! in_array($series, self::SERIES, true) || ! isset(self::VOLUMES[$number])) {
                        continue;
                    }
                    $target = $have[$number] ?? null;
                    if ($target === null || $target !== (int) $m->catalog_volume_id) {
                        $moves[] = [$m->id, $target, $m->catalog_volume_id ? (int) $m->catalog_volume_id : null];
                        $label = sprintf('volume %d ← %s', $number, $m->catalog_volume_id ? '#' . $m->catalog_volume_id : 'none');
                        $counts[$label] = ($counts[$label] ?? 0) + 1;
                    }
                }
            });
        if ($report) {
            ksort($counts, SORT_NATURAL);
            foreach ($counts as $label => $n) {
                $this->line(sprintf('  %s: %d copies', $label, $n));
            }
            $this->info(sprintf('%d copies tied anew.', count($moves)));
        }

        return $moves;
    }

    /** The copies of every volume of the source and of the volumes left, and the catalogers of all of them. */
    private function recount(Source $source, array $from): void
    {
        $volumes = DB::table('source_volumes')->where('source_id', $source->id)->pluck('id')->merge(array_filter($from))->unique()->all();
        foreach ($volumes as $id) {
            DB::table('catalog_volumes')->where('id', $id)->update(['manuscripts_count' => DB::table('manuscripts')->where('catalog_volume_id', $id)->count()]);
        }
        foreach (DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->pluck('cataloger_id')->unique() as $cataloger) {
            $ids = DB::table('catalog_volume_cataloger')->where('cataloger_id', $cataloger)->pluck('catalog_volume_id')->unique();
            // the same counts as LinkCatalogVolumesCommand::recountVolumesAndCatalogers
            DB::table('catalogers')->where('id', $cataloger)->update(['volumes_count' => $ids->count(), 'manuscripts_count' => DB::table('manuscripts')
                ->where(fn ($q) => $q->where('cataloger_id', $cataloger)->orWhereIn('catalog_volume_id', $ids))->count()]);
        }
    }

    /** Writes every row the run may change to a JSON file and returns its path. */
    private function backup(Source $source, array $moves): string
    {
        $volumes = DB::table('source_volumes')->where('source_id', $source->id)->pluck('id')->merge(array_filter(array_column($moves, 2)))->unique()->values()->all();
        $copies = array_column($moves, 0);
        $backup = [
            'command' => $this->getName(),
            'written_at' => now()->toIso8601String(),
            'source_ids' => [$source->id],
            'sources' => DB::table('sources')->where('id', $source->id)->get(),
            'catalog_volumes' => DB::table('catalog_volumes')->whereIn('id', $volumes)->get(),
            'source_volumes' => DB::table('source_volumes')->whereIn('id', $volumes)->get(),
            'catalog_volume_cataloger' => DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->get(),
            'manuscripts' => collect(array_chunk($copies, 1000))->flatMap(fn ($ids) => DB::table('manuscripts')->whereIn('id', $ids)->get(['id', 'catalog_volume_id']))->all(),
            'source_entries' => collect(array_chunk($copies, 1000))->flatMap(fn ($ids) => DB::table('source_entries')->where('legacy_type', 'fankha_citation')
                ->whereIn('legacy_id', $ids)->get(['id', 'source_id', 'source_volume_id', 'volume_number', 'page_start']))->all(),
            'catalogers' => DB::table('catalogers')->whereIn('id', DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->select('cataloger_id'))
                ->get(['id', 'volumes_count', 'manuscripts_count']),
        ];

        $path = $this->option('backup') ?: storage_path('app/private/backups/sources-split-mohaddes-armavi-' . now()->format('Ymd_His') . '.json');
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
        $volumes = array_column($backup['source_volumes'], 'id');
        $made = DB::table('source_volumes')->whereIn('source_id', $backup['source_ids'])->whereNotIn('id', $volumes)->pluck('id')->all();
        $this->info(sprintf('Restoring %d volumes and %d copies, removing %d volumes made, from %s', count($volumes), count($backup['manuscripts']), count($made), $file));

        DB::transaction(function () use ($backup, $volumes, $made) {
            // the copies first: a made volume's are back on their own when it goes
            FixMisnamedIsfahanCataloguesCommand::restoreColumns('manuscripts', $backup['manuscripts']);
            FixMisnamedIsfahanCataloguesCommand::restoreColumns('source_entries', $backup['source_entries']);
            DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $made)->delete();
            DB::table('source_volumes')->whereIn('id', $made)->delete();
            DB::table('catalog_volumes')->whereIn('id', $made)->delete();
            foreach (['sources', 'catalog_volumes', 'source_volumes'] as $table) {
                foreach (array_chunk($backup[$table], 500) as $rows) {
                    DB::table($table)->upsert($rows, ['id']);
                }
            }
            DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->delete();
            DB::table('catalog_volume_cataloger')->insert($backup['catalog_volume_cataloger']);
            FixMisnamedIsfahanCataloguesCommand::restoreColumns('catalogers', $backup['catalogers']);
        });
        foreach ($backup['source_ids'] as $id) {
            self::forget($id);
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
