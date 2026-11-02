<?php

namespace App\Console\Commands;

use App\Console\Commands\ImportFankhaCatalogReferencesCommand as References;
use App\Console\Commands\SplitEhyaPhotographicCatalogueCommand as Photographic;
use App\Console\Commands\SplitMohaddesArmaviCatalogueCommand as Armavi;
use App\Models\Source;
use App\Sources\SourceEntrySync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Markaz-e Ehya copies the splits of steps 13.122 and 13.127 left where they were (step 13.131), read against the
 * printed Fankha:
 *
 *  - «محدث ارموی مخ: 1145-3» is volume 3 page 1145. The print sets volume and page right to left («۱۱۴۵-۳») and its
 *    text layer keeps that order. The Mohaddes Armavi catalogue runs one sequence of pages over its three volumes, so
 *    a page names its volume: «محدث ارموی مخ: 263» is volume 1, and so is «10-404».
 *  - «ف مخ: 1 - 330», untied, in Fankha's volumes 7 and 8, is the same catalogue: their pages (300–434) are the gap of
 *    its first volume, and their shelfmarks are of the Armavi collection.
 *  - «عکسی ف: 412-2» is volume 2 page 412 of the photographic catalogue: of the two numbers only one can be a volume
 *    and the other is within that volume's pages. A citation written after a note («… [عکسی ف: 6-123]») is read too.
 *
 * A copy moves only when everything its citation gives (the volume, and the volume its page falls in) names the same
 * volume, and only from a volume of the same catalogue or none. Found by source code, library, MTIF record and citation
 * pattern, never by local id. A backup of every row it changes is written first; --revert restores it. Safe to run again.
 */
class TieOddEhyaCitationsCommand extends Command
{
    protected $signature = 'sources:tie-odd-ehya-citations
        {--dry-run : Report what would change without saving}
        {--backup= : Where to write the backup of the rows changed (default storage/app/private/backups/)}
        {--revert= : Restore the rows of a backup this command wrote}';

    protected $description = 'Tie Markaz-e Ehya\'s copies cited with volume and page reversed, a page alone or «ف مخ» to the volume the citation names';

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

        $armavi = FillEmptyCataloguesCommand::source(Armavi::CODE);
        $photo = FillEmptyCataloguesCommand::source(Photographic::PHOTOGRAPHIC);
        if (! $armavi || ! $photo) {
            $this->error('A source is missing: ' . implode(', ', [Armavi::CODE, Photographic::PHOTOGRAPHIC]));

            return self::FAILURE;
        }

