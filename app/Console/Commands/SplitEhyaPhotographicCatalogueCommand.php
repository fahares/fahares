<?php

namespace App\Console\Commands;

use App\Console\Commands\ImportFankhaCatalogReferencesCommand as References;
use App\Models\Source;
use App\Sources\SourceEntrySync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Markaz-e Ehya's photographic catalogue («فهرست نسخه‌های عکسی مرکز احیاء میراث اسلامی», seven volumes in Fankha's
 * table) was one volume (step 13.122): «… - عکسی جلد 7», numbered 1, with no MTIF record, holding 6,459 copies. Of
 * them 5,220 cite «عکسی ف: V - P» for V from 1 to 7, and 1,216 are Masjid-e A'zam's, cited «ف مخ: 3» from its own
 * catalogue. The same slip put Masjid-e A'zam's volumes 2, 4 and 5 on the catalog volumes after it (Mohaddes Armavi's
 * and Golpayegani's fourth and fifth).
 *
 *  - The photographic catalogue gets volumes 1 to 6, the one it had becoming 7, each with its MTIF record (the first
 *    five carried by Ehya's manuscript catalogue until step 13.121), pages, year, numbers and catalogers from Fankha's
 *    table (rows 398–404).
 *  - Each Ehya copy whose first citation is «عکسی ف: V» goes to volume V of it, wherever it was; a copy on it whose
 *    first citation is «ف: V» («ف: 3 - 90 و عکسی ف: 2 - 430») goes to volume V of the manuscript catalogue.
 *  - Each Masjid-e A'zam copy cited «ف مخ: V» on another catalogue, or on none, goes to volume V of its own.
 *  Entries follow their copies (SourceEntrySync::syncCited); volume and cataloger counts are taken again.
 *
 * A citation with no volume, or a number past the catalogue's volumes («عکسی ف: 412-2»), is left where it is. Found by
 * source code, library and MTIF id, never by local id. A backup of every row it changes is written first; --revert
 * restores it, removing the volumes it made. Safe to run again.
 */
class SplitEhyaPhotographicCatalogueCommand extends Command
{
    protected $signature = 'sources:split-ehya-photographic
        {--dry-run : Report what would change without saving}
        {--backup= : Where to write the backup of the rows changed (default storage/app/private/backups/)}
        {--revert= : Restore the rows of a backup this command wrote}';

    protected $description = 'Give Markaz-e Ehya\'s photographic catalogue its seven volumes and the copies of each, and Masjid-e A\'zam\'s copies their own catalogue';

    public const PHOTOGRAPHIC = 'catalogue-4598cd487858';

    public const MANUSCRIPTS = 'catalogue-0fc97a930b14';

    public const MASJID = 'catalogue-20097e616c1d';

    /** Catalogers by MTIF id: Ahmad, Ja'far and Sadeq Hosseini Eshkevari. */
    private const AHMAD = 37228;

    private const JAFAR = 37267;

    private const SADEQ = 37268;

    /** Each volume of the photographic catalogue: MTIF record, pages, year, manuscripts and catalogers (Fankha's table). */
    public const VOLUMES = [
        1 => [21319, 488, '۱۳۷۷', '1-400', [self::AHMAD => 'supervisor', self::JAFAR => 'cataloger', self::SADEQ => 'cataloger']],
        2 => [21320, 496, '۱۳۸۰', '401-800', [self::AHMAD => 'supervisor', self::JAFAR => 'cataloger', self::SADEQ => 'cataloger']],
        3 => [21321, 504, '۱۳۸۰', '801-1200', [self::AHMAD => 'supervisor', self::JAFAR => 'cataloger', self::SADEQ => 'cataloger']],
        4 => [21322, 488, '۱۳۸۲', '1201-1600', [self::AHMAD => 'supervisor', self::JAFAR => 'cataloger', self::SADEQ => 'cataloger']],
        5 => [21428, 464, '۱۳۸۳', '1601-2000', [self::AHMAD => 'supervisor', self::JAFAR => 'cataloger', self::SADEQ => 'cataloger']],
        6 => [66618, 507, '۱۳۸۶', '2001-2400', [self::AHMAD => 'supervisor', self::JAFAR => 'cataloger']],
        7 => [66617, 420, '۱۳۸۷', '2401-2800', [self::JAFAR => 'cataloger']],
    ];

    public const TITLE = 'فهرست نسخه‌های عکسی مرکز احیاء میراث اسلامی';

    /** Masjid-e A'zam's handwritten catalogue has five volumes. */
    private const MASJID_VOLUMES = 5;

    private bool $dry = false;

    /** @var array<int, int> the catalogers of the volumes before the run, so the ones dropped are counted again too */
    private array $touchedCatalogers = [];

    public function handle(): int
    {
        if ($file = $this->option('revert')) {
            return $this->revert($file);
        }
        $this->dry = (bool) $this->option('dry-run');
        if ($this->dry) {
            $this->warn('Dry run: nothing is saved.');
        }

        $photo = FillEmptyCataloguesCommand::source(self::PHOTOGRAPHIC);
        $manuscripts = FillEmptyCataloguesCommand::source(self::MANUSCRIPTS);
        $masjid = FillEmptyCataloguesCommand::source(self::MASJID);
        if (! $photo || ! $manuscripts || ! $masjid) {
            $this->error('A source is missing: ' . implode(', ', [self::PHOTOGRAPHIC, self::MANUSCRIPTS, self::MASJID]));

            return self::FAILURE;
        }

        $volumes = $this->volumes($photo);
        $moves = $this->moves($photo, $manuscripts, $masjid);
        if (! $volumes && ! $moves) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }
        if ($this->dry) {
            return self::SUCCESS;
        }

        $this->info('Backup: ' . $this->backup([$photo, $manuscripts, $masjid], $moves));
        DB::transaction(function () use ($photo, $manuscripts, $masjid, $volumes) {
            $this->writeVolumes($photo, $volumes);
            // the volumes the moves go to exist now
            $moves = $this->moves($photo, $manuscripts, $masjid, false);
            foreach (collect($moves)->groupBy(fn ($m) => $m[1]) as $target => $group) {
                foreach ($group->pluck(0)->chunk(1000) as $ids) {
                    DB::table('manuscripts')->whereIn('id', $ids->all())->update(['catalog_volume_id' => $target]);
                }
            }
            foreach (array_chunk(array_column($moves, 0), 1000) as $ids) {
                SourceEntrySync::syncCited(DB::table('manuscripts')->whereIn('id', $ids)->get(['id', 'metadata', 'raw_text', 'catalog_volume_id']));
            }
            $this->recount([$photo->id, $manuscripts->id, $masjid->id], array_column($moves, 2));
        });
        foreach ([$photo, $manuscripts, $masjid] as $source) {
            self::forget($source->id);
        }

        return self::SUCCESS;
    }

    /**
     * The photographic catalogue's volumes as they should be: [number => [existing volume or null, values]], only the
     * ones that differ.
     *
     * @return array<int, array{0: ?object, 1: array}>
     */
    private function volumes(Source $photo): array
    {
        $have = DB::table('source_volumes')->where('source_id', $photo->id)->get();
        $changes = [];
        foreach (self::VOLUMES as $number => [$mtif, $pages, $year, $range]) {
            // the one volume it had is the seventh («عکسی جلد 7», 2401-2800)
            $volume = $have->first(fn ($v) => (int) $v->mtif_entry_id === $mtif)
                ?? $have->first(fn ($v) => $v->manuscripts_range === $range && ! $v->mtif_entry_id);
            $values = ['title' => self::TITLE . ' - جلد ' . $number, 'volume_number' => $number, 'publisher' => 'مرکز احیاء میراث اسلامی',
                'publication_year' => $year, 'pages_count' => $pages, 'manuscripts_range' => $range,
                'citation_pattern' => 'عکسی ف: ' . $number, 'mtif_entry_id' => $mtif];
            $differs = ! $volume || collect($values)->contains(fn ($value, $column) => (string) $volume->{$column} !== (string) $value);
            if ($differs) {
                $changes[$number] = [$volume, $values];
                $this->line(sprintf('  photographic volume %d: %s', $number, $volume
                    ? "#{$volume->id} «{$volume->title}» (number {$volume->volume_number}, MTIF " . ($volume->mtif_entry_id ?? '-') . ') takes its record' : 'made, MTIF ' . $mtif));
            }
        }

        return $changes;
    }

    private function writeVolumes(Source $photo, array $changes): void
    {
        $model = DB::table('catalog_volumes')->whereIn('id', DB::table('source_volumes')->where('source_id', $photo->id)->select('id'))->first();
        foreach ($changes as $number => [$volume, $values]) {
            $mtif = $values['mtif_entry_id'];
            // the record is this volume's alone
            foreach (['source_volumes', 'catalog_volumes'] as $table) {
                DB::table($table)->where('mtif_entry_id', $mtif)->when($volume, fn ($q) => $q->where('id', '!=', $volume->id))->update(['mtif_entry_id' => null]);
            }
            $id = $volume?->id;
            if ($id) {
                DB::table('catalog_volumes')->where('id', $id)->update($values + ['updated_at' => now()]);
                DB::table('source_volumes')->where('id', $id)->update($values + ['updated_at' => now()]);
            } else {
                $id = DB::table('catalog_volumes')->insertGetId($values + ['library_id' => $photo->library_id, 'catalog_id' => $model->catalog_id ?? null,
                    'metadata' => $model->metadata ?? null, 'manuscripts_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('source_volumes')->updateOrInsert(['id' => $id], $values + ['source_id' => $photo->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $catalogers = DB::table('catalogers')->whereIn('mtif_entry_id', array_keys(self::VOLUMES[$number][4]))->pluck('id', 'mtif_entry_id');
            DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $id)->delete();
            DB::table('catalog_volume_cataloger')->insert(collect(self::VOLUMES[$number][4])->filter(fn ($role, $mtif) => isset($catalogers[$mtif]))
                ->map(fn ($role, $mtif) => ['catalog_volume_id' => $id, 'cataloger_id' => $catalogers[$mtif], 'role' => $role, 'created_at' => now(), 'updated_at' => now()])
                ->values()->all());
        }
        DB::table('sources')->where('id', $photo->id)->update(['volumes_count' => DB::table('source_volumes')->where('source_id', $photo->id)->count(), 'updated_at' => now()]);
    }

    /**
     * The copies to tie anew: [copy id, volume id it goes to (null in a dry run before the volume is made), volume id it
     * is on].
     *
     * @return array<int, array{0: int, 1: ?int, 2: ?int}>
     */
    private function moves(Source $photo, Source $manuscripts, Source $masjid, bool $report = true): array
    {
        $byNumber = fn (Source $s) => DB::table('source_volumes')->where('source_id', $s->id)->get()
            ->groupBy('volume_number')->map(fn ($vs) => $vs->count() === 1 ? (int) $vs->first()->id : null)->filter()->all();
        // before they are made, a photographic volume missing is known by its number alone
        $photoVolumes = $this->volumesByRecord($photo);
        $photoIds = DB::table('source_volumes')->where('source_id', $photo->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $manuscriptVolumes = $byNumber($manuscripts);
        $masjidVolumes = $byNumber($masjid);
        $ehyaVolumes = DB::table('source_volumes')->whereIn('source_id', [$photo->id, $manuscripts->id])->pluck('id')->all();

        $moves = [];
        $counts = [];
        $query = DB::table('manuscripts')->select('id', 'library_id', 'metadata', 'raw_text', 'catalog_volume_id')
            ->where(fn ($q) => $q->where('library_id', $photo->library_id)
                ->where(fn ($q) => $q->whereIn('catalog_volume_id', $ehyaVolumes)->orWhereNull('catalog_volume_id')))
            ->orWhere(fn ($q) => $q->where('library_id', $masjid->library_id)
                ->where(fn ($q) => $q->whereNotIn('catalog_volume_id', array_values($masjidVolumes))->orWhereNull('catalog_volume_id')));
        $query->orderBy('id')->chunk(5000, function ($rows) use (&$moves, &$counts, $photo, $photoVolumes, $photoIds, $manuscriptVolumes, $masjidVolumes) {
            foreach ($rows as $m) {
                $raw = References::rawCitation($m->metadata, $m->raw_text);
                if ($raw === null) {
                    continue;
                }
                $cited = References::parse($raw);
                $series = strtr((string) $cited['series'], ['ك' => 'ک', 'ى' => 'ی', 'ي' => 'ی']);
                $number = $cited['volume'];
                $target = null;
                $label = null;
                if ((int) $m->library_id === (int) $photo->library_id) {
                    $onPhoto = in_array((int) $m->catalog_volume_id, $photoIds, true);
                    if (str_contains($series, 'عک') && isset(self::VOLUMES[$number])) {
                        [$target, $label] = [$photoVolumes[$number] ?? -$number, "photographic {$number}"];
                    } elseif ($series === 'ف' && $onPhoto && isset($manuscriptVolumes[$number])) {
                        [$target, $label] = [$manuscriptVolumes[$number], "manuscripts {$number}"];
                    }
                } elseif (preg_match('/^ف\s*مخ$/u', $series) && $number >= 1 && $number <= self::MASJID_VOLUMES && isset($masjidVolumes[$number])) {
                    [$target, $label] = [$masjidVolumes[$number], "Masjid-e A'zam {$number}"];
                }
                if ($target !== null && $target !== (int) $m->catalog_volume_id) {
                    $moves[] = [$m->id, $target > 0 ? $target : null, $m->catalog_volume_id ? (int) $m->catalog_volume_id : null];
                    $from = $m->catalog_volume_id ? '#' . $m->catalog_volume_id : 'none';
                    $counts["{$label} ← {$from}"] = ($counts["{$label} ← {$from}"] ?? 0) + 1;
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

    /** The photographic catalogue's volumes as they stand, by the number they are to have. */
    private function volumesByRecord(Source $photo): array
    {
        $have = DB::table('source_volumes')->where('source_id', $photo->id)->get();
        $numbers = [];
        foreach (self::VOLUMES as $number => [$mtif, , , $range]) {
            $volume = $have->first(fn ($v) => (int) $v->mtif_entry_id === $mtif) ?? $have->first(fn ($v) => $v->manuscripts_range === $range && ! $v->mtif_entry_id);
            if ($volume) {
                $numbers[$number] = (int) $volume->id;
            }
        }

        return $numbers;
    }

    /** The copies of every volume of the three sources and of the volumes left, and the catalogers of all of them. */
    private function recount(array $sourceIds, array $from): void
    {
        $volumes = DB::table('source_volumes')->whereIn('source_id', $sourceIds)->pluck('id')->merge(array_filter($from))->unique()->all();
        foreach ($volumes as $id) {
            DB::table('catalog_volumes')->where('id', $id)->update(['manuscripts_count' => DB::table('manuscripts')->where('catalog_volume_id', $id)->count()]);
        }
        $catalogers = array_unique(array_merge($this->touchedCatalogers, DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->pluck('cataloger_id')->all()));
        // the one count of every command (Cataloger::recount)
        \App\Models\Cataloger::recount(array_unique($catalogers));
    }

    /** Writes every row the run may change to a JSON file and returns its path. */
    private function backup(array $sources, array $moves): string
    {
        $sourceIds = array_map(fn ($s) => $s->id, $sources);
        $volumes = DB::table('source_volumes')->whereIn('source_id', $sourceIds)->pluck('id')->merge(array_filter(array_column($moves, 2)))->unique()->values()->all();
        $records = array_column(self::VOLUMES, 0);
        // a volume elsewhere that carries one of the records loses it
        $volumes = array_values(array_unique(array_merge($volumes, DB::table('source_volumes')->whereIn('mtif_entry_id', $records)->pluck('id')->all(),
            DB::table('catalog_volumes')->whereIn('mtif_entry_id', $records)->pluck('id')->all())));
        $copies = array_column($moves, 0);
        // and the ones the photographic volumes take
        $this->touchedCatalogers = DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->pluck('cataloger_id')
            ->merge(DB::table('catalogers')->whereIn('mtif_entry_id', [self::AHMAD, self::JAFAR, self::SADEQ])->pluck('id'))->unique()->values()->all();

        $backup = [
            'command' => $this->getName(),
            'written_at' => now()->toIso8601String(),
            'source_ids' => $sourceIds,
            'sources' => DB::table('sources')->whereIn('id', $sourceIds)->get(),
            'catalog_volumes' => DB::table('catalog_volumes')->whereIn('id', $volumes)->get(),
            'source_volumes' => DB::table('source_volumes')->whereIn('id', $volumes)->get(),
            'catalog_volume_cataloger' => DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->get(),
            'manuscripts' => collect(array_chunk($copies, 1000))->flatMap(fn ($ids) => DB::table('manuscripts')->whereIn('id', $ids)->get(['id', 'catalog_volume_id']))->all(),
            'source_entries' => collect(array_chunk($copies, 1000))->flatMap(fn ($ids) => DB::table('source_entries')->where('legacy_type', 'fankha_citation')
                ->whereIn('legacy_id', $ids)->get(['id', 'source_id', 'source_volume_id', 'volume_number', 'page_start']))->all(),
            'catalogers' => DB::table('catalogers')->whereIn('id', $this->touchedCatalogers)->get(['id', 'volumes_count', 'manuscripts_count']),
        ];

        $path = $this->option('backup') ?: storage_path('app/private/backups/sources-split-ehya-photographic-' . now()->format('Ymd_His') . '.json');
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
