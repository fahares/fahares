<?php

namespace App\Sources;

use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Support\BibliographyLinks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The entries a work or manuscript carries in its own columns (step 13.3): its Fankha entry (catalog_id,
 * volume_number, page_start, page_end, raw_text) and a work's bibliography. sources:backfill copies them all;
 * syncRecord() keeps one record's entries in step whenever those columns are saved, so every writer of the
 * record (a suggestion, its rollback, a merge, an import) leaves the two the same.
 */
class SourceEntrySync
{
    /** The columns of a record its entries follow; a manuscript's also give the library catalogue page Fankha cites. */
    public const RECORD_COLUMNS = ['catalog_id', 'volume_number', 'page_start', 'page_end', 'raw_text'];

    public const MANUSCRIPT_COLUMNS = ['metadata', 'catalog_volume_id'];

    /** Every column of source_entries but the keys, so the rows of one upsert have the same keys. */
    public static function row(array $values, $created = null, $updated = null): array
    {
        $now = now();

        return $values + [
            'source_id' => null, 'source_volume_id' => null, 'volume_number' => null, 'page_start' => null, 'page_end' => null,
            'entry_number' => null, 'shelfmark_page' => null, 'role' => SourceEntry::ROLE_ENTRY, 'is_primary' => false,
            'citation_text' => null, 'locations' => null, 'entry_text' => null, 'copy_text' => null,
            'text_volume_number' => null, 'text_page' => null, 'text_status' => SourceEntry::TEXT_NONE,
            'match_method' => null, 'confidence' => null, 'status' => SourceEntry::STATUS_UNVERIFIED,
            'reviewed_by' => null, 'reviewed_at' => null, 'notes' => null, 'sort_order' => 0, 'legacy_index' => 0,
            'created_at' => $created ?? $now, 'updated_at' => $updated ?? $created ?? $now,
        ];
    }

    /**
     * The Fankha entry of a record ($r: a model or a row of its table): its raw_text is the entry's text, a work's
     * the entry, a manuscript's its own line in the work's entry. The text stands where the record does.
     *
     * @param  array  $volumeIds  [source_id][volume_number] => source_volumes.id
     * @param  array  $codes  catalogs.id => code
     */
    public static function primaryValues(object $r, string $class, array $volumeIds, array $codes): array
    {
        $hasText = filled($r->raw_text);
        $volume = $r->volume_number !== null ? (int) $r->volume_number : null;
        $page = $r->page_start !== null ? (int) $r->page_start : null;
        $addenda = config('catalogs.addenda.' . ($codes[$r->catalog_id] ?? ''), []);

        return [
            'citable_type' => $class,
            'citable_id' => $r->id,
            'source_id' => $r->catalog_id,
            'source_volume_id' => $volumeIds[$r->catalog_id][$volume] ?? null,
            'volume_number' => $volume,
            'page_start' => $page,
            'page_end' => $r->page_end,
            'role' => self::inRanges($addenda, $volume, $page) ? SourceEntry::ROLE_ADDENDUM : SourceEntry::ROLE_ENTRY,
            'is_primary' => true,
            ($class === Manuscript::class ? 'copy_text' : 'entry_text') => $hasText ? $r->raw_text : null,
            'text_volume_number' => $hasText ? $volume : null,
            'text_page' => $hasText ? $page : null,
            'text_status' => $hasText ? SourceEntry::TEXT_AUTO : SourceEntry::TEXT_NONE,
            'origin' => SourceEntry::ORIGIN_FANKHA,
            // the entry is Fankha itself: nothing about it awaits review
            'status' => SourceEntry::STATUS_VERIFIED,
            'legacy_type' => $class === Manuscript::class ? 'manuscript' : 'work',
            'legacy_id' => $r->id,
        ];
    }

