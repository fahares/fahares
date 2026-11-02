<?php

namespace App\Console\Commands;

use App\Console\Commands\ImportFankhaCatalogReferencesCommand as References;
use App\Models\Cataloger;
use App\Models\CatalogVolumePart;
use App\Sources\SourceEntrySync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives a catalogue that holds several libraries' catalogues in each volume («نشریه» of Tehran University) its parts,
 * from a data file approved by the user (step 13.159, stage 3 of docs/plans/catalog-volume-parts.md):
 *
 *  - «folds»: sources that are volumes of it held as a library's catalogue («… ثقة الاسلام تبریز (نشریه دانشگاه
 *    تهران ۴)»). With «volume», the source's volume becomes that volume of the catalogue; the rest of its copies go
 *    to the volume their citation names, and the source, emptied, is folded in (metadata.merged_codes, which
 *    /sources/{code} redirects).
 *    With «keep», the source stays: only its copies whose first citation is the catalogue's move («اوراق عتیق» on
 *    the catalogue of Zanjan's Imam Jom'e school).
 *  - Each copy untied, on the catalogue or on a folded source, whose first citation is the catalogue's («نشریه: 5 -
 *    132», or «132-5» as the print sets it right to left) goes to that volume.
 *  - «create»: the catalogue, when the registry has it not, is made with its volumes (source, catalog volume and
 *    source volume under one id), and its revert removes it.
 *  - «editors» are the people of every volume (supervisor); «parts» are made with their library, pages and catalogers,
 *    a cataloger found by MTIF id or name, made when the registry has none. Each copy then takes its part
 *    (CatalogVolumePart::assign); counts are taken again.
 *
 * Found by source code, library slug, MTIF id and Fankha's row, never by local id. A backup of every row it changes is
 * written first; --revert restores it, removing what it made. Safe to run again.
 */
class ImportVolumePartsCommand extends Command
{
    protected $signature = 'catalogs:import-volume-parts
        {--data= : The data file (database/data/volume_parts_*.json)}
        {--dry-run : Report what would change without saving}
        {--backup= : Where to write the backup of the rows changed (default storage/app/private/backups/)}
        {--revert= : Restore the rows of a backup this command wrote}';

    protected $description = 'Give a catalogue of several libraries its parts and the copies of each, from a data file';

    /** The ordinal of a daftar written out. */
    private const ORDINALS = ['یازدهم' => 11, 'دوازدهم' => 12, 'سیزدهم' => 13, 'نخستین' => 1, 'اول' => 1, 'یکم' => 1, 'دوم' => 2, 'سوم' => 3,
        'چهارم' => 4, 'پنجم' => 5, 'ششم' => 6, 'هفتم' => 7, 'هشتم' => 8, 'نهم' => 9, 'دهم' => 10];

    private bool $dry = false;

    private array $data = [];

