<?php

namespace App\Console\Commands;

use App\Console\Commands\MergeSplitSourcesCommand as MergeSplit;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Two sources titled «فهرست کتب خطی کتابخانه‌های اصفهان» hold volumes 2–4 of Markaz-e Ehya's manuscript catalogue and
 * volumes 5–12 of Marashi's (step 13.121). The 1,515 and 4,879 copies on them are all of those two libraries and cite
 * «ف: V - P» with the volume's number; the volumes' pages, numbers of manuscripts (401-800 …) and MTIF records are those
 * books'. Only the titles, publisher («نشر نفایس مخطوطات اصفهان»), years (۱۳۳۷-۱۳۴۱) and cataloger (Rowzati) were
 * written on them, by the remediation that gave Rowzati's twelve Isfahan issues to the catalog volumes numbered 1 to 12.
 *
 * Each such volume takes its series' title, publisher, years and catalogers (from the series' nearest volume that has
 * them). The series' own record of the same volume, which sources:backfill made from a second MTIF record and which holds
 * at most a copy, is folded into it (sources:merge-split's foldVolume), and the two sources fold into their series with
 * sources:merge-split --group, their codes redirecting. Ehya's volumes 1 to 5 also take the manuscript catalogue's MTIF
 * records and page counts: they carried the photographic catalogue's (21319 …), whose copies Fankha cites «عکسی ف».
 *
 * Everything is found by source code and MTIF id. A backup of every row it changes is written first; --revert restores
 * it. Safe to run again: folded sources are not found again.
 */
class FixMisnamedIsfahanCataloguesCommand extends Command
{
    protected $signature = 'sources:fix-misnamed-isfahan
        {--dry-run : Report what would change without saving}
        {--backup= : Where to write the backup of the rows changed (default storage/app/private/backups/)}
        {--revert= : Restore the rows of a backup this command wrote}';

    protected $description = 'Fold the two misnamed «کتابخانه‌های اصفهان» sources into the Markaz-e Ehya and Marashi catalogues';

    /** Misnamed source => the series its volumes belong to. */
    public const SERIES = [
        'catalogue-f619df859857' => 'catalogue-0fc97a930b14', // Markaz-e Ehya's manuscript catalogue, volumes 2–4
        'catalogue-f80eb658096d' => 'catalogue-4c7c0fc2e037', // Marashi's manuscript catalogue, volumes 5–12
    ];

    /** The MTIF record and page count of each volume of Ehya's manuscript catalogue, which Fankha's table gives too. */
    public const MTIF = ['catalogue-0fc97a930b14' => [1 => [21323, 534], 2 => [21324, 467], 3 => [21325, 472], 4 => [21326, 467], 5 => [21429, 440]]];

    /** A page count past this is a typo of MTIF's (Marashi's twelfth volume, «378118»); the series' own record's is taken. */
    private const MAX_PAGES = 5000;

    /** Tables the backup holds whole rows of, restored as they were. */
    private const WHOLE = ['sources', 'catalog_volumes', 'source_volumes', 'source_items'];

    private bool $dry = false;

    /** @var array<int, int> the catalogers of the volumes before the fold, so the ones dropped are counted again too */
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

        $pairs = [];
        foreach (self::SERIES as $code => $seriesCode) {
            $misnamed = Source::with('volumes')->where('code', $code)->first();
            $series = FillEmptyCataloguesCommand::source($seriesCode)?->load('volumes');
            if (! $series) {
                $this->warn("{$seriesCode}: no such source.");
            } elseif (! $misnamed) {
                $this->line("{$code}: already folded into {$series->code}.");
            } else {
                $pairs[] = [$misnamed, $series, $this->plan($misnamed, $series)];
            }
        }
        $records = $this->records();

        if (! $pairs && ! $records) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }
        if ($this->dry) {
            return self::SUCCESS;
        }

        $path = $this->backup($pairs, $records);
        $this->info("Backup: {$path}");
        DB::transaction(function () use ($pairs) {
            foreach ($pairs as [$misnamed, $series, $plan]) {
                $this->fold($misnamed, $series, $plan);
            }
            $this->setRecords($this->records(false));
            $this->recount($pairs);
        });
        $this->forget(collect($pairs)->flatMap(fn ($p) => [$p[0]->id, $p[1]->id])->all());

        return self::SUCCESS;
    }

    /**
     * For each misnamed volume: its number, the series volume whose title, publisher, years and catalogers it takes,
     * and the series' own record of it, if any.
     *
     * @return array<int, object>
     */
    private function plan(Source $misnamed, Source $series): array
    {
        $this->info("#{$misnamed->id} {$misnamed->code} «{$misnamed->title}» (library {$misnamed->library_id}) folds into #{$series->id} {$series->code} «{$series->title}»");
        $plan = [];
        foreach ($misnamed->volumes as $volume) {
            $number = $volume->volume_number ?? MergeSplit::volumeNumber($volume->title);
            $model = $series->volumes->filter(fn ($v) => filled($v->publisher) && ! MergeSplit::isPart($v->title)
                && preg_match('/-\s*جلد\s*[0-9۰-۹]+$/u', $v->title))
                ->sortBy(fn ($v) => [abs($v->volume_number - $number), $v->volume_number])->first();
            if (! $number || ! $model) {
                $this->warn("  #{$volume->id} «{$volume->title}»: no volume number or no series volume to follow; left as it is.");

                continue;
            }
            $same = $series->volumes->first(fn ($v) => $v->volume_number === $number && ! MergeSplit::isPart($v->title));
            $title = preg_replace('/[0-9۰-۹]+$/u', (string) $number, $model->title);
            $entries = DB::table('source_entries')->where('source_volume_id', $volume->id)->count();
            $this->line(sprintf('  volume %d #%d «%s» → «%s», %s, %s; %d entries%s', $number, $volume->id, $volume->title, $title,
                $model->publisher, $model->publication_year, $entries,
                $same ? sprintf('; the series\' record #%d «%s» (MTIF %s, %d entries) folds into it', $same->id, $same->title,
                    $same->mtif_entry_id ?? '-', DB::table('source_entries')->where('source_volume_id', $same->id)->count()) : ''));
            $plan[] = (object) ['volume' => $volume, 'number' => $number, 'model' => $model, 'same' => $same, 'title' => $title];
        }

        return $plan;
    }

    /** @param  array<int, object>  $plan */
    private function fold(Source $misnamed, Source $series, array $plan): void
    {
        foreach ($plan as $p) {
            $id = $p->volume->id;
            $fill = ['title' => $p->title, 'publisher' => $p->model->publisher, 'publication_year' => $p->model->publication_year, 'updated_at' => now()];
            foreach (['source_volumes', 'catalog_volumes'] as $table) {
                DB::table($table)->where('id', $id)->update($fill
                    + ((int) DB::table($table)->where('id', $id)->value('pages_count') > self::MAX_PAGES ? ['pages_count' => null] : []));
            }
            if ($p->same) {
                // the record's entries, copies and catalogers move to the volume, and what the volume lacks it gives
                MergeSplit::foldVolume($p->same->id, $id);
            }
            $catalogers = DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $p->model->id)->get();
            DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $id)->delete();
            DB::table('catalog_volume_cataloger')->insert($catalogers->map(fn ($c) => collect((array) $c)->except('id')
                ->merge(['catalog_volume_id' => $id, 'created_at' => now(), 'updated_at' => now()])->all())->all());
        }

        // merge-split takes a blank column of the kept source from the folded one: not Rowzati's publisher
        DB::table('sources')->where('id', $misnamed->id)->update(['publisher' => null, 'publication_year' => null, 'compiler' => null]);
        $this->call('sources:merge-split', ['--group' => [$series->code . ',' . $misnamed->code], '--only' => true]);
    }

    /**
     * The Ehya volumes whose MTIF record is not the manuscript catalogue's: [volume id, number, record, pages, record now].
     *
     * @return array<int, array{0:int, 1:int, 2:int, 3:int, 4:?int}>
     */
    private function records(bool $report = true): array
    {
        $records = [];
        foreach (self::MTIF as $code => $numbers) {
            $series = FillEmptyCataloguesCommand::source($code);
            foreach ($numbers as $number => [$mtif, $pages]) {
                // before the fold the volume is still on the misnamed source; the one holding copies is meant
                $volume = DB::table('source_volumes')->whereIn('source_id', array_filter([$series?->id,
                    ...array_map(fn ($c) => Source::where('code', $c)->value('id'), array_keys(self::SERIES, $code))]))
                    ->where('volume_number', $number)->get()
                    ->sortByDesc(fn ($v) => DB::table('source_entries')->where('source_volume_id', $v->id)->count())->first();
                if ($volume && ((int) $volume->mtif_entry_id !== $mtif || (int) $volume->pages_count !== $pages)) {
                    $records[] = [$volume->id, $number, $mtif, $pages, $volume->mtif_entry_id];
                }
            }
        }
        foreach ($report ? $records : [] as [$id, $number, $mtif, $pages, $was]) {
            $this->line(sprintf('  Ehya volume %d #%d: MTIF %s → %d, %d pages', $number, $id, $was ?? '-', $mtif, $pages));
        }

        return $records;
    }

    private function setRecords(array $records): void
    {
        foreach ($records as [$id, , $mtif, $pages]) {
            foreach (['source_volumes', 'catalog_volumes'] as $table) {
                // the record is the volume's alone
                DB::table($table)->where('mtif_entry_id', $mtif)->where('id', '!=', $id)->update(['mtif_entry_id' => null]);
                DB::table($table)->where('id', $id)->update(['mtif_entry_id' => $mtif, 'pages_count' => $pages, 'updated_at' => now()]);
            }
        }
    }

    /** The copies of each moved volume, and the volumes and copies of every cataloger they had or have now. */
    private function recount(array $pairs): void
    {
        $ids = collect($pairs)->flatMap(fn ($p) => collect($p[2])->pluck('volume.id'))->all();
        foreach ($ids as $id) {
            DB::table('catalog_volumes')->where('id', $id)->update(['manuscripts_count' => DB::table('manuscripts')->where('catalog_volume_id', $id)->count()]);
        }
        $catalogers = array_merge($this->touchedCatalogers, DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $ids)->pluck('cataloger_id')->all());
        foreach (array_unique($catalogers) as $cataloger) {
            $volumes = DB::table('catalog_volume_cataloger')->where('cataloger_id', $cataloger)->pluck('catalog_volume_id')->unique();
            // the same counts as LinkCatalogVolumesCommand::recountVolumesAndCatalogers
            DB::table('catalogers')->where('id', $cataloger)->update(['volumes_count' => $volumes->count(), 'manuscripts_count' => DB::table('manuscripts')
                ->where(fn ($q) => $q->where('cataloger_id', $cataloger)->orWhereIn('catalog_volume_id', $volumes))->count()]);
        }
    }

    /** Writes every row the fold may change to a JSON file and returns its path. */
    private function backup(array $pairs, array $records): string
    {
        $sources = collect($pairs)->flatMap(fn ($p) => [$p[0]->id, $p[1]->id])->all();
        $misnamed = collect($pairs)->map(fn ($p) => $p[0]->id)->all();
        $same = collect($pairs)->flatMap(fn ($p) => collect($p[2])->pluck('same.id'))->filter()->values()->all();
        $volumes = DB::table('source_volumes')->whereIn('source_id', $sources)->pluck('id')
            ->merge(array_column($records, 0))->unique()->values()->all();
        $items = DB::table('source_items')->whereIn('source_volume_id', $volumes)->orWhereIn('source_id', $misnamed)->pluck('id')->all();
        $this->touchedCatalogers = DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->pluck('cataloger_id')->unique()->all();
        $locations = fn ($q) => collect($misnamed)->each(fn ($id) => $q->orWhere('locations', 'like', '%"source_id":' . $id . ',%'));

        $backup = [
            'command' => $this->getName(),
            'written_at' => now()->toIso8601String(),
            'codes' => array_merge(array_keys(self::SERIES), array_values(self::SERIES)),
            'sources' => DB::table('sources')->whereIn('id', $sources)->get(),
            'catalog_volumes' => DB::table('catalog_volumes')->whereIn('id', $volumes)->get(),
            'source_volumes' => DB::table('source_volumes')->whereIn('id', $volumes)->get(),
            'source_items' => DB::table('source_items')->whereIn('id', $items)->get(),
            'catalog_volume_cataloger' => DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->get(),
            'source_entries' => DB::table('source_entries')
                ->where(fn ($q) => $q->whereIn('source_id', $misnamed)->orWhereIn('source_volume_id', $same)->orWhere($locations))
                ->get(['id', 'source_id', 'source_volume_id', 'source_item_id', 'volume_number', 'text_volume_number', 'locations']),
            'field_evidence' => DB::table('field_evidence')->whereIn('source_id', $misnamed)->get(['id', 'source_id', 'volume_number']),
            'source_pages' => DB::table('source_pages')->whereIn('source_volume_id', $same)->get(['id', 'source_volume_id']),
            'source_item_refs' => DB::table('source_item_refs')->whereIn('target_item_id', $items)->get(['id', 'target_item_id']),
            'manuscripts' => DB::table('manuscripts')->whereIn('catalog_volume_id', $same)->get(['id', 'catalog_volume_id']),
            'manuscript_catalog_references' => DB::table('manuscript_catalog_references')->whereIn('catalog_volume_id', $same)->get(['id', 'catalog_volume_id']),
            'editions' => [
                'sources' => DB::table('sources')->whereIn('edition_of_id', $misnamed)->get(['id', 'edition_of_id']),
                'source_volumes' => DB::table('source_volumes')->whereIn('edition_of_id', $same)->get(['id', 'edition_of_id']),
                'catalog_volumes' => DB::table('catalog_volumes')->whereIn('edition_of_id', $same)->get(['id', 'edition_of_id']),
            ],
            'catalogers' => DB::table('catalogers')->whereIn('id', $this->touchedCatalogers)->get(['id', 'volumes_count', 'manuscripts_count']),
        ];

        $path = $this->option('backup') ?: storage_path('app/private/backups/sources-fix-misnamed-isfahan-' . now()->format('Ymd_His') . '.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($backup, JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /** Puts back every row of a backup: the folded sources and volumes, their catalogers, and what moved off them. */
    private function revert(string $file): int
    {
        $backup = is_file($file) ? json_decode(file_get_contents($file), true) : null;
        if (($backup['command'] ?? null) !== $this->getName()) {
            $this->error("{$file}: not a backup of {$this->getName()}.");

            return self::FAILURE;
        }
        $volumes = array_column($backup['source_volumes'], 'id');
        $this->info(sprintf('Restoring %d sources, %d volumes, %d entries, %d copies from %s', count($backup['sources']), count($volumes),
            count($backup['source_entries']), count($backup['manuscripts']), $file));

        DB::transaction(function () use ($backup, $volumes) {
            foreach (self::WHOLE as $table) {
                foreach (array_chunk($backup[$table], 500) as $rows) {
                    DB::table($table)->upsert($rows, ['id']);
                }
            }
            DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->delete();
            DB::table('catalog_volume_cataloger')->insert($backup['catalog_volume_cataloger']);
            foreach (['source_entries', 'field_evidence', 'source_pages', 'source_item_refs', 'manuscripts', 'manuscript_catalog_references', 'catalogers'] as $table) {
                self::restoreColumns($table, $backup[$table]);
            }
            foreach ($backup['editions'] as $table => $rows) {
                self::restoreColumns($table, $rows);
            }
        });
        $this->forget(array_column($backup['sources'], 'id'));
        $this->info('Restored.');

        return self::SUCCESS;
    }

    /** Sets the columns a backup holds back on each row, one statement for the rows that had the same values. */
    public static function restoreColumns(string $table, array $rows): void
    {
        foreach (collect($rows)->groupBy(fn ($r) => json_encode(collect($r)->except('id')->all())) as $group) {
            $values = collect($group->first())->except('id')->all();
            foreach ($group->pluck('id')->chunk(1000) as $ids) {
                DB::table($table)->whereIn('id', $ids->all())->update($values);
            }
        }
    }

    private function forget(array $sourceIds): void
    {
        Cache::forget('sources:shelf');
        foreach (array_filter($sourceIds) as $id) {
            Cache::forget("sources:copies:{$id}");
            foreach (['works', 'manuscripts'] as $type) {
                Cache::forget('sources:linked:' . md5(implode('|', [$id, $type, null, ''])));
            }
        }
    }
}