    /**
     * One entry per string of a work's bibliography, in order, with the places it cites of the sources the site
     * knows: the first is the entry's, all of them in locations when there are more.
     *
     * @param  array  $configSources  config key => sources.id
     * @return list<array>
     */
    public static function bibliographyValues(int $workId, array $strings, array $configSources, array $volumeIds): array
    {
        $rows = [];
        foreach (array_values($strings) as $i => $entry) {
            $places = array_map(fn ($c) => ['source_id' => $configSources[$c['source']] ?? null, 'volume' => $c['volume'], 'page' => $c['page']],
                BibliographyLinks::citations($entry));
            $first = $places[0] ?? null;
            $rows[] = [
                'citable_type' => Work::class,
                'citable_id' => $workId,
                'source_id' => $first['source_id'] ?? null,
                'source_volume_id' => $first ? $volumeIds[$first['source_id']][$first['volume']] ?? null : null,
                'volume_number' => $first['volume'] ?? null,
                'page_start' => $first['page'] ?? null,
                'role' => SourceEntry::ROLE_CITATION,
                'citation_text' => $entry,
                'locations' => count($places) > 1 ? json_encode($places) : null,
                'origin' => SourceEntry::ORIGIN_BIBLIOGRAPHY,
                // Fankha's own words: nothing about them awaits review
                'status' => SourceEntry::STATUS_VERIFIED,
                'sort_order' => $i,
                'legacy_type' => 'work_bibliography',
                'legacy_id' => $workId,
                'legacy_index' => $i,
            ];
        }

        return $rows;
    }

    /** The non-empty strings of a bibliography column (a JSON string or the cast array). */
    public static function bibliographyStrings($value): array
    {
        $list = is_string($value) ? json_decode($value, true) : $value;

        return array_values(array_filter((array) $list, fn ($e) => is_string($e) && trim($e) !== ''));
    }

    /** Brings one record's entries in line with its columns: its Fankha entry and, for a work, its bibliography. */
    public static function syncRecord(Model $record): void
    {
        $class = $record::class;
        // a record made without catalog_id has the column's default, which the model has not read back
        if (! array_key_exists('catalog_id', $record->getAttributes())) {
            $record->setRawAttributes(['catalog_id' => DB::table($record->getTable())->where('id', $record->id)->value('catalog_id')] + $record->getAttributes(), true);
        }
        if ($record->catalog_id) {
            self::ensureCatalogSource((int) $record->catalog_id);
            $volumeIds = self::volumeIds([(int) $record->catalog_id]);
            $codes = DB::table('catalogs')->where('id', $record->catalog_id)->pluck('code', 'id')->all();
            self::upsert([self::row(self::primaryValues($record, $class, $volumeIds, $codes), $record->created_at)]);
        } else {
            DB::table('source_entries')->where('legacy_type', $class === Manuscript::class ? 'manuscript' : 'work')->where('legacy_id', $record->id)->delete();
        }

        if ($record instanceof Work) {
            self::syncBibliography($record);
        }
        if ($record instanceof Manuscript) {
            // the row as stored: a model just made holds only the columns it was given
            self::syncCited([DB::table('manuscripts')->where('id', $record->id)->first(['id', 'metadata', 'raw_text', 'catalog_volume_id'])]);
        }
    }

    /**
     * The library catalogue page Fankha cites for a manuscript (backlog item 51): its citation as printed, «[ف: 2 - 224]»,
     * read into volume and page where it gives them, at its catalog_volume_id. Null when the copy has neither.
     *
     * @param  array  $volumeSources  source_volumes.id => sources.id
     */
    public static function citedValues(object $m, array $volumeSources): ?array
    {
        $raw = \App\Console\Commands\ImportFankhaCatalogReferencesCommand::rawCitation($m->metadata, $m->raw_text);
        if ($raw === null && ! $m->catalog_volume_id) {
            return null;
        }
        $parsed = $raw === null ? ['volume' => null, 'page' => null] : \App\Console\Commands\ImportFankhaCatalogReferencesCommand::parse($raw);
        $volume = $m->catalog_volume_id && isset($volumeSources[$m->catalog_volume_id]) ? (int) $m->catalog_volume_id : null;

        return [
            'citable_type' => Manuscript::class,
            'citable_id' => $m->id,
            'source_id' => $volume ? $volumeSources[$volume] : null,
            'source_volume_id' => $volume,
            'volume_number' => $parsed['volume'],
            'page_start' => $parsed['page'],
            'citation_text' => $raw,
            'origin' => SourceEntry::ORIGIN_FANKHA_CITATION,
            'legacy_type' => 'fankha_citation',
            'legacy_id' => $m->id,
        ];
    }

