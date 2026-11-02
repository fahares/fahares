<?php

namespace App\Console\Commands;

use App\Console\Commands\ImportFankhaCatalogReferencesCommand as References;
use App\Models\Cataloger;
use App\Models\CatalogVolumePart;
use App\Models\Source;
use App\Sources\SourceEntrySync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * «نسخه‌پژوهی», edited by Abolfazl Hafezian Baboli (Khaneh-ye Pazhuhesh, three daftars, 1383–1385), as one source with
 * a volume for each daftar and a part for each library's catalogue in it (step 13.153, stage 2 of
 * docs/plans/catalog-volume-parts.md).
 *
 * The registry held it as «فهرست نسخه‌های خطی کتابخانه علامه طباطبائی شیراز (پزشکی)», one volume of library 64
 * with no MTIF record and «خانه پژوهش (نسخه‌پژوهی)» for publisher. That is not a book: Allameh Tabatabaei's catalogue
 * in the Shiraz medical school is Sayyed Abdulaziz Tabatabaei's, printed in «میراث اسلامی ایران» (MTIF 12440); every
 * copy on the volume cites «نسخه پژوهی: D - P».
 *
 *  - The source becomes «نسخه‌پژوهی», of no library; its volume daftar 1, and daftars 2 and 3 are made beside it,
 *    each with the MTIF record of the first printing (MTIF 11087: 21811, 22500, 30202) and its pages.
 *  - Hafezian is the editor of the three (supervisor). The parts are Fankha's table's rows 39 and 304–311: the
 *    libraries of Shiraz by Mohammad Barakat, «کتابخانه علامه روضاتی» set in order by Mohammad Reza Zadhoush.
 *  - A copy untied or on the source whose first citation is «نسخه پژوهی: D - P» goes to daftar D; one that gives a
 *    page alone («نسخه پژوهی: 218») to the one daftar whose part of its library holds the page. Its part follows
 *    (CatalogVolumePart::assign), its entry too; the counts are taken again.
 *
 * Found by source code, MTIF id, library slug and citation, never by local id. A backup of every row it changes is
 * written first; --revert restores it, removing what it made. Safe to run again.
 */
class BuildNoskhePazhuhiCommand extends Command
{
    protected $signature = 'sources:build-noskhe-pazhuhi
        {--dry-run : Report what would change without saving}
        {--backup= : Where to write the backup of the rows changed (default storage/app/private/backups/)}
        {--revert= : Restore the rows of a backup this command wrote}';

    protected $description = 'Give «نسخه‌پژوهی» its three daftars and the parts of each library, and its copies their daftar';

    public const CODE = 'catalogue-a379aa82523c';

    public const TITLE = 'نسخه‌پژوهی';

    public const PUBLISHER = 'خانه پژوهش';

    /** Each daftar: MTIF record (the first printing, MTIF 11087), pages and year. */
    public const VOLUMES = [
        1 => [21811, 656, '1383'],
        2 => [22500, 716, '1384'],
        3 => [30202, 739, '1385'],
    ];

    /** Catalogers by MTIF id: Hafezian (editor), Barakat, Zadhoush. */
    public const HAFEZIAN = 56965;

    public const BARAKAT = 37244;

    public const ZADHOUSH = 62583;

