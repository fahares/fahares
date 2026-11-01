<?php

namespace App\Console\Commands;

use App\Console\Commands\ImportFankhaCatalogReferencesCommand as References;
use App\Models\Source;
use App\Sources\SourceEntrySync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sayyed Mohammad Ali Rowzati's «فهرست کتب خطی کتابخانه‌های اصفهان» (step 13.137) is one bound volume in MTIF (21530,
 * 400+8 pages, «معرفی کامل هشتاد نسخه»): its twelve parts run one sequence of pages, and Fankha cites it by page alone
 * («فهرست روضاتی: - 123») for the 80 copies of «کتابخانه‌های اصفهان». The volume had no MTIF record, Sayyed Ahmad
 * Rowzati's library («روضاتی») for its library, and 456 copies, of which only those 80 are its own:
 *
 *  - The volume gets MTIF 21530, its pages, Fankha's years (۱۳۳۷-۱۳۴۱) and «فهرست روضاتی» for its citation; the
 *    catalogue and the volume go to «کتابخانه‌های اصفهان».
 *  - A copy cited «ف: - P» («روضاتی - ف: P») is of Sayyed Ahmad Rowzati's library (Fankha's «روضاتی», «مجموعه شخصی
 *    سید احمد روضاتی»), cited as its own catalogue's copies are: it goes to that catalogue.
 *  - A copy cited «نشریه: V» goes to volume V of «نشریه نسخه‌های خطی کتابخانه مرکزی دانشگاه تهران».
 *  - A copy cited «نسخه پژوهی: 1» (Hafezian's «کتابخانه علامه روضاتی» in «نسخه‌پژوهی», دفتر یکم, which has no source
 *    yet) is left on none.
 *  Entries follow their copies (SourceEntrySync::syncCited); volume and cataloger counts are taken again.
 *
 * Only the copies on the volume are read. Found by source code, library name, MTIF id and citation pattern, never by
 * local id. A backup of every row it changes is written first; --revert restores it. Safe to run again.
 */
class FixRowzatiIsfahanCatalogueCommand extends Command
{
    protected $signature = 'sources:fix-rowzati-isfahan
        {--dry-run : Report what would change without saving}
        {--backup= : Where to write the backup of the rows changed (default storage/app/private/backups/)}
        {--revert= : Restore the rows of a backup this command wrote}';

    protected $description = 'Give Rowzati\'s Isfahan catalogue its MTIF volume and library, and its strangers\' copies their own catalogues';

    public const CODE = 'catalogue-e3209510dc45';

    /** Sayyed Ahmad Rowzati's library catalogue. */
    public const AHMAD = 'catalogue-54ef008d33d5';

    /** «نشریه نسخه‌های خطی کتابخانه مرکزی دانشگاه تهران». */
    public const NASHRIYEH = 'catalogue-37ef5a7d7428';

    public const LIBRARY = ['کتابخانه‌های اصفهان', 'اصفهان'];

    /** The volume as MTIF 21530 and Fankha's table give it. */
    public const VOLUME = ['mtif_entry_id' => 21530, 'pages_count' => 408, 'publication_year' => '۱۳۳۷-۱۳۴۱', 'citation_pattern' => 'فهرست روضاتی'];

    private const AHMAD_SERIES = ['ف', 'روضاتی ف'];

    private const NOSKHE_PAZHUHI = ['نسخه پژوهی', 'نسخه‌پژوهی'];

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

        $sources = array_map(fn ($code) => FillEmptyCataloguesCommand::source($code), [self::CODE, self::AHMAD, self::NASHRIYEH]);
        $libraries = DB::table('libraries')->where('name', self::LIBRARY[0])->where('city', self::LIBRARY[1])->pluck('id');
        if (in_array(null, $sources, true) || $libraries->count() !== 1) {
            $this->error('A source or the library is missing: ' . implode(', ', [self::CODE, self::AHMAD, self::NASHRIYEH, implode(' / ', self::LIBRARY)]));

            return self::FAILURE;
        }
        [$source, $ahmad, $nashriyeh] = $sources;
        $library = (int) $libraries->first();
        $volume = DB::table('source_volumes')->where('source_id', $source->id)->orderBy('volume_number')->first();
        $ahmadVolume = DB::table('source_volumes')->where('source_id', $ahmad->id)->orderBy('volume_number')->value('id');
        if (! $volume || ! $ahmadVolume) {
            $this->error('A catalogue has no volume.');

            return self::FAILURE;
        }