    /**
     * Writes the cited page of each manuscript (rows with id, metadata, raw_text, catalog_volume_id) and removes
     * it from a copy that no longer has one. Returns [written, removed].
     */
    public static function syncCited(iterable $manuscripts): array
    {
        $manuscripts = collect($manuscripts);
        $volumeIds = $manuscripts->pluck('catalog_volume_id')->filter()->unique()->values();
        $volumeSources = $volumeIds->isEmpty() ? [] : DB::table('source_volumes')->whereIn('id', $volumeIds)->pluck('source_id', 'id')->all();
        $rows = [];
        $none = [];
        foreach ($manuscripts as $m) {
            $values = self::citedValues($m, $volumeSources);
            $values ? $rows[] = self::row($values) : $none[] = $m->id;
        }
        $rows && self::upsert($rows);
        $removed = $none ? DB::table('source_entries')->where('legacy_type', 'fankha_citation')->whereIn('legacy_id', $none)->delete() : 0;
        // a copy on a volume with parts is of the one its library and page name (step 13.150)
        \App\Models\CatalogVolumePart::assign($manuscripts->pluck('id'));
        self::syncOtherCited($manuscripts);

        return [count($rows), $removed];
    }

    /**
     * The places a copy cites besides its catalogue entry's (step 14.42): one entry for each place of each source,
     * «[اهدائی رهبر: 3-673] و [ف: 21-1380]» two and «[فیلمها ف: 1 - 566 و 743]» two, under legacy_type
     * fankha_citation_more in their order. Each is at the volume the library's other copies citing that series and
     * volume are on (volumeOf); else it keeps its text without a volume.
     */
    public static function syncOtherCited(iterable $manuscripts): void
    {
        $others = [];
        $texts = [];
        foreach ($manuscripts as $m) {
            $others[$m->id] = \App\Support\CatalogCitations::others($m->metadata, $m->raw_text);
            $texts[$m->id] = (string) $m->raw_text;
        }
        if (! $others) {
            return;
        }
        $withPlaces = array_keys(array_filter($others));
        $libraries = $withPlaces ? DB::table('manuscripts')->whereIn('id', $withPlaces)->pluck('library_id', 'id')->all() : [];
        $volumes = self::seriesVolumes(array_values(array_unique(array_filter($libraries))));
        $volumeIds = [];
        foreach ($volumes as $keys) {
            foreach ($keys as $byVolume) {
                array_push($volumeIds, ...array_keys($byVolume));
            }
        }
        $volumeSources = $volumeIds ? DB::table('source_volumes')->whereIn('id', array_unique($volumeIds))->pluck('source_id', 'id')->all() : [];

        $rows = [];
        $elsewhere = null;
        foreach ($others as $id => $places) {
            foreach ($places as $i => $place) {
                $series = \App\Support\CatalogCitations::seriesKey($place['series']);
                $byKey = $volumes[$libraries[$id] ?? 0] ?? [];
                $key = $series . '|' . $place['volume'];
                // «اهدائی رهبر: 2 اخبار 2 - 492»: the part a volume in parts names, before its pages (step 14.105)
                $part = self::partOf($place['text']);
                $volume = $part !== null && isset($byKey[$key . '|' . $part])
                    ? self::volumeOf($byKey[$key . '|' . $part], $place['page'])
                    : self::volumeOf($byKey[$key] ?? [], $place['page']);
                // «اهدائی رهبر: 158-8», «ف: 2617-7»: page and volume written the other way round (step 14.88). Taken only
                // when the library has no such volume at all and the swapped page lies in the pages seen in that volume.
                if ($volume === null && $place['volume'] !== null && $place['page'] !== null && ! isset($byKey[$series . '|' . $place['volume']])) {
                    $swapped = $byKey[$series . '|' . $place['page']] ?? [];
                    $fits = array_filter($swapped, fn ($v) => $v[1] !== null && $place['volume'] >= $v[1] && $place['volume'] <= $v[2]);
                    if (count($fits) === 1) {
                        $volume = (int) array_key_first($fits);
                        $place = ['volume' => $place['page'], 'page' => $place['volume']] + $place;
                    }
                }
                // «[صد و شصت نسخه: - 11]» in a copy of Marashi once Ostadi's («تملک: رضا استادی»): a catalogue the copy's
                // own library does not cite, whose name the copies of one library alone cite, and that library's name
                // in the copy's own text (step 14.105)
                if ($volume === null && $place['series'] !== null && ! isset($byKey[$key])) {
                    $elsewhere ??= self::seriesElsewhere(array_merge(...array_values($others)));
                    [$library, $candidates] = $elsewhere[$key] ?? [null, []];
                    $volume = $library !== null && self::namesLibrary($texts[$id] ?? '', $library)
                        ? self::volumeOf($candidates, $place['page']) : null;
                    if ($volume !== null && ! isset($volumeSources[$volume])) {
                        $volumeSources[$volume] = DB::table('source_volumes')->where('id', $volume)->value('source_id');
                    }
                }
                $rows[] = self::row([
                    'citable_type' => Manuscript::class,
                    'citable_id' => $id,
                    'source_id' => $volume ? $volumeSources[$volume] ?? null : null,
                    'source_volume_id' => $volume && isset($volumeSources[$volume]) ? $volume : null,
                    'volume_number' => $place['volume'],
                    'page_start' => $place['page'],
                    'citation_text' => $place['text'],
                    'origin' => SourceEntry::ORIGIN_FANKHA_CITATION,
                    'sort_order' => $i + 1,
                    'legacy_type' => 'fankha_citation_more',
                    'legacy_id' => $id,
                    'legacy_index' => $i,
                ]);
            }
        }
        $rows && self::upsert($rows);
        foreach (array_chunk(array_keys($others), 1000, true) as $part) {
            DB::table('source_entries')->where('legacy_type', 'fankha_citation_more')
                ->where(function ($q) use ($part, $others) {
                    foreach ($part as $id) {
                        $q->orWhere(fn ($w) => $w->where('legacy_id', $id)->where('legacy_index', '>=', count($others[$id])));
                    }
                })->delete();
        }
    }