    /** Each part: daftar, library slug, title, pages, its cataloger and its row of Fankha's table. */
    public const PARTS = [
        [1, 'shyraz-aalamh-tbatbayy-64', 'کتابخانه علامه طباطبائی', 18, 42, self::BARAKAT, 309],
        [1, 'shyraz-mdrsh-hkym-266', 'کتابخانه مدرسه حکیم', 42, 48, self::BARAKAT, 306],
        [1, 'shyraz-mdrsh-mnsoryh-211', 'کتابخانه مدرسه منصوریه', 49, 55, self::BARAKAT, 307],
        [1, 'shyraz-mozh-pars-106', 'کتابخانه موزه پارس', 56, 59, self::BARAKAT, 308],
        [1, 'shyraz-dstghyb-bdraldyn-199', 'کتابخانه حجت الاسلام سید بدرالدین دستغیب', 60, 61, self::BARAKAT, 305],
        [1, 'shyraz-ansary-shykh-aabdalkrym-980', 'کتابخانه حجة الاسلام شیخ عبدالکریم انصاری', 62, 62, self::BARAKAT, 304],
        [1, 'asfhan-rodaty-syd-mhmd-aaly-100', 'کتابخانه علامه روضاتی', 145, 170, self::ZADHOUSH, 39],
        [2, 'shyraz-aalamh-tbatbayy-64', 'کتابخانه علامه طباطبائی', 107, 242, self::BARAKAT, 310],
        [3, 'shyraz-aalamh-tbatbayy-64', 'کتابخانه علامه طباطبائی', 65, 178, self::BARAKAT, 311],
    ];

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
        $libraries = DB::table('libraries')->whereIn('slug', array_column(self::PARTS, 1))->pluck('id', 'slug');
        $catalogers = DB::table('catalogers')->whereIn('mtif_entry_id', [self::HAFEZIAN, self::BARAKAT])->pluck('id', 'mtif_entry_id');
        $missing = array_merge($source ? [] : [self::CODE], array_diff(array_column(self::PARTS, 1), $libraries->keys()->all()),
            array_diff([self::HAFEZIAN, self::BARAKAT], $catalogers->keys()->all()));
        if ($missing) {
            $this->error('Missing: ' . implode(', ', array_unique($missing)));

            return self::FAILURE;
        }