        $moves = $this->moves($armavi, $photo);
        if (! $moves) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }
        if ($this->dry) {
            return self::SUCCESS;
        }

        $this->info('Backup: ' . $this->backup([$armavi, $photo], $moves));
        DB::transaction(function () use ($armavi, $photo, $moves) {
            foreach (collect($moves)->groupBy(fn ($m) => $m[1]) as $target => $group) {
                DB::table('manuscripts')->whereIn('id', $group->pluck(0)->all())->update(['catalog_volume_id' => $target]);
            }
            SourceEntrySync::syncCited(DB::table('manuscripts')->whereIn('id', array_column($moves, 0))->get(['id', 'metadata', 'raw_text', 'catalog_volume_id']));
            $this->recount([$armavi->id, $photo->id]);
        });
        foreach ([$armavi, $photo] as $source) {
            self::forget($source->id);
        }

        return self::SUCCESS;
    }

    /** The Mohaddes Armavi volume a page falls in. */
    private static function armaviVolume(int $page): ?int
    {
        foreach (Armavi::VOLUMES as $number => [, , $first, $last]) {
            if ($page >= $first && $page <= $last) {
                return $number;
            }
        }

        return null;
    }

    /**
     * The Mohaddes Armavi volume the numbers of a citation agree on: a number up to 3 is a volume, a larger one a page.
     *
     * @param  int[]  $numbers
     */
    public static function armaviCited(array $numbers): ?int
    {
        $volumes = array_map(fn ($n) => $n <= count(Armavi::VOLUMES) ? $n : self::armaviVolume($n), $numbers);
        // two numbers both up to 3 leave volume and page unknown
        if (! $volumes || in_array(null, $volumes, true) || (count($numbers) > 1 && max($numbers) <= count(Armavi::VOLUMES))) {
            return null;
        }

        return count(array_unique($volumes)) === 1 ? $volumes[0] : null;
    }

    /**
     * The photographic volume of a citation's two numbers: the one that can be a volume, the other within its pages.
     *
     * @param  int[]  $numbers
     */
    public static function photographicCited(array $numbers): ?int
    {
        if (count($numbers) !== 2) {
            return null;
        }
        $volumes = array_values(array_filter($numbers, fn ($n) => isset(Photographic::VOLUMES[$n])));
        if (count($volumes) !== 1) {
            return null;
        }
        $page = array_sum($numbers) - $volumes[0];

        return $page > count(Photographic::VOLUMES) && $page <= Photographic::VOLUMES[$volumes[0]][1] ? $volumes[0] : null;
    }

    /** The numbers of the citation's part a pattern finds, or null. */
    private static function numbers(string $citation, string $pattern): ?array
    {
        if (! preg_match_all($pattern, $citation, $m)) {
            return null;
        }
        preg_match_all('/\d+/', end($m[1]), $n);

        return array_map('intval', $n[0]);
    }

    /**
     * The copies to tie anew: [copy id, volume id it goes to, volume id it is on].
     *
     * @return array<int, array{0: int, 1: int, 2: ?int}>
     */
    private function moves(Source $armavi, Source $photo): array
    {
        $armaviVolumes = DB::table('source_volumes')->where('source_id', $armavi->id)->pluck('id', 'citation_pattern')
            ->mapWithKeys(fn ($id, $pattern) => [(int) preg_replace('/\D/', '', $pattern) => (int) $id])->all();
        $byRecord = DB::table('source_volumes')->where('source_id', $photo->id)->pluck('id', 'mtif_entry_id');
        $photoVolumes = collect(Photographic::VOLUMES)->map(fn ($v) => isset($byRecord[$v[0]]) ? (int) $byRecord[$v[0]] : null)->filter()->all();

        $moves = [];
        $counts = [];
        $rows = DB::table('manuscripts')->select('id', 'metadata', 'raw_text', 'catalog_volume_id')->where('library_id', $armavi->library_id)
            ->where(fn ($q) => $q->whereNull('catalog_volume_id')->orWhereIn('catalog_volume_id', array_merge(array_values($armaviVolumes), array_values($photoVolumes))))
            ->get();
        foreach ($rows as $m) {
            $raw = References::rawCitation($m->metadata, $m->raw_text);
            if ($raw === null) {
                continue;
            }
            $raw = strtr($raw, ['ك' => 'ک', 'ى' => 'ی', 'ي' => 'ی']);
            $on = $m->catalog_volume_id ? (int) $m->catalog_volume_id : null;
            $cited = References::parse($raw);
            $name = trim(preg_replace('/[\s\-–]+/u', ' ', strtr((string) $cited['series'], ['ك' => 'ک', 'ى' => 'ی', 'ي' => 'ی'])));
            [$target, $label] = [null, null];
            if (in_array($name, Armavi::SERIES, true) && (! $on || in_array($on, $armaviVolumes, true))) {
                $number = self::armaviCited(self::numbers($raw, '/محدث\s*ارموی[^\d\]]*([^\]]*)/u') ?? []);
                [$target, $label] = [$armaviVolumes[$number] ?? null, "Mohaddes Armavi {$number}"];
            } elseif ($name === 'ف مخ' && ! $on) {
                $number = self::armaviCited(self::numbers($raw, '/ف\s*مخ\s*:([^\]]*)/u') ?? []);
                [$target, $label] = [$armaviVolumes[$number] ?? null, "Mohaddes Armavi {$number} («ف مخ»)"];
            } elseif ((str_contains($name, 'عک') || ($cited['volume'] === null && mb_strlen($name) > 20)) && str_contains($raw, 'عک')
                && (! $on || in_array($on, $photoVolumes, true))) {
                // a citation after a note is read from the note's end («… [عکسی ف: 6-123]»)
                $number = self::photographicCited(self::numbers($raw, '/عک[^:\[\]]*:([^\]\[]*)/u') ?? []);
                [$target, $label] = [$photoVolumes[$number] ?? null, "photographic {$number}"];
            }
            if ($target && $target !== $on) {
                $moves[] = [$m->id, $target, $on];
                $from = $on ? '#' . $on : 'none';
                $counts["{$label} ← {$from}"] = ($counts["{$label} ← {$from}"] ?? 0) + 1;
            }
        }
        ksort($counts, SORT_NATURAL);
        foreach ($counts as $label => $n) {
            $this->line(sprintf('  %s: %d copies', $label, $n));
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
        // the one count of every command (Cataloger::recount)
        \App\Models\Cataloger::recount(DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->pluck('cataloger_id'));
    }

    /** Writes every row the run may change to a JSON file and returns its path. */
    private function backup(array $sources, array $moves): string
    {
        $sourceIds = array_map(fn ($s) => $s->id, $sources);
        $volumes = DB::table('source_volumes')->whereIn('source_id', $sourceIds)->pluck('id')->all();
        $copies = array_column($moves, 0);
        $backup = [
            'command' => $this->getName(),
            'written_at' => now()->toIso8601String(),
            'catalog_volumes' => DB::table('catalog_volumes')->whereIn('id', $volumes)->get(['id', 'manuscripts_count']),
            'manuscripts' => DB::table('manuscripts')->whereIn('id', $copies)->get(['id', 'catalog_volume_id']),
            'source_entries' => DB::table('source_entries')->where('legacy_type', 'fankha_citation')->whereIn('legacy_id', $copies)
                ->get(['id', 'source_id', 'source_volume_id', 'volume_number', 'page_start']),
            'catalogers' => DB::table('catalogers')->whereIn('id', DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->select('cataloger_id'))
                ->get(['id', 'volumes_count', 'manuscripts_count']),
            'source_ids' => $sourceIds,
        ];

        $path = $this->option('backup') ?: storage_path('app/private/backups/sources-tie-odd-ehya-citations-' . now()->format('Ymd_His') . '.json');
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
            foreach (['manuscripts', 'source_entries', 'catalog_volumes', 'catalogers'] as $table) {
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