    /**
     * [library_id]['series|volume'] => the volumes the library's copies citing that series and volume are on, from
     * their catalogue entries: [source_volumes.id => [copies, first page, last page]], each holding at least one in
     * twenty of them (fewer are copies linked by mistake).
     *
     * @return array<int, array<string, array<int, array{0: int, 1: ?int, 2: ?int}>>>
     */
    public static function seriesVolumes(array $libraryIds): array
    {
        $seen = [];
        foreach (array_chunk($libraryIds, 50) as $part) {
            DB::table('source_entries as e')->join('manuscripts as m', 'm.id', '=', 'e.legacy_id')
                ->where('e.legacy_type', 'fankha_citation')->whereIn('m.library_id', $part)->whereNotNull('e.source_volume_id')
                ->select(['e.id', 'm.library_id', 'e.citation_text', 'e.source_volume_id'])
                ->chunkById(5000, function ($rows) use (&$seen) {
                    foreach ($rows as $r) {
                        $place = \App\Support\CatalogCitations::places((string) $r->citation_text)[0] ?? null;
                        if (! $place || $place['series'] === null) {
                            continue;
                        }
                        $key = \App\Support\CatalogCitations::seriesKey($place['series']) . '|' . $place['volume'];
                        $part = self::partOf($place['text']);
                        foreach ($part === null ? [$key] : [$key, $key . '|' . $part] as $k) {
                            $v = &$seen[$r->library_id][$k][$r->source_volume_id];
                            $v ??= [0, null, null];
                            $v[0]++;
                            if ($place['page'] !== null) {
                                $v[1] = $v[1] === null ? $place['page'] : min($v[1], $place['page']);
                                $v[2] = $v[2] === null ? $place['page'] : max($v[2], $place['page']);
                            }
                            unset($v);
                        }
                    }
                }, 'e.id', 'id');
        }
        foreach ($seen as $library => $keys) {
            foreach ($keys as $key => $byVolume) {
                $all = array_sum(array_column($byVolume, 0));
                $seen[$library][$key] = array_filter($byVolume, fn ($v) => $v[0] * 20 >= $all);
            }
        }

        return $seen;
    }

    /** The part of a volume in parts a citation names between its volume and page: «2 اخبار 2 - 492», «1علوم قرآن-15». */
    public static function partOf(string $text): ?int
    {
        return preg_match('/:\s*\d+\s*[^\d\s:\-–][^\d:\-–]*?\s*(\d+)\s*[\-–]\s*\d/u', $text, $m) ? (int) $m[1] : null;
    }