        $values = $this->values($source, $volume, $library);
        $moves = $this->moves((int) $volume->id, (int) $ahmadVolume, $nashriyeh);
        if (! $values && ! $moves) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }
        if ($this->dry) {
            return self::SUCCESS;
        }

        $ids = [$source->id, $ahmad->id, $nashriyeh->id];
        $this->info('Backup: ' . $this->backup($ids, $moves));
        DB::transaction(function () use ($source, $volume, $library, $values, $moves, $ids) {
            if ($values) {
                $group = json_decode($source->getRawOriginal('metadata') ?? '{}', true) ?: [];
                if (isset($group['group'])) {
                    $group['group'] = preg_replace('/^catalogue\|\d+\|/', "catalogue|{$library}|", $group['group']);
                }
                DB::table('sources')->where('id', $source->id)->update(['library_id' => $library, 'metadata' => json_encode($group, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
                DB::table('source_volumes')->where('id', $volume->id)->update(self::VOLUME + ['updated_at' => now()]);
                DB::table('catalog_volumes')->where('id', $volume->id)->update(self::VOLUME + ['library_id' => $library, 'updated_at' => now()]);
            }
            foreach (collect($moves)->groupBy(fn ($m) => $m[1] ?? 0) as $target => $group) {
                DB::table('manuscripts')->whereIn('id', $group->pluck(0)->all())->update(['catalog_volume_id' => $target ?: null]);
            }
            SourceEntrySync::syncCited(DB::table('manuscripts')->whereIn('id', array_column($moves, 0))->get(['id', 'metadata', 'raw_text', 'catalog_volume_id']));
            $this->recount($ids);
        });
        foreach ($ids as $id) {
            self::forget($id);
        }

        return self::SUCCESS;
    }

    /** What the catalogue and its volume lack, reported; empty when they have it. */
    private function values(Source $source, object $volume, int $library): array
    {
        $cv = DB::table('catalog_volumes')->where('id', $volume->id)->first();
        $differ = collect(self::VOLUME)->filter(fn ($value, $column) => (string) $volume->{$column} !== (string) $value || (string) $cv->{$column} !== (string) $value)->all();
        if ((int) $source->library_id !== $library || (int) $cv->library_id !== $library) {
            $differ['library_id'] = $library;
        }
        foreach ($differ as $column => $value) {
            $this->line(sprintf('  volume #%d %s: %s', $volume->id, $column, $value));
        }

        return $differ;
    }

    /**
     * The copies on the volume to tie anew: [copy id, volume id it goes to or null, volume id it is on].
     *
     * @return array<int, array{0: int, 1: ?int, 2: int}>
     */
    private function moves(int $volume, int $ahmadVolume, Source $nashriyeh): array
    {
        $nashriyehVolumes = DB::table('source_volumes')->where('source_id', $nashriyeh->id)->pluck('id', 'citation_pattern')
            ->mapWithKeys(fn ($id, $pattern) => [(int) preg_replace('/\D/', '', $pattern) => (int) $id])->all();
        $moves = [];
        $counts = [];
        foreach (DB::table('manuscripts')->where('catalog_volume_id', $volume)->get(['id', 'metadata', 'raw_text']) as $m) {
            $raw = References::rawCitation($m->metadata, $m->raw_text);
            if ($raw === null) {
                continue;
            }
            $cited = References::parse($raw);
            $series = trim(preg_replace('/[\s\-–]+/u', ' ', strtr((string) $cited['series'], ['ك' => 'ک', 'ى' => 'ی', 'ي' => 'ی'])));
            if (in_array($series, self::AHMAD_SERIES, true)) {
                [$target, $label] = [$ahmadVolume, 'Sayyed Ahmad Rowzati'];
            } elseif ($series === 'نشریه' && isset($nashriyehVolumes[$cited['volume']])) {
                [$target, $label] = [$nashriyehVolumes[$cited['volume']], "نشریه {$cited['volume']}"];
            } elseif (in_array($series, self::NOSKHE_PAZHUHI, true)) {
                [$target, $label] = [null, 'none («نسخه پژوهی»)'];
            } else {
                continue;
            }
            $moves[] = [$m->id, $target, $volume];
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        ksort($counts, SORT_NATURAL);
        foreach ($counts as $label => $n) {
            $this->line(sprintf('  %s ← #%d: %d copies', $label, $volume, $n));
        }
        $this->info(sprintf('%d copies tied anew.', count($moves)));

        return $moves;
    }

    /** The copies of every volume of the sources, and the catalogers of them. */
    private function recount(array $sourceIds): void
    {
        $volumes = DB::table('source_volumes')->whereIn('source_id', $sourceIds)->pluck('id')->all();
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
    private function backup(array $sourceIds, array $moves): string
    {
        $volumes = DB::table('source_volumes')->whereIn('source_id', $sourceIds)->pluck('id')->all();
        $copies = array_column($moves, 0);
        $backup = [
            'command' => $this->getName(),
            'written_at' => now()->toIso8601String(),
            'source_ids' => $sourceIds,
            'sources' => DB::table('sources')->where('id', $sourceIds[0])->get(['id', 'library_id', 'metadata', 'updated_at']),
            'source_volumes' => DB::table('source_volumes')->whereIn('id', $volumes)->get(array_merge(['id', 'updated_at'], array_keys(self::VOLUME))),
            'catalog_volumes' => DB::table('catalog_volumes')->whereIn('id', $volumes)->get(array_merge(['id', 'library_id', 'manuscripts_count', 'updated_at'], array_keys(self::VOLUME))),
            'manuscripts' => DB::table('manuscripts')->whereIn('id', $copies)->get(['id', 'catalog_volume_id']),
            'source_entries' => DB::table('source_entries')->where('legacy_type', 'fankha_citation')->whereIn('legacy_id', $copies)
                ->get(['id', 'source_id', 'source_volume_id', 'volume_number', 'page_start']),
            'catalogers' => DB::table('catalogers')->whereIn('id', DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->select('cataloger_id'))
                ->get(['id', 'volumes_count', 'manuscripts_count']),
        ];

        $path = $this->option('backup') ?: storage_path('app/private/backups/sources-fix-rowzati-isfahan-' . now()->format('Ymd_His') . '.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($backup, JSON_UNESCAPED_UNICODE));

        return $path;
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
            foreach (['sources', 'source_volumes', 'catalog_volumes', 'manuscripts', 'source_entries', 'catalogers'] as $table) {
                FixMisnamedIsfahanCataloguesCommand::restoreColumns($table, $backup[$table]);
            }
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