    public function handle(): int
    {
        if ($file = $this->option('revert')) {
            return $this->revert($file);
        }
        $path = $this->option('data');
        $this->data = $path && is_file($path) ? (json_decode(file_get_contents($path), true) ?? []) : [];
        if (! isset($this->data['source'], $this->data['series'], $this->data['parts'])) {
            $this->error('A data file with source, series and parts is needed: --data=database/data/volume_parts_*.json');

            return self::FAILURE;
        }
        $this->dry = (bool) $this->option('dry-run');
        if ($this->dry) {
            $this->warn('Dry run: nothing is saved.');
        }

        $source = FillEmptyCataloguesCommand::source($this->data['source']);
        $slugs = array_values(array_filter(array_unique(array_column($this->data['parts'], 'library'))));
        $libraries = DB::table('libraries')->whereIn('slug', $slugs)->pluck('id', 'slug')->all();
        $create = $source ? null : ($this->data['create'] ?? null);
        $missing = array_merge($source || $create ? [] : [$this->data['source']], array_diff($slugs, array_keys($libraries)));
        if ($missing) {
            $this->error('Missing: ' . implode(', ', $missing));

            return self::FAILURE;
        }

        $sourceId = $source?->id ?? 0;
        $folds = $this->folds($sourceId);
        if ($create) {
            // the volumes it will have, to count what goes to them
            $folds['virtual'] = array_fill_keys(array_column($create['volumes'], 'number'), 0);
            $this->line(sprintf('  source to make: «%s» (%s), %d volumes', $create['title'], $this->data['source'], count($create['volumes'])));
        }
        $moves = $this->moves($sourceId, $folds);
        $parts = $this->partsToMake($sourceId, $folds);
        $numbers = array_merge(DB::table('source_volumes')->where('source_id', $sourceId)->pluck('volume_number')->all(), array_keys($folds['volumes']), array_keys($folds['virtual'] ?? []));
        $catalogers = $this->catalogers(false, $numbers);
        $editors = $this->editorsToSet($sourceId, $folds);
        if (! $create && ! $folds['volumes'] && ! $folds['sources'] && ! $moves && ! $parts && ! $editors) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }
        $this->line(sprintf('  catalogers to make: %s', implode('، ', array_keys(array_filter($catalogers, fn ($id) => $id === null))) ?: 'none'));
        if ($this->dry) {
            return self::SUCCESS;
        }

        $this->info('Backup: ' . $this->backup($sourceId, $folds, $moves, $create !== null));
        unset($folds['virtual']);
        DB::transaction(function () use (&$source, $create, $folds, $libraries) {
            $source ??= $this->makeSource($create);
            $volumes = $this->writeVolumes($source->id, $folds);
            $catalogers = $this->catalogers(true, array_keys($volumes));
            $moves = $this->moves($source->id, $this->folds($source->id), false);
            foreach (collect($moves)->groupBy(fn ($m) => $m[1]) as $number => $group) {
                foreach ($group->pluck(0)->chunk(1000) as $ids) {
                    DB::table('manuscripts')->whereIn('id', $ids->all())->update(['catalog_volume_id' => $volumes[$number]]);
                    // the legacy references of the folded volumes follow
                    DB::table('manuscript_catalog_references')->whereIn('manuscript_id', $ids->all())->whereIn('catalog_volume_id', $folds['all'])
                        ->update(['catalog_volume_id' => $volumes[$number]]);
                }
            }
            foreach (array_chunk(array_column($moves, 0), 1000) as $ids) {
                SourceEntrySync::syncCited(DB::table('manuscripts')->whereIn('id', $ids)->get(['id', 'metadata', 'raw_text', 'catalog_volume_id']));
            }
            $this->dropFolded($source->id, $folds);
            $this->writeEditors(array_values($volumes), $catalogers);
            $this->writeParts($volumes, $libraries, $catalogers);
            CatalogVolumePart::assign(DB::table('manuscripts')->whereIn('catalog_volume_id', array_values($volumes))->pluck('id'));
            $this->recount($source->id, array_values($volumes), $folds['kept']);
        });
        self::forget($source->id);