    /**
     * ['series|volume'] => [library_id, volumes as seriesVolumes() gives them], for the series of these places that
     * the catalogue entries of a single library's copies cite. A series name more than one library cites («ف»,
     * «ف مخ») is no one catalogue's.
     *
     * @param  list<array{text: string, series: ?string, volume: ?int, page: ?int}>  $places
     */
    private static function seriesElsewhere(array $places): array
    {
        $names = [];
        foreach ($places as $place) {
            if ($place['series'] !== null) {
                $names[\App\Support\CatalogCitations::seriesKey($place['series'])] = $place['series'];
            }
        }
        $found = [];
        foreach ($names as $series => $name) {
            if (mb_strlen($series) < 4) {
                continue;
            }
            $libraries = DB::table('source_entries as e')->join('manuscripts as m', 'm.id', '=', 'e.legacy_id')
                ->where('e.legacy_type', 'fankha_citation')->whereNotNull('e.source_volume_id')
                ->where('e.citation_text', 'like', '%' . $name . '%')->distinct()->pluck('m.library_id')->all();
            $byLibrary = [];
            foreach (self::seriesVolumes($libraries) as $library => $keys) {
                foreach ($keys as $key => $byVolume) {
                    if (str_starts_with($key, $series . '|')) {
                        $byLibrary[$library][$key] = $byVolume;
                    }
                }
            }
            if (count($byLibrary) === 1) {
                foreach (reset($byLibrary) as $key => $byVolume) {
                    $found[$key] = [array_key_first($byLibrary), $byVolume];
                }
            }
        }

        return $found;
    }

    /** Whether a copy's text names a library: «استادی» of «استادی، رضا» in «تملک: رضا استادی». */
    private static function namesLibrary(string $text, int $libraryId): bool
    {
        static $names = [];
        $name = $names[$libraryId] ??= (string) DB::table('libraries')->where('id', $libraryId)->value('name');
        $head = trim(preg_split('/[،,(]/u', $name)[0]);

        return mb_strlen($head) >= 3 && str_contains(\App\Console\Commands\LinkCatalogVolumesCommand::normalize($text),
            \App\Console\Commands\LinkCatalogVolumesCommand::normalize($head));
    }

    /**
     * The volume of a place among those seriesVolumes() gives for its series and volume: the only one, or of a
     * volume in parts («جلد سوم: فقه، بخش ۱» and «بخش ۲») the one whose pages hold the page.
     */
    public static function volumeOf(array $candidates, ?int $page): ?int
    {
        if (count($candidates) === 1) {
            return (int) array_key_first($candidates);
        }
        if ($page === null) {
            return null;
        }
        $holding = array_keys(array_filter($candidates, fn ($v) => $v[1] !== null && $page >= $v[1] && $page <= $v[2]));

        return count($holding) === 1 ? (int) $holding[0] : null;
    }

    public static function syncBibliography(Work $work): void
    {
        $strings = self::bibliographyStrings($work->getAttributes()['bibliography'] ?? null);
        $configSources = self::configSources();
        $rows = array_map(fn ($v) => self::row($v, $work->created_at),
            self::bibliographyValues($work->id, $strings, $configSources, self::volumeIds(array_values(array_filter($configSources)))));
        self::upsert($rows);
        // strings past the new end are gone
        DB::table('source_entries')->where('legacy_type', 'work_bibliography')->where('legacy_id', $work->id)
            ->where('legacy_index', '>=', count($strings))->delete();
    }

    /**
     * Brings the entries of records written with DB::table in line (a command's bulk update skips the save hook),
     * a thousand records at a time.
     *
     * @param  class-string<Work|Manuscript>  $class
     */
    public static function syncIds(string $class, iterable $ids): void
    {
        foreach (collect($ids)->unique()->chunk(1000) as $chunk) {
            $class::whereIn('id', $chunk->all())->get()->each(fn ($record) => self::syncRecord($record));
        }
    }