        $changes = $this->changes($source);
        $moves = $this->moves($source, $libraries->all());
        if (! $changes && ! $moves) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }
        if ($this->dry) {
            return self::SUCCESS;
        }

        $this->info('Backup: ' . $this->backup($source, $moves));
        DB::transaction(function () use ($source, $libraries, $catalogers) {
            $catalogers = $catalogers->all() + [self::ZADHOUSH => $this->zadhoush()];
            $volumes = $this->writeVolumes($source, $catalogers);
            $this->writeParts($volumes, $libraries->all(), $catalogers);
            // the daftars exist now
            $moves = $this->moves($source, $libraries->all(), false);
            foreach (collect($moves)->groupBy(fn ($m) => $m[1]) as $daftar => $group) {
                DB::table('manuscripts')->whereIn('id', $group->pluck(0)->all())->update(['catalog_volume_id' => $volumes[$daftar]]);
            }
            SourceEntrySync::syncCited(DB::table('manuscripts')->whereIn('id', array_column($moves, 0))->get(['id', 'metadata', 'raw_text', 'catalog_volume_id']));
            // the copies daftar 1 had take their part too
            CatalogVolumePart::assign(DB::table('manuscripts')->whereIn('catalog_volume_id', array_values($volumes))->pluck('id'));
            $this->recount(array_values($volumes), array_values($catalogers));
        });
        self::forget($source->id);

        return self::SUCCESS;
    }

    private static function pattern(int $daftar): string
    {
        return self::TITLE . ': ' . $daftar;
    }

    /** The daftars as they stand: [number => volume], the one the source had being daftar 1. */
    private function have(Source $source): array
    {
        $rows = DB::table('source_volumes')->where('source_id', $source->id)->orderBy('id')->get();
        $have = [];
        foreach (array_keys(self::VOLUMES) as $daftar) {
            if ($volume = $rows->firstWhere('citation_pattern', self::pattern($daftar))) {
                $have[$daftar] = $volume;
            }
        }
        if (! isset($have[1]) && ($first = $rows->first(fn ($v) => ! in_array($v->id, array_map(fn ($h) => $h->id, $have), true)))) {
            $have[1] = $first;
        }

        return $have;
    }

    private static function values(int $daftar): array
    {
        [$mtif, $pages, $year] = self::VOLUMES[$daftar];

        return ['title' => self::TITLE . ' - دفتر ' . $daftar, 'volume_number' => $daftar, 'mtif_entry_id' => $mtif, 'pages_count' => $pages,
            'publication_year' => $year, 'publisher' => self::PUBLISHER, 'citation_pattern' => self::pattern($daftar)];
    }

    /** What the source, its daftars and their parts lack, reported; empty when they have it. */
    private function changes(Source $source): array
    {
        $changes = [];
        if ($source->title !== self::TITLE || $source->library_id !== null || $source->publisher !== self::PUBLISHER) {
            $changes[] = 'source';
            $this->line(sprintf('  source #%d «%s» → «%s», of no library', $source->id, $source->title, self::TITLE));
        }
        $have = $this->have($source);
        foreach (array_keys(self::VOLUMES) as $daftar) {
            $volume = $have[$daftar] ?? null;
            if (! $volume || collect(self::values($daftar))->contains(fn ($value, $column) => (string) $volume->{$column} !== (string) $value)) {
                $changes[] = "daftar {$daftar}";
                $this->line(sprintf('  daftar %d: %s', $daftar, $volume ? "#{$volume->id} «{$volume->title}» set" : 'made'));
            }
        }
        $parts = DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', collect($have)->pluck('id'))->count();
        if ($parts !== count(self::PARTS)) {
            $changes[] = 'parts';
            $this->line(sprintf('  parts: %d of %d', $parts, count(self::PARTS)));
        }

        return $changes;
    }

    /** The numbers a copy's Noskhe-pazhuhi citation gives, or null when its first citation is of another source. */
    public static function numbers(string $raw): ?array
    {
        $cited = References::parse($raw);
        // «نسخه‌پژوهی 3» too, the daftar before the colon
        if (preg_replace('/[\s\x{200C}\d]+/u', '', strtr((string) $cited['series'], ['ي' => 'ی', 'ك' => 'ک'])) !== 'نسخهپژوهی') {
            return null;
        }
        // «نسخه پژوهی: 2 - 145», «نسخه‌پژوهی 3: -108», «[نسخه‌پژوهی: [196 - 2]»
        preg_match('/نسخه[\s\x{200C}]*پژوهی\s*(\d*)\s*:([^\]]*)/u', strtr($raw, ['ي' => 'ی', 'ك' => 'ک']), $m);
        preg_match_all('/\d+/', ($m[1] ?? '') . ' ' . ($m[2] ?? ''), $n);

        return array_map('intval', $n[0]);
    }

    /**
     * The daftar of a copy's numbers: «D - P» or «P - D» with D a daftar, «D» alone, or a page alone that one part of
     * its library holds.
     *
     * @param  int[]  $numbers
     */
    public static function daftar(array $numbers, ?string $slug): ?int
    {
        // the first pair: «2 – 129 و 1 – 20» is daftar 2; the print sets daftar and page right to left, so «218-2» is
        // daftar 2 page 218, as Ehya's «محدث ارموی مخ: 1145-3» (step 13.131)
        if (count($numbers) >= 2) {
            [$a, $b] = $numbers;
            if (isset(self::VOLUMES[$a])) {
                return $a;
            }

            return isset(self::VOLUMES[$b]) ? $b : null;
        }
        if (count($numbers) !== 1) {
            return null;
        }
        if (isset(self::VOLUMES[$numbers[0]])) {
            return $numbers[0];
        }
        $held = array_filter(self::PARTS, fn ($p) => $p[1] === $slug && $numbers[0] >= $p[3] && $numbers[0] <= $p[4]);

        return count($held) === 1 ? reset($held)[0] : null;
    }

    /**
     * The copies to tie anew: [copy id, daftar, volume id it is on]. Untied or on the source, whose first citation is
     * Noskhe-pazhuhi's; the daftar's volume is looked up on writing.
     *
     * @return array<int, array{0: int, 1: int, 2: ?int}>
     */
    private function moves(Source $source, array $libraries, bool $report = true): array
    {
        $have = array_map(fn ($v) => (int) $v->id, $this->have($source));
        $slugs = array_flip($libraries) + DB::table('libraries')->pluck('slug', 'id')->all();
        $moves = [];
        $counts = [];
        $left = [];
        DB::table('manuscripts')->select('id', 'library_id', 'metadata', 'raw_text', 'catalog_volume_id')
            ->where(fn ($q) => $q->whereNull('catalog_volume_id')->orWhereIn('catalog_volume_id', array_values($have) ?: [0]))
            ->where(fn ($q) => $q->where('metadata->catalog_citation', 'like', '%پژوهی%')->orWhere('raw_text', 'like', '%پژوهی%'))
            ->orderBy('id')->chunk(5000, function ($rows) use (&$moves, &$counts, &$left, $have, $slugs) {
                foreach ($rows as $m) {
                    $raw = References::rawCitation($m->metadata, $m->raw_text);
                    $numbers = $raw === null ? null : self::numbers($raw);
                    if ($numbers === null) {
                        continue;
                    }
                    $slug = $slugs[$m->library_id] ?? null;
                    $daftar = self::daftar($numbers, $slug);
                    if ($daftar === null) {
                        $left[$slug ?? '-'] = ($left[$slug ?? '-'] ?? 0) + 1;

                        continue;
                    }
                    $on = $m->catalog_volume_id ? (int) $m->catalog_volume_id : null;
                    if (! isset($have[$daftar]) || $have[$daftar] !== $on) {
                        $moves[] = [$m->id, $daftar, $on];
                        $label = sprintf('daftar %d ← %s, %s', $daftar, $on ? "#{$on}" : 'none', $slug ?? '-');
                        $counts[$label] = ($counts[$label] ?? 0) + 1;
                    }
                }
            });
        if ($report) {
            ksort($counts, SORT_NATURAL);
            foreach ($counts as $label => $n) {
                $this->line(sprintf('  %s: %d copies', $label, $n));
            }
            ksort($left);
            foreach ($left as $slug => $n) {
                $this->line(sprintf('  left, no daftar: %s: %d copies', $slug, $n));
            }
            $this->info(sprintf('%d copies tied anew.', count($moves)));
        }

        return $moves;
    }

    /** Mohammad Reza Zadhoush, made from MTIF person 62583 when the registry has no cataloger of him. */
    private function zadhoush(): int
    {
        if ($id = DB::table('catalogers')->where('mtif_entry_id', self::ZADHOUSH)->value('id')) {
            return (int) $id;
        }
        $name = 'محمدرضا زاده‌هوش';

        return (int) DB::table('catalogers')->insertGetId(['name' => $name, 'slug' => Str::slug($name, '-', null) ?: 'cataloger-' . self::ZADHOUSH,
            'mtif_entry_id' => self::ZADHOUSH, 'metadata' => json_encode(['made_by' => $this->getName()]), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<int, int> daftar => volume id */
    private function writeVolumes(Source $source, array $catalogers): array
    {
        DB::table('sources')->where('id', $source->id)->update(['title' => self::TITLE, 'library_id' => null, 'publisher' => self::PUBLISHER,
            'compiler' => 'ابوالفضل حافظیان بابلی', 'metadata' => json_encode(['group' => 'catalogue||' . self::TITLE], JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        $have = $this->have($source);
        $model = isset($have[1]) ? DB::table('catalog_volumes')->where('id', $have[1]->id)->first() : null;
        $metadata = json_encode(['series' => self::TITLE], JSON_UNESCAPED_UNICODE);
        $volumes = [];
        foreach (array_keys(self::VOLUMES) as $daftar) {
            $values = self::values($daftar);
            if ($volume = $have[$daftar] ?? null) {
                DB::table('catalog_volumes')->where('id', $volume->id)->update($values + ['library_id' => null, 'metadata' => $metadata, 'updated_at' => now()]);
                DB::table('source_volumes')->where('id', $volume->id)->update($values + ['metadata' => $metadata, 'updated_at' => now()]);
                $volumes[$daftar] = (int) $volume->id;

                continue;
            }
            $id = DB::table('catalog_volumes')->insertGetId($values + ['library_id' => null, 'catalog_id' => $model->catalog_id ?? null, 'metadata' => $metadata,
                'manuscripts_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('source_volumes')->updateOrInsert(['id' => $id], $values + ['source_id' => $source->id, 'metadata' => $metadata, 'created_at' => now(), 'updated_at' => now()]);
            $volumes[$daftar] = $id;
        }
        // the editor on the volumes; the catalogers on their parts
        DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->delete();
        DB::table('catalog_volume_cataloger')->insert(array_map(fn ($id) => ['catalog_volume_id' => $id, 'cataloger_id' => $catalogers[self::HAFEZIAN],
            'role' => 'supervisor', 'created_at' => now(), 'updated_at' => now()], array_values($volumes)));
        DB::table('sources')->where('id', $source->id)->update(['volumes_count' => DB::table('source_volumes')->where('source_id', $source->id)->count()]);

        return $volumes;
    }

    private function writeParts(array $volumes, array $libraries, array $catalogers): void
    {
        foreach (self::PARTS as $sort => [$daftar, $slug, $title, $first, $last, $cataloger, $row]) {
            $part = CatalogVolumePart::firstOrCreate(['catalog_volume_id' => $volumes[$daftar], 'library_id' => $libraries[$slug], 'page_first' => $first],
                ['title' => $title, 'page_last' => $last, 'sort' => $sort, 'metadata' => ['fankha_row' => $row]]);
            $part->catalogers()->syncWithoutDetaching([$catalogers[$cataloger] => ['role' => 'cataloger']]);
        }
    }

    private function recount(array $volumes, array $catalogers): void
    {
        foreach ($volumes as $id) {
            DB::table('catalog_volumes')->where('id', $id)->update(['manuscripts_count' => DB::table('manuscripts')->where('catalog_volume_id', $id)->count()]);
        }
        CatalogVolumePart::recount(DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $volumes)->pluck('id')->all());
        Cataloger::recount($catalogers);
    }

    /** Writes every row the run may change to a JSON file and returns its path. */
    private function backup(Source $source, array $moves): string
    {
        $volumes = DB::table('source_volumes')->where('source_id', $source->id)->pluck('id')->all();
        $copies = array_values(array_unique(array_merge(array_column($moves, 0), DB::table('manuscripts')->whereIn('catalog_volume_id', $volumes)->pluck('id')->all())));
        $people = DB::table('catalogers')->whereIn('id', DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->select('cataloger_id'))
            ->orWhereIn('mtif_entry_id', [self::HAFEZIAN, self::BARAKAT, self::ZADHOUSH]);
        $backup = [
            'command' => $this->getName(),
            'written_at' => now()->toIso8601String(),
            'source_ids' => [$source->id],
            'sources' => DB::table('sources')->where('id', $source->id)->get(),
            'catalog_volumes' => DB::table('catalog_volumes')->whereIn('id', $volumes)->get(),
            'source_volumes' => DB::table('source_volumes')->whereIn('id', $volumes)->get(),
            'catalog_volume_cataloger' => DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->get(),
            'part_ids' => DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $volumes)->pluck('id'),
            'manuscripts' => collect(array_chunk($copies, 1000))->flatMap(fn ($ids) => DB::table('manuscripts')->whereIn('id', $ids)
                ->get(['id', 'catalog_volume_id', 'catalog_volume_part_id', 'cataloger_id']))->all(),
            'source_entries' => collect(array_chunk($copies, 1000))->flatMap(fn ($ids) => DB::table('source_entries')->where('legacy_type', 'fankha_citation')
                ->whereIn('legacy_id', $ids)->get(['id', 'source_id', 'source_volume_id', 'volume_number', 'page_start']))->all(),
            'catalogers' => $people->get(['id', 'volumes_count', 'manuscripts_count', 'supervised_count']),
        ];

        $path = $this->option('backup') ?: storage_path('app/private/backups/sources-build-noskhe-pazhuhi-' . now()->format('Ymd_His') . '.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($backup, JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /** Puts back every row of a backup, removing the daftars, parts and cataloger the run made. */
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
            FixMisnamedIsfahanCataloguesCommand::restoreColumns('manuscripts', $backup['manuscripts']);
            FixMisnamedIsfahanCataloguesCommand::restoreColumns('source_entries', $backup['source_entries']);
            DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', array_merge($volumes, $made))->whereNotIn('id', $backup['part_ids'])->delete();
            DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', array_merge($volumes, $made))->delete();
            DB::table('source_volumes')->whereIn('id', $made)->delete();
            DB::table('catalog_volumes')->whereIn('id', $made)->delete();
            foreach (['sources', 'catalog_volumes', 'source_volumes'] as $table) {
                DB::table($table)->upsert($backup[$table], ['id']);
            }
            DB::table('catalog_volume_cataloger')->insert($backup['catalog_volume_cataloger']);
            $kept = array_column($backup['catalogers'], 'id');
            DB::table('catalogers')->where('mtif_entry_id', self::ZADHOUSH)->whereNotIn('id', $kept)->where('metadata->made_by', $this->getName())->delete();
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