        return self::SUCCESS;
    }

    private function series(): string
    {
        return $this->data['series'];
    }

    /** The series and the other names its copies cite it by («چهار کتابخانه مشهد - ف»). */
    private function names(): array
    {
        return array_merge([$this->series()], $this->data['aliases'] ?? []);
    }

    private static function bare(?string $text): string
    {
        return preg_replace('/[\s\x{200C}\d]+/u', '', strtr((string) $text, ['ي' => 'ی', 'ك' => 'ک']));
    }

    /**
     * The folded sources: [volumes => [number => volume id] to become the catalogue's, sources => [id => code] to fold,
     * all => their volume ids].
     */
    private function folds(int $sourceId): array
    {
        $have = DB::table('source_volumes')->where('source_id', $sourceId)->pluck('id', 'volume_number')->all();
        $folds = ['volumes' => [], 'sources' => [], 'all' => [], 'kept' => []];
        foreach ($this->data['folds'] ?? [] as $fold) {
            $source = DB::table('sources')->where('code', $fold['code'])->first();
            if (! $source) {
                continue;
            }
            $ids = DB::table('source_volumes')->where('source_id', $source->id)->orderBy('id')->pluck('id')->all();
            if (! empty($fold['keep'])) {
                $folds['all'] = array_merge($folds['all'], $ids);
                $folds['kept'] = array_merge($folds['kept'], $ids);
                $this->line(sprintf('  take the catalogue\'s copies from «%s» (%s)', $source->title, $source->code));

                continue;
            }
            $folds['sources'][$source->id] = $source->code;
            $folds['all'] = array_merge($folds['all'], $ids);
            if (isset($fold['volume']) && ! isset($have[$fold['volume']]) && $ids) {
                $folds['volumes'][$fold['volume']] = $ids[0];
                $this->line(sprintf('  volume %d: #%d of «%s» (%s)', $fold['volume'], $ids[0], $source->title, $source->code));
            }
            $this->line(sprintf('  fold «%s» (%s) into the catalogue', $source->title, $source->code));
        }

        return $folds;
    }

    /** The numbers a copy's citation of the catalogue gives, or null when its first citation is of another source. */
    public static function numbers(string $raw, string|array $series): ?array
    {
        $cited = self::bare(References::parse($raw)['series']);
        $series = collect((array) $series)->first(fn ($name) => self::bare($name) === $cited);
        if ($series === null) {
            return null;
        }
        $name = implode('[\s\x{200C}]*', array_map(fn ($w) => preg_quote($w, '/'), preg_split('/[\s\x{200C}]+|(?=-)|(?<=-)/u', $series, -1, PREG_SPLIT_NO_EMPTY)));
        // «دفتر نهم - 74» is daftar 9
        $raw = preg_replace_callback('/(?:دفتر|جلد)\s*(' . implode('|', array_keys(self::ORDINALS)) . ')/u', fn ($o) => (string) self::ORDINALS[$o[1]], strtr($raw, ['ي' => 'ی', 'ك' => 'ک']));
        // «سه کتابخانه اصفهان؛ ف: 12», «… - ف: 12»
        preg_match('/' . $name . '(?:[\s\-–؛]*ف)?\s*(\d*)\s*:([^\]]*)/u', $raw, $m);
        preg_match_all('/\d+/', ($m[1] ?? '') . ' ' . ($m[2] ?? ''), $n);

        return array_map('intval', $n[0]);
    }

    /**
     * The volume of a copy's numbers: «V - P» or «P - V» with V one of the volumes, or «V» alone.
     *
     * @param  int[]  $numbers
     * @param  int[]  $volumes  the catalogue's volume numbers
     */
    public static function volume(array $numbers, array $volumes): ?int
    {
        $numbers = array_slice($numbers, 0, 2);
        // a book of one volume: what the citation gives is a page of it
        if (count($volumes) === 1 && $numbers) {
            return $volumes[0];
        }
        if (count($numbers) === 1) {
            return in_array($numbers[0], $volumes, true) ? $numbers[0] : null;
        }
        if (count($numbers) !== 2) {
            return null;
        }
        // the first when both can be a volume («2 - 5»)
        foreach ($numbers as $n) {
            if (in_array($n, $volumes, true)) {
                return $n;
            }
        }

        return null;
    }

    /**
     * The copies to tie anew: [copy id, volume number, volume id it is on]. Untied, on the catalogue or on a folded
     * source, whose first citation is the catalogue's.
     *
     * @return array<int, array{0: int, 1: int, 2: ?int}>
     */
    private function moves(int $sourceId, array $folds, bool $report = true): array
    {
        $have = DB::table('source_volumes')->where('source_id', $sourceId)->pluck('id', 'volume_number')->map(fn ($id) => (int) $id)->all() + $folds['volumes'] + ($folds['virtual'] ?? []);
        $numbers = array_map('intval', array_keys($have));
        $on = array_merge(array_values($have), $folds['all']);
        $moves = [];
        $counts = [];
        $left = [];
        // the series as written, and with the Arabic ي and ك («دليل المخطوطات»)
        $likes = array_unique(['%' . $this->series() . '%', '%' . strtr($this->series(), ['ی' => 'ي', 'ک' => 'ك']) . '%']);
        DB::table('manuscripts')->select('id', 'metadata', 'raw_text', 'catalog_volume_id')
            ->where(fn ($q) => $q->whereNull('catalog_volume_id')->orWhereIn('catalog_volume_id', $on ?: [0]))
            ->where(function ($q) use ($likes) {
                foreach ($likes as $like) {
                    $q->orWhere('metadata->catalog_citation', 'like', $like)->orWhere('raw_text', 'like', $like);
                }
            })
            ->orderBy('id')->chunk(5000, function ($rows) use (&$moves, &$counts, &$left, $have, $numbers, $folds) {
                foreach ($rows as $m) {
                    $raw = References::rawCitation($m->metadata, $m->raw_text);
                    // a citation that lost its opening bracket («بی‌کا تا قرن 10 نشریه: 7 - 537]»)
                    if ($raw === null && preg_match('/(' . preg_quote($this->series(), '/') . '\s*:[^\]]*)\]/u', (string) $m->raw_text, $b)) {
                        $raw = '[' . $b[1] . ']';
                    }
                    $cited = $raw === null ? null : self::numbers($raw, $this->names());
                    if ($cited === null) {
                        if ($m->catalog_volume_id && in_array((int) $m->catalog_volume_id, array_diff($folds['all'], $folds['kept']), true)) {
                            $left['another source, on a folded volume'] = ($left['another source, on a folded volume'] ?? 0) + 1;
                        }

                        continue;
                    }
                    $number = self::volume($cited, $numbers);
                    $current = $m->catalog_volume_id ? (int) $m->catalog_volume_id : null;
                    if ($number === null) {
                        $key = $current && in_array($current, $folds['all'], true) ? 'no volume, on a folded volume' : 'no volume';
                        $left[$key] = ($left[$key] ?? 0) + 1;

                        continue;
                    }
                    if ($have[$number] !== $current || $have[$number] === 0) {
                        $moves[] = [$m->id, $number, $current];
                        $label = sprintf('volume %d ← %s', $number, $current ? "#{$current}" : 'none');
                        $counts[$label] = ($counts[$label] ?? 0) + 1;
                    }
                }
            });
        if ($report) {
            ksort($counts, SORT_NATURAL);
            foreach ($counts as $label => $n) {
                $this->line(sprintf('  %s: %d copies', $label, $n));
            }
            foreach ($left as $label => $n) {
                $this->line(sprintf('  left, %s: %d copies', $label, $n));
            }
            $this->info(sprintf('%d copies tied anew.', count($moves)));
        }

        return $moves;
    }

    /** What a part is known by: its row of Fankha's table, or the data file's key for a part the table has not. */
    private static function partKey(array $part): string
    {
        return isset($part['fankha_row']) ? (string) $part['fankha_row'] : 'k:' . ($part['key'] ?? '');
    }

    /** The parts of the data file the catalogue lacks, reported. */
    private function partsToMake(int $sourceId, array $folds): array
    {
        $have = DB::table('source_volumes')->where('source_id', $sourceId)->pluck('id', 'volume_number')->all() + $folds['volumes'] + ($folds['virtual'] ?? []);
        $made = DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', array_values($have) ?: [0])->get(['catalog_volume_id', 'metadata'])
            ->map(fn ($p) => $p->catalog_volume_id . '|' . self::partKey(json_decode($p->metadata ?? '{}', true)))->all();
        $missing = array_filter($this->data['parts'], fn ($p) => isset($have[$p['daftar']]) && ! in_array($have[$p['daftar']] . '|' . self::partKey($p), $made, true));
        $none = array_filter($this->data['parts'], fn ($p) => ! isset($have[$p['daftar']]));
        $this->line(sprintf('  parts to make: %d of %d%s', count($missing), count($this->data['parts']),
            $none ? sprintf(' (%d of a volume the catalogue has not: %s)', count($none), implode(', ', array_unique(array_column($none, 'daftar')))) : ''));

        return $missing;
    }

    /** Makes the catalogue the data file describes, each volume a catalog volume and a source volume under one id. */
    private function makeSource(array $create): \App\Models\Source
    {
        $id = DB::table('sources')->insertGetId(['code' => $this->data['source'], 'kind' => $create['kind'] ?? \App\Models\Source::KIND_LIBRARY_CATALOGUE,
            'rank' => \App\Models\Source::KIND_RANKS[$create['kind'] ?? \App\Models\Source::KIND_LIBRARY_CATALOGUE] ?? 50, 'title' => $create['title'],
            'compiler' => $create['compiler'] ?? null, 'publisher' => $create['publisher'] ?? null, 'publication_year' => $create['year'] ?? null,
            'volumes_count' => count($create['volumes']), 'metadata' => json_encode(['group' => 'catalogue||' . $create['title']], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now()]);
        foreach ($create['volumes'] as $volume) {
            $values = ['title' => sprintf($this->data['volume_title'], $volume['number']), 'volume_number' => $volume['number'], 'publisher' => $create['publisher'] ?? null,
                'publication_year' => $volume['year'] ?? $create['year'] ?? null, 'pages_count' => $volume['pages'] ?? null, 'mtif_entry_id' => $volume['mtif'] ?? null,
                'citation_pattern' => $this->series() . ': ' . $volume['number']];
            $volumeId = DB::table('catalog_volumes')->insertGetId($values + ['library_id' => null, 'catalog_id' => 1, 'manuscripts_count' => 0,
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('source_volumes')->insert($values + ['id' => $volumeId, 'source_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->line(sprintf('  made «%s» (%s)', $create['title'], $this->data['source']));

        return \App\Models\Source::findOrFail($id);
    }

    /** The volumes whose people are not the editors alone. */
    private function editorsToSet(int $sourceId, array $folds): array
    {
        $ids = array_merge(DB::table('source_volumes')->where('source_id', $sourceId)->pluck('id')->all(), array_values($folds['volumes']));
        // the editors' cataloger ids (one not made yet is none)
        $editors = collect($this->catalogers(false, []))->only(array_column($this->data['editors'] ?? [], 'name'))->map(fn ($id) => (int) $id)->sort()->values()->all();
        $differ = [];
        foreach ($ids as $id) {
            $people = DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $id)->get(['cataloger_id', 'role']);
            if ($people->pluck('cataloger_id')->map(fn ($c) => (int) $c)->sort()->values()->all() !== $editors
                || $people->contains(fn ($p) => $p->role !== 'supervisor')) {
                $differ[] = $id;
            }
        }
        if ($differ) {
            $this->line(sprintf('  editors to set on %d volumes', count($differ)));
        }

        return $differ;
    }

    /**
     * The editors and the catalogers of the parts of the volumes given: [name => cataloger id, or null when there is
     * none yet and $make is false].
     *
     * @return array<string, ?int>
     */
    private function catalogers(bool $make, array $numbers): array
    {
        $parts = collect($this->data['parts'])->filter(fn ($p) => in_array($p['daftar'], array_map('intval', $numbers), true));
        $people = collect($this->data['editors'] ?? [])->concat($parts->flatMap(fn ($p) => $p['catalogers']))->unique('name');
        $all = DB::table('catalogers')->get(['id', 'name', 'mtif_entry_id']);
        $ids = [];
        foreach ($people as $person) {
            $found = isset($person['mtif']) ? $all->firstWhere('mtif_entry_id', $person['mtif']) : null;
            $found ??= $all->first(fn ($c) => self::bare($c->name) === self::bare($person['name']));
            if (! $found && $make) {
                $slug = Str::slug($person['name'], '-', null) ?: 'cataloger';
                $slug = DB::table('catalogers')->where('slug', $slug)->exists() ? $slug . '-' . Str::lower(Str::random(4)) : $slug;
                $id = DB::table('catalogers')->insertGetId(['name' => $person['name'], 'slug' => $slug, 'mtif_entry_id' => $person['mtif'] ?? null,
                    'metadata' => json_encode(['made_by' => $this->marker()], JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
                $found = (object) ['id' => $id];
            }
            $ids[$person['name']] = $found ? (int) $found->id : null;
        }

        return $ids;
    }

    /** What marks the catalogers this data file made, so its revert removes them and no other's. */
    private function marker(): string
    {
        return $this->getName() . ' ' . $this->data['source'];
    }

    /** @return array<int, int> volume number => volume id */
    private function writeVolumes(int $sourceId, array $folds): array
    {
        foreach ($folds['volumes'] as $number => $id) {
            $values = ['volume_number' => $number, 'title' => sprintf($this->data['volume_title'], $number), 'citation_pattern' => $this->series() . ': ' . $number];
            DB::table('source_volumes')->where('id', $id)->update($values + ['source_id' => $sourceId, 'updated_at' => now()]);
            DB::table('catalog_volumes')->where('id', $id)->update($values + ['library_id' => null, 'updated_at' => now()]);
        }

        return DB::table('source_volumes')->where('source_id', $sourceId)->pluck('id', 'volume_number')->map(fn ($id) => (int) $id)->all();
    }

    /** The folded sources' volumes the catalogue did not take, emptied, go; so do the sources, their codes kept. */
    private function dropFolded(int $sourceId, array $folds): void
    {
        $drop = array_diff($folds['all'], array_values($folds['volumes']), $folds['kept']);
        // what is left on them is of another source, tied to it by mistake: it is untied
        $left = DB::table('manuscripts')->whereIn('catalog_volume_id', $drop ?: [0])->pluck('id')->all();
        if ($left) {
            DB::table('manuscripts')->whereIn('id', $left)->update(['catalog_volume_id' => null]);
            SourceEntrySync::syncCited(DB::table('manuscripts')->whereIn('id', $left)->get(['id', 'metadata', 'raw_text', 'catalog_volume_id']));
            $this->line(sprintf('  untied, of another source on a folded volume: %d copies', count($left)));
        }
        foreach ($drop as $id) {
            DB::table('source_entries')->where('source_volume_id', $id)->update(['source_volume_id' => null, 'source_id' => null]);
            DB::table('manuscript_catalog_references')->where('catalog_volume_id', $id)->update(['catalog_volume_id' => null]);
            DB::table('source_volumes')->where('id', $id)->delete();
            DB::table('catalog_volumes')->where('id', $id)->delete();
        }
        $folded = [];
        foreach ($folds['sources'] as $id => $code) {
            if (! DB::table('source_volumes')->where('source_id', $id)->exists()) {
                DB::table('sources')->where('id', $id)->delete();
                $folded[] = $code;
            }
        }
        $metadata = json_decode(DB::table('sources')->where('id', $sourceId)->value('metadata') ?? '{}', true) ?: [];
        if ($folded) {
            $metadata['merged_codes'] = array_values(array_unique(array_merge($metadata['merged_codes'] ?? [], $folded)));
        }
        DB::table('sources')->where('id', $sourceId)->update(['metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'volumes_count' => DB::table('source_volumes')->where('source_id', $sourceId)->count(), 'updated_at' => now()]);
    }

    private function writeEditors(array $volumes, array $catalogers): void
    {
        DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->delete();
        foreach ($volumes as $id) {
            foreach ($this->data['editors'] ?? [] as $editor) {
                DB::table('catalog_volume_cataloger')->insert(['catalog_volume_id' => $id, 'cataloger_id' => $catalogers[$editor['name']],
                    'role' => 'supervisor', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    private function writeParts(array $volumes, array $libraries, array $catalogers): void
    {
        foreach ($this->data['parts'] as $sort => $part) {
            if (! isset($volumes[$part['daftar']])) {
                continue;
            }
            $volume = $volumes[$part['daftar']];
            $key = isset($part['fankha_row']) ? ['fankha_row' => $part['fankha_row']] : ['key' => $part['key']];
            $row = DB::table('catalog_volume_parts')->where('catalog_volume_id', $volume)->where('metadata->' . array_key_first($key), reset($key))->first();
            $model = $row ? CatalogVolumePart::find($row->id) : CatalogVolumePart::create(['catalog_volume_id' => $volume,
                'library_id' => $part['library'] ? $libraries[$part['library']] : null, 'title' => $part['title'], 'page_first' => $part['pages'][0],
                'page_last' => $part['pages'][1], 'sort' => $sort, 'metadata' => $key]);
            $model->catalogers()->syncWithoutDetaching(collect($part['catalogers'])->mapWithKeys(fn ($c) => [$catalogers[$c['name']] => ['role' => 'cataloger']])->all());
        }
    }

    private function recount(int $sourceId, array $volumes, array $kept = []): void
    {
        foreach (array_merge($volumes, $kept) as $id) {
            DB::table('catalog_volumes')->where('id', $id)->update(['manuscripts_count' => DB::table('manuscripts')->where('catalog_volume_id', $id)->count()]);
        }
        $parts = DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $volumes)->pluck('id')->all();
        CatalogVolumePart::recount($parts);
        Cataloger::recount(DB::table('catalog_volume_part_cataloger')->whereIn('catalog_volume_part_id', $parts)->pluck('cataloger_id')
            ->merge(DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', array_merge($volumes, $kept))->pluck('cataloger_id'))
            ->merge(DB::table('catalogers')->where('metadata->made_by', $this->marker())->pluck('id')));
    }

    /** Writes every row the run may change to a JSON file and returns its path. */
    private function backup(int $sourceId, array $folds, array $moves, bool $making = false): string
    {
        $sources = array_merge([$sourceId], array_keys($folds['sources']));
        // a kept source's volumes too: their counts are taken again
        $volumes = array_merge(DB::table('source_volumes')->whereIn('source_id', $sources)->pluck('id')->all(), $folds['kept']);
        $copies = array_values(array_unique(array_merge(array_column($moves, 0), DB::table('manuscripts')->whereIn('catalog_volume_id', $volumes)->pluck('id')->all())));
        $chunked = fn ($table, $column, $ids, $columns) => collect(array_chunk($ids, 1000))->flatMap(fn ($c) => DB::table($table)->whereIn($column, $c)->get($columns))->all();
        $people = DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->pluck('cataloger_id')
            ->merge(DB::table('catalog_volume_part_cataloger')->whereIn('catalog_volume_part_id', DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $volumes)->select('id'))->pluck('cataloger_id'))
            ->merge(array_filter($this->catalogers(false, DB::table('source_volumes')->where('source_id', $sourceId)->pluck('volume_number')
                ->merge(array_keys($folds['volumes']))->merge(array_keys($folds['virtual'] ?? []))->all())))->unique()->values()->all();
        $backup = [
            'command' => $this->getName(),
            'marker' => $this->marker(),
            // the source the run makes, which its revert removes
            'made_source' => $making ? $this->data['source'] : null,
            'written_at' => now()->toIso8601String(),
            'source_ids' => $sources,
            'sources' => DB::table('sources')->whereIn('id', $sources)->get(),
            'catalog_volumes' => DB::table('catalog_volumes')->whereIn('id', $volumes)->get(),
            'source_volumes' => DB::table('source_volumes')->whereIn('id', $volumes)->get(),
            'catalog_volume_cataloger' => DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->get(),
            'part_ids' => DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $volumes)->pluck('id'),
            'manuscripts' => $chunked('manuscripts', 'id', $copies, ['id', 'catalog_volume_id', 'catalog_volume_part_id', 'cataloger_id']),
            'source_entries' => $chunked('source_entries', 'legacy_id', $copies, ['id', 'legacy_type', 'legacy_id', 'source_id', 'source_volume_id', 'volume_number', 'page_start']),
            'manuscript_catalog_references' => $chunked('manuscript_catalog_references', 'catalog_volume_id', $volumes, ['id', 'catalog_volume_id']),
            'catalogers' => DB::table('catalogers')->whereIn('id', $people)->get(['id', 'volumes_count', 'manuscripts_count', 'supervised_count']),
        ];
        $backup['source_entries'] = array_values(array_filter($backup['source_entries'], fn ($e) => $e->legacy_type === 'fankha_citation'));
        $backup['source_entries'] = array_map(fn ($e) => collect((array) $e)->except('legacy_type')->all(), $backup['source_entries']);

        $path = $this->option('backup') ?: storage_path('app/private/backups/catalogs-import-volume-parts-' . now()->format('Ymd_His') . '.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($backup, JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /** Puts back every row of a backup, removing the parts and catalogers the run made and restoring the sources it folded. */
    private function revert(string $file): int
    {
        $backup = is_file($file) ? json_decode(file_get_contents($file), true) : null;
        if (($backup['command'] ?? null) !== $this->getName()) {
            $this->error("{$file}: not a backup of {$this->getName()}.");

            return self::FAILURE;
        }
        $volumes = array_column($backup['source_volumes'], 'id');
        $this->info(sprintf('Restoring %d sources, %d volumes and %d copies from %s', count($backup['sources']), count($volumes), count($backup['manuscripts']), $file));

        DB::transaction(function () use ($backup, $volumes) {
            foreach (['sources', 'catalog_volumes', 'source_volumes'] as $table) {
                DB::table($table)->upsert($backup[$table], ['id']);
            }
            FixMisnamedIsfahanCataloguesCommand::restoreColumns('manuscripts', $backup['manuscripts']);
            FixMisnamedIsfahanCataloguesCommand::restoreColumns('source_entries', $backup['source_entries']);
            // a copy moved that had no entry was given one: it goes
            $had = array_column($backup['source_entries'], 'legacy_id');
            // (a backup written before step 13.166 has not the entries' copies)
            $made = $backup['source_entries'] && ! $had ? [] : array_diff(array_column($backup['manuscripts'], 'id'), $had);
            foreach (array_chunk($made, 1000) as $ids) {
                DB::table('source_entries')->where('legacy_type', 'fankha_citation')->whereIn('legacy_id', $ids)->delete();
            }
            FixMisnamedIsfahanCataloguesCommand::restoreColumns('manuscript_catalog_references', $backup['manuscript_catalog_references']);
            DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $volumes)->whereNotIn('id', $backup['part_ids'])->delete();
            DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->delete();
            DB::table('catalog_volume_cataloger')->insert($backup['catalog_volume_cataloger']);
            if ($made = DB::table('sources')->where('code', $backup['made_source'] ?? '')->first()) {
                $ids = DB::table('source_volumes')->where('source_id', $made->id)->pluck('id')->all();
                DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $ids)->delete();
                DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $ids)->delete();
                DB::table('source_volumes')->whereIn('id', $ids)->delete();
                DB::table('catalog_volumes')->whereIn('id', $ids)->delete();
                DB::table('sources')->where('id', $made->id)->delete();
            }
            $kept = array_column($backup['catalogers'], 'id');
            DB::table('catalogers')->where('metadata->made_by', $backup['marker'])->whereNotIn('id', $kept)->delete();
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