    /** Inserts or updates rows by the record they come from (legacy_type, legacy_id, legacy_index). */
    public static function upsert(array $rows): void
    {
        $keys = ['legacy_type', 'legacy_id', 'legacy_index'];
        // a thousand works give several thousand bibliography rows, past the 65,535 placeholders of one statement
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('source_entries')->upsert($chunk, $keys, array_diff(array_keys($chunk[0]), [...$keys, 'created_at']));
        }
    }

    /**
     * The source of a catalog, made from its row when the registry does not have it yet (sources.id = catalogs.id),
     * with a volume for each of its volumes; Fankha's page images are the viewer's default folder.
     */
    public static function ensureCatalogSource(int $catalogId): ?int
    {
        if (DB::table('sources')->where('id', $catalogId)->exists()) {
            return $catalogId;
        }
        $c = DB::table('catalogs')->find($catalogId);
        if (! $c) {
            return null;
        }
        DB::table('sources')->insert(['id' => $c->id] + self::catalogSourceValues($c) + ['created_at' => now(), 'updated_at' => now()]);
        self::ensureVolumes($c->id, range(1, max(1, (int) $c->volumes_count)), $c->code === 'fankha' ? 'fankha' : null);

        return $catalogId;
    }

    public static function catalogSourceValues(object $c): array
    {
        return [
            'code' => $c->code,
            'kind' => Source::KIND_UNION_CATALOGUE,
            'rank' => Source::KIND_RANKS[Source::KIND_UNION_CATALOGUE],
            'title' => $c->name,
            'short_name' => $c->short_name,
            'compiler' => $c->compiler,
            'publisher' => $c->publisher,
            'volumes_count' => $c->volumes_count,
            'description' => $c->description,
            'citation_format' => $c->citation_format,
        ];
    }

    /** Makes the volumes a source lacks; returns how many were made. */
    public static function ensureVolumes(int $sourceId, array $numbers, ?string $facsimile, ?callable $extra = null, bool $dry = false): int
    {
        $have = DB::table('source_volumes')->where('source_id', $sourceId)->pluck('volume_number')->map(fn ($n) => (int) $n)->all();
        $rows = [];
        foreach (array_diff($numbers, $have) as $n) {
            $rows[] = ['source_id' => $sourceId, 'volume_number' => $n, 'facsimile_code' => $facsimile, 'created_at' => now(), 'updated_at' => now()]
                + ($extra ? $extra($n) : []);
        }
        $dry || ! $rows || DB::table('source_volumes')->insert($rows);

        return count($rows);
    }

    /** [source_id][volume_number] => the source's first volume of that number. */
    public static function volumeIds(?array $sourceIds = null): array
    {
        $map = [];
        DB::table('source_volumes')->when($sourceIds !== null, fn ($q) => $q->whereIn('source_id', $sourceIds))
            ->orderBy('id')->get(['id', 'source_id', 'volume_number'])
            ->each(function ($v) use (&$map) {
                $map[$v->source_id][$v->volume_number] ??= $v->id;
            });

        return $map;
    }

    /** config('bibliography.sources') key => sources.id, for the reference works the registry has. */
    public static function configSources(): array
    {
        return DB::table('sources')->whereIn('code', array_keys(CitationForms::sources()))->pluck('id', 'code')->all();
    }

    /** One source per library and title, and one per later printing (the Masjid-e A'zam catalogue of 1388). */
    public static function groupKey(object $volume): string
    {
        if ($volume->edition_of_id) {
            return 'edition|' . ($volume->facsimile_code ?: $volume->library_id . '|' . self::stem($volume->title));
        }

        return 'catalogue|' . ($volume->library_id ?? '') . '|' . self::stem($volume->title);
    }

    public static function groupCode(string $key, object $first): string
    {
        return $first->edition_of_id && $first->facsimile_code ? $first->facsimile_code : 'catalogue-' . substr(sha1($key), 0, 12);
    }

    /** A volume's title without its volume part: «فهرست … آستان قدس رضوی - جلد دوم: کتب خطی» → «فهرست … آستان قدس رضوی». */
    public static function stem(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title));
        $at = mb_strrpos($title, ' - ');

        return $at === false ? $title : trim(mb_substr($title, 0, $at));
    }

    /** The columns a catalog_volumes row gives its source volume (same id). */
    public static function volumeValues(object $v): array
    {
        return [
            'volume_number' => $v->volume_number,
            'title' => $v->title,
            'edition_label' => $v->edition_label,
            'facsimile_code' => $v->facsimile_code,
            'cover_image_path' => $v->cover_image_path,
            'publisher' => $v->publisher,
            'publication_year' => $v->publication_year,
            'pages_count' => $v->pages_count,
            'page_first' => $v->page_first,
            'page_last' => $v->page_last,
            'manuscripts_range' => $v->manuscripts_range,
            'citation_pattern' => $v->citation_pattern,
            'mtif_entry_id' => $v->mtif_entry_id,
            'source_url' => $v->source_url,
            'metadata' => is_array($v->metadata) ? json_encode($v->metadata, JSON_UNESCAPED_UNICODE) : $v->metadata,
        ];
    }

    /**
     * Brings one catalog_volumes row into source_volumes under its id (step 13.4): a volume already there keeps its
     * source, so a regrouping made in the registry stays; a new one joins the source of its library and title, or of
     * its printing, made when there is none.
     */
    public static function syncVolume(\App\Models\CatalogVolume $volume): void
    {
        // the row as stored: a model just made holds only the columns it was given
        $row = DB::table('catalog_volumes')->find($volume->id);
        $values = self::volumeValues($row) + ['updated_at' => now()];
        if (DB::table('source_volumes')->where('id', $volume->id)->exists()) {
            DB::table('source_volumes')->where('id', $volume->id)->update($values);
        } else {
            $key = self::groupKey($row);
            $sourceId = DB::table('sources')->where('code', self::groupCode($key, $row))->value('id')
                ?? DB::table('sources')->insertGetId([
                    'code' => self::groupCode($key, $row),
                    'kind' => Source::KIND_LIBRARY_CATALOGUE,
                    'rank' => Source::KIND_RANKS[Source::KIND_LIBRARY_CATALOGUE],
                    'title' => self::stem($row->title),
                    'library_id' => $row->library_id,
                    'edition_label' => $row->edition_label,
                    'publisher' => $row->publisher,
                    'volumes_count' => 1,
                    'metadata' => json_encode(['group' => $key], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            DB::table('source_volumes')->insert(['id' => $volume->id, 'source_id' => $sourceId, 'created_at' => now()] + $values);
        }
        if ($volume->edition_of_id && ($of = \App\Models\CatalogVolume::find($volume->edition_of_id))) {
            DB::table('source_volumes')->where('id', $of->id)->exists() || self::syncVolume($of);
            DB::table('source_volumes')->where('id', $volume->id)->update(['edition_of_id' => $of->id]);
            $source = DB::table('source_volumes')->where('id', $volume->id)->value('source_id');
            DB::table('sources')->where('id', $source)->whereNull('edition_of_id')
                ->update(['edition_of_id' => DB::table('source_volumes')->where('id', $of->id)->value('source_id')]);
        }
    }

    private static function inRanges(array $ranges, ?int $volume, ?int $page): bool
    {
        foreach ($ranges as $range) {
            if ($volume === $range['volume'] && $page >= $range['from'] && $page <= $range['to']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Copies without a catalogue volume: one whose citation changed in fahares:rebuild-copies, or one
     * fahares:add-corpus-works made (step 14.110). catalogs:link-volumes relinks only the libraries of its links file,
     * not the large ones (Marashi, Tehran University). So each goes to the volume the library's other copies citing
     * that series and volume are on, and the cataloger most of that volume's copies have (volumeOf, step 14.51). A copy
     * whose volume no other copy shows stays without one.
     */
    public static function relinkVolumes(array $ids): int
    {
        $rows = DB::table('manuscripts')->whereIn('id', $ids)->whereNull('catalog_volume_id')
            ->get(['id', 'library_id', 'metadata']);
        $volumes = self::seriesVolumes($rows->pluck('library_id')->filter()->unique()->values()->all());
        $done = 0;
        foreach ($rows as $row) {
            $citation = json_decode($row->metadata ?? 'null', true)['catalog_citation'] ?? null;
            $place = $citation ? (\App\Support\CatalogCitations::places($citation)[0] ?? null) : null;
            if (! $place || $place['series'] === null) {
                continue;
            }
            $key = \App\Support\CatalogCitations::seriesKey($place['series']) . '|' . $place['volume'];
            $volume = self::volumeOf($volumes[$row->library_id][$key] ?? [], $place['page']);
            if (! $volume) {
                continue;
            }
            $cataloger = DB::table('manuscripts')->where('catalog_volume_id', $volume)->whereNotNull('cataloger_id')
                ->groupBy('cataloger_id')->orderByRaw('count(*) desc')->value('cataloger_id');
            DB::table('manuscripts')->where('id', $row->id)->update(['catalog_volume_id' => $volume, 'cataloger_id' => $cataloger]);
            $done++;
        }

        return $done;
    }
}
