<?php

namespace App\Sources;

use App\Models\Cataloger;
use App\Models\CatalogVolumePart;
use App\Models\Source;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Folds sources that are one printed catalogue into one (step 13.110, sources:merge-split; the admin panel's merge
 * of two sources, step 14.50). plan() pairs the volumes: each takes the number its title gives («جلد هشتم» → 8) and
 * moves to the kept source with its id, which is its catalog volume's too, so its catalogers and parts stay; where
 * two volumes have one number, the kept source's (else the one with most entries) stays and the other's entries,
 * printed entries, pages, copies, catalogers and parts move to it before it is deleted with its catalog volume.
 * apply() does it; the kept source takes the titles' common form (or the one given) and the folded sources' codes
 * (metadata.merged_codes, which /sources/{code} redirects and sources:backfill keeps folded).
 *
 * snapshot() keeps, before apply(), every row apply() may change or delete, and undo() puts them back: the command
 * has no backup of its own, the panel's merge keeps the snapshot in its audit log.
 */
class SourceMerger
{
    private const UNITS = ['اول' => 1, 'یکم' => 1, 'دوم' => 2, 'سوم' => 3, 'چهارم' => 4, 'پنجم' => 5, 'ششم' => 6, 'هفتم' => 7,
        'هشتم' => 8, 'نهم' => 9, 'دهم' => 10, 'یازدهم' => 11, 'دوازدهم' => 12, 'سیزدهم' => 13, 'چهاردهم' => 14, 'پانزدهم' => 15,
        'شانزدهم' => 16, 'هفدهم' => 17, 'هجدهم' => 18, 'هیجدهم' => 18, 'نوزدهم' => 19, 'بیستم' => 20, 'سی ام' => 30, 'سیم' => 30,
        'چهلم' => 40, 'پنجاهم' => 50];

    private const TENS = ['بیست' => 20, 'سی' => 30, 'چهل' => 40, 'پنجاه' => 50];

    /** What the panel folds at most: a larger merge is the command's, its snapshot too large for an audit log. */
    public const PANEL_LIMIT = 50000;

    /**
     * How the group folds: the kept source (the group's first), the folded ones, the common title, and the volumes
     * that stay (each with its new number) and those that fold into another.
     *
     * @param  Collection<int, Source>  $group  kept source first, each with its volumes loaded
     * @return array{kept: Source, folded: Collection, title: string, volumes: Collection, stays: array, drops: array}
     */
    public function plan(Collection $group): array
    {
        $group = $group->values();
        if ($group->count() < 2) {
            throw new InvalidArgumentException('برای ادغام دست‌کم دو منبع لازم است.');
        }
        $kept = $group->first();
        $volumes = $group->flatMap(fn (Source $s) => $s->volumes->map(fn ($v) => (object) [
            'volume' => $v, 'source' => $s, 'old' => $v->volume_number,
            // a part is numbered as its source cites it («جلد سوم - بخش دوم (مجلد چهارم)» is 4)
            'new' => self::isPart($v->title) ? $v->volume_number : self::volumeNumber($v->title) ?? self::volumeNumber($s->title) ?? $v->volume_number,
            'entries' => DB::table('source_entries')->where('source_volume_id', $v->id)->count(),
        ]));
        $stays = [];
        $drops = [];
        foreach ($volumes->sortBy([fn ($a, $b) => (int) ($b->source->id === $kept->id) <=> (int) ($a->source->id === $kept->id),
            fn ($a, $b) => $b->entries <=> $a->entries, fn ($a, $b) => $a->volume->id <=> $b->volume->id]) as $v) {
            // a part shares its volume's number with the other parts («جلد ۳/۲۴», «جلد نهم - بخش دوم») and is never one of them
            $key = $v->new === null || self::isPart($v->volume->title) ? 'v' . $v->volume->id : $v->new;
            $same = $stays[$key] ?? null;
            if ($same && $same->source->id !== $v->source->id
                && in_array(self::subtitle($v->volume->title), ['', self::subtitle($same->volume->title)], true)) {
                $drops[] = [$v, $same];
            } else {
                $stays[$same ? 'v' . $v->volume->id : $key] = $v;
            }
        }
        uasort($stays, fn ($a, $b) => [$a->new ?? PHP_INT_MAX, $a->volume->id] <=> [$b->new ?? PHP_INT_MAX, $b->volume->id]);

        return ['kept' => $kept, 'folded' => $group->slice(1)->values(), 'title' => self::baseTitle($kept->title),
            'volumes' => $volumes, 'stays' => $stays, 'drops' => $drops];
    }

    /**
     * What the merge moves, for its preview: entries, copies, parts, catalogers, printed entries and page texts of
     * the folded sources, and of the duplicate volumes the ones that fold into another.
     *
     * @return array<string, int>
     */
    public function counts(array $plan): array
    {
        $folded = $plan['folded']->pluck('id')->all();
        $volumes = $plan['folded']->flatMap(fn ($s) => $s->volumes->pluck('id'))->all() ?: [0];
        $drops = array_map(fn ($d) => $d[0]->volume->id, $plan['drops']) ?: [0];

        return [
            'volumes' => count($volumes) - ($volumes === [0] ? 1 : 0),
            'drops' => count($plan['drops']),
            'entries' => DB::table('source_entries')->where(fn ($q) => $q->whereIn('source_id', $folded)->orWhereIn('source_volume_id', $volumes))->count(),
            'copies' => DB::table('manuscripts')->whereIn('catalog_volume_id', $volumes)->count(),
            'parts' => DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $volumes)->count(),
            'catalogers' => DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $volumes)->distinct()->count('cataloger_id'),
            'items' => DB::table('source_items')->whereIn('source_volume_id', $volumes)->count(),
            'pages' => DB::table('source_pages')->whereIn('source_volume_id', $volumes)->count(),
            'evidence' => DB::table('field_evidence')->whereIn('source_id', $folded)->count(),
            'drop_entries' => DB::table('source_entries')->whereIn('source_volume_id', $drops)->count(),
            'editions' => DB::table('sources')->whereIn('edition_of_id', $folded)->count(),
        ];
    }

    /** Folds the group as planned; the caller runs it in a transaction. */
    public function apply(array $plan, ?string $title = null): void
    {
        $kept = $plan['kept'];
        $folded = $plan['folded'];
        $group = collect([$kept])->concat($folded);

        foreach ($plan['drops'] as [$d, $k]) {
            self::foldVolume($d->volume->id, $k->volume->id);
        }
        $this->renumber($kept, $group, $plan['volumes'], $plan['drops'], $plan['stays']);

        $metadata = $kept->metadata ?? [];
        $metadata['merged_codes'] = array_values(array_unique(array_merge($metadata['merged_codes'] ?? [],
            ...$folded->map(fn ($s) => [$s->code, ...($s->metadata['merged_codes'] ?? [])])->all())));
        $fill = [];
        foreach (['short_name', 'compiler', 'publisher', 'publication_year', 'description', 'citation_format', 'extractor'] as $column) {
            if (blank($kept->{$column}) && filled($value = $folded->pluck($column)->filter()->first())) {
                $fill[$column] = $value;
            }
        }
        DB::table('sources')->whereIn('edition_of_id', $folded->pluck('id'))->update(['edition_of_id' => $kept->id]);
        // how the folded sources are cited, and how they cite, is the kept source's (step 14.57)
        if (CitationForms::ready()) {
            foreach (['cited_source_id', 'citing_source_id'] as $column) {
                DB::table('source_citation_forms')->whereIn($column, $folded->pluck('id'))->update([$column => $kept->id]);
            }
            DB::table('source_citation_forms')->where('cited_source_id', $kept->id)->where('citing_source_id', $kept->id)->delete();
        }
        DB::table('sources')->whereIn('id', $folded->pluck('id'))->delete();
        DB::table('sources')->where('id', $kept->id)->update($fill + [
            'title' => filled($title) ? trim($title) : $plan['title'],
            'volumes_count' => DB::table('source_volumes')->where('source_id', $kept->id)->count(),
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        // the copies of a duplicate volume take a part of the volume kept, and the counts follow
        $keptVolumes = array_unique(array_map(fn ($d) => $d[1]->volume->id, $plan['drops']));
        if ($keptVolumes) {
            CatalogVolumePart::assign(DB::table('manuscripts')->whereIn('catalog_volume_id', $keptVolumes)->pluck('id'));
            foreach ($keptVolumes as $id) {
                DB::table('catalog_volumes')->where('id', $id)->update(['manuscripts_count' => DB::table('manuscripts')->where('catalog_volume_id', $id)->count()]);
            }
            CatalogVolumePart::recount(DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $keptVolumes)->pluck('id')->all());
            Cataloger::recount(DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $keptVolumes)->pluck('cataloger_id')
                ->merge(DB::table('catalog_volume_part_cataloger')->whereIn('catalog_volume_part_id',
                    DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $keptVolumes)->select('id'))->pluck('cataloger_id')));
        }
        self::forget($group->pluck('id')->all());
    }

    /** Moves everything that hangs on a duplicate volume to the one kept, then deletes it with its catalog volume. */
    public static function foldVolume(int $drop, int $keep): void
    {
        DB::table('source_entries')->where('source_volume_id', $drop)->update(['source_volume_id' => $keep]);
        // a printed entry or a page the kept volume has too is the same one
        foreach (DB::table('source_items')->where('source_volume_id', $drop)->get(['id', 'item_key']) as $item) {
            $same = DB::table('source_items')->where('source_volume_id', $keep)->where('item_key', $item->item_key)->value('id');
            if ($same) {
                DB::table('source_entries')->where('source_item_id', $item->id)->update(['source_item_id' => $same]);
                DB::table('source_item_refs')->where('target_item_id', $item->id)->update(['target_item_id' => $same]);
                DB::table('source_items')->where('parent_id', $item->id)->update(['parent_id' => $same]);
                DB::table('source_items')->where('id', $item->id)->delete();
            } else {
                DB::table('source_items')->where('id', $item->id)->update(['source_volume_id' => $keep]);
            }
        }
        DB::table('source_pages')->where('source_volume_id', $drop)
            ->whereNotIn('page', DB::table('source_pages')->where('source_volume_id', $keep)->select('page'))
            ->update(['source_volume_id' => $keep]);
        foreach (['source_volumes', 'catalog_volumes'] as $table) {
            DB::table($table)->where('edition_of_id', $drop)->update(['edition_of_id' => $keep]);
        }
        DB::table('manuscripts')->where('catalog_volume_id', $drop)->update(['catalog_volume_id' => $keep]);
        DB::table('manuscript_catalog_references')->where('catalog_volume_id', $drop)->update(['catalog_volume_id' => $keep]);
        $has = DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $keep)->pluck('cataloger_id')->all();
        DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $drop)->whereNotIn('cataloger_id', $has)
            ->update(['catalog_volume_id' => $keep]);
        DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $drop)->delete();
        // the duplicate's parts go with it, after the kept volume's own (they were deleted with it before step 14.50)
        $sort = (int) DB::table('catalog_volume_parts')->where('catalog_volume_id', $keep)->max('sort');
        foreach (DB::table('catalog_volume_parts')->where('catalog_volume_id', $drop)->orderBy('sort')->orderBy('id')->pluck('id') as $part) {
            DB::table('catalog_volume_parts')->where('id', $part)->update(['catalog_volume_id' => $keep, 'sort' => ++$sort]);
        }

        // what the kept volume lacks, the duplicate may have
        foreach (['source_volumes' => ['publisher', 'publication_year', 'pages_count', 'page_first', 'page_last', 'manuscripts_range',
            'mtif_entry_id', 'facsimile_code', 'cover_image_path', 'source_url'], 'catalog_volumes' => ['publisher', 'publication_year',
            'pages_count', 'manuscripts_range', 'mtif_entry_id', 'cover_image_path']] as $table => $columns) {
            $from = DB::table($table)->where('id', $drop)->first();
            $to = DB::table($table)->where('id', $keep)->first();
            if ($from && $to && ($fill = collect($columns)->filter(fn ($c) => blank($to->{$c}) && filled($from->{$c}))
                ->mapWithKeys(fn ($c) => [$c => $from->{$c}])->all())) {
                DB::table($table)->where('id', $keep)->update($fill);
            }
        }
        DB::table('source_volumes')->where('id', $drop)->delete();
        DB::table('catalog_volumes')->where('id', $drop)->delete();
    }

    /**
     * Moves the volumes and everything that cites the folded sources to the kept one, each place with its volume's new
     * number.
     */
    private function renumber(Source $kept, Collection $group, Collection $volumes, array $drops, array $stays): void
    {
        // [source id][old number] => new number, for the places that name a volume by its number only
        $numbers = [];
        foreach ($volumes as $v) {
            if ($v->old !== null && $v->new !== null) {
                $numbers[$v->source->id][$v->old] = $v->new;
            }
        }
        foreach ($stays as $v) {
            $id = $v->volume->id;
            if ($v->old !== $v->new && $v->old !== null) {
                foreach (['volume_number', 'text_volume_number'] as $column) {
                    DB::table('source_entries')->where('source_volume_id', $id)->where('source_id', $v->source->id)
                        ->where($column, $v->old)->update([$column => $v->new]);
                }
            }
            DB::table('source_volumes')->where('id', $id)->update(['source_id' => $kept->id, 'volume_number' => $v->new]);
            DB::table('catalog_volumes')->where('id', $id)->update(['volume_number' => $v->new]);
            DB::table('source_items')->where('source_volume_id', $id)->update(['source_id' => $kept->id, 'volume_number' => $v->new]);
        }
        // a duplicate volume's entries now hang on the kept volume, under its number
        foreach ($drops as [$d, $k]) {
            if ($d->old !== null && $d->old !== $k->new) {
                foreach (['volume_number', 'text_volume_number'] as $column) {
                    DB::table('source_entries')->where('source_volume_id', $k->volume->id)->where('source_id', $d->source->id)
                        ->where($column, $d->old)->update([$column => $k->new]);
                }
            }
        }
        foreach ($group as $source) {
            $map = array_filter($numbers[$source->id] ?? [], fn ($new, $old) => $new !== $old, ARRAY_FILTER_USE_BOTH);
            foreach (['source_entries' => ['volume_number', 'text_volume_number'], 'field_evidence' => ['volume_number']] as $table => $columns) {
                foreach ($columns as $column) {
                    if ($map) {
                        // in one statement, so that 1 → 2 and 2 → 3 do not run into each other
                        $case = 'case ' . $column . collect($map)->map(fn ($new, $old) => " when {$old} then {$new}")->implode('') . ' end';
                        DB::table($table)->where('source_id', $source->id)->whereIn($column, array_keys($map))
                            ->when($table === 'source_entries', fn ($q) => $q->whereNull('source_volume_id'))
                            ->update([$column => DB::raw($case)]);
                    }
                }
                if ($source->id !== $kept->id) {
                    DB::table($table)->where('source_id', $source->id)->update(['source_id' => $kept->id]);
                }
            }
            if ($source->id === $kept->id) {
                continue;
            }
            DB::table('source_entries')->where('locations', 'like', '%"source_id":' . $source->id . ',%')->orderBy('id')
                ->each(function ($row) use ($source, $kept, $map) {
                    $locations = array_map(fn ($l) => ($l['source_id'] ?? null) === $source->id
                        ? ['source_id' => $kept->id, 'volume' => $map[$l['volume'] ?? null] ?? $l['volume'] ?? null] + $l : $l,
                        json_decode($row->locations, true) ?: []);
                    DB::table('source_entries')->where('id', $row->id)->update(['locations' => json_encode($locations, JSON_UNESCAPED_UNICODE)]);
                });
        }
    }

    /**
     * Every row apply() may change or delete, as it is now: whole rows of what it deletes or renumbers, and of the
     * many rows only the columns it writes.
     */
    public function snapshot(array $plan): array
    {
        $ids = collect([$plan['kept']])->concat($plan['folded'])->pluck('id')->all();
        $folded = $plan['folded']->pluck('id')->all();
        $volumes = DB::table('source_volumes')->whereIn('source_id', $ids)->pluck('id')->all() ?: [0];
        $drops = array_map(fn ($d) => $d[0]->volume->id, $plan['drops']) ?: [0];
        $dropItems = DB::table('source_items')->whereIn('source_volume_id', $drops)->pluck('id')->all() ?: [0];
        $parts = DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $volumes)->pluck('id')->all() ?: [0];
        $all = fn ($table, $column, $values) => DB::table($table)->whereIn($column, $values)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $cols = fn ($query, $columns) => $query->orderBy('id')->get($columns)->map(fn ($r) => (array) $r)->all();
        $entries = DB::table('source_entries')->where(fn ($q) => $q->whereIn('source_id', $ids)->orWhereIn('source_volume_id', $volumes)
            ->orWhere(function ($q) use ($folded) {
                foreach ($folded as $id) {
                    $q->orWhere('locations', 'like', '%"source_id":' . $id . ',%');
                }
            }));

        return [
            'source_ids' => $ids,
            'volume_ids' => array_values(array_diff($volumes, [0])),
            'sources' => $all('sources', 'id', $ids),
            'editions' => $cols(DB::table('sources')->whereIn('edition_of_id', $folded ?: [0]), ['id', 'edition_of_id']),
            'source_volumes' => $all('source_volumes', 'id', $volumes),
            'catalog_volumes' => $all('catalog_volumes', 'id', $volumes),
            'volume_editions' => [
                'source_volumes' => $cols(DB::table('source_volumes')->whereIn('edition_of_id', $drops), ['id', 'edition_of_id']),
                'catalog_volumes' => $cols(DB::table('catalog_volumes')->whereIn('edition_of_id', $drops), ['id', 'edition_of_id']),
            ],
            'catalog_volume_cataloger' => $all('catalog_volume_cataloger', 'catalog_volume_id', $volumes),
            'catalog_volume_parts' => $all('catalog_volume_parts', 'id', $parts),
            'catalog_volume_part_cataloger' => $all('catalog_volume_part_cataloger', 'catalog_volume_part_id', $parts),
            'source_entries' => $cols($entries, ['id', 'source_id', 'source_volume_id', 'source_item_id', 'volume_number', 'text_volume_number', 'locations']),
            'source_items' => $cols(DB::table('source_items')->whereIn('source_volume_id', $volumes)->whereNotIn('id', $dropItems), ['id', 'source_id', 'source_volume_id', 'volume_number', 'parent_id']),
            'source_items_dropped' => $all('source_items', 'id', $dropItems),
            'source_item_refs' => $all('source_item_refs', 'source_item_id', $dropItems),
            'source_item_targets' => $cols(DB::table('source_item_refs')->whereIn('target_item_id', $dropItems), ['id', 'target_item_id']),
            'source_pages' => $all('source_pages', 'source_volume_id', $drops),
            'field_evidence' => $cols(DB::table('field_evidence')->whereIn('source_id', $ids), ['id', 'source_id', 'volume_number']),
            'manuscripts' => $cols(DB::table('manuscripts')->whereIn('catalog_volume_id', $volumes), ['id', 'catalog_volume_id', 'catalog_volume_part_id', 'cataloger_id']),
            'manuscript_catalog_references' => $cols(DB::table('manuscript_catalog_references')->whereIn('catalog_volume_id', $drops), ['id', 'catalog_volume_id']),
            'source_citation_forms' => CitationForms::ready() ? DB::table('source_citation_forms')->where(fn ($q) => $q->whereIn('cited_source_id', $ids)
                ->orWhereIn('citing_source_id', $ids))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all() : [],
        ];
    }

    /** Puts back what a snapshot holds, the folded sources and volumes with their ids; the caller runs it in a transaction. */
    public function undo(array $snapshot): void
    {
        $insert = function (string $table, array $rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($table)->upsert($chunk, ['id']);
            }
        };
        $insert('sources', $snapshot['sources']);
        $insert('source_volumes', $snapshot['source_volumes']);
        $insert('catalog_volumes', $snapshot['catalog_volumes']);
        foreach ($snapshot['volume_editions'] as $table => $rows) {
            self::restoreColumns($table, $rows);
        }
        self::restoreColumns('sources', $snapshot['editions']);

        DB::table('catalog_volume_cataloger')->whereIn('catalog_volume_id', $snapshot['volume_ids'] ?: [0])->delete();
        $insert('catalog_volume_cataloger', $snapshot['catalog_volume_cataloger']);
        $insert('catalog_volume_parts', $snapshot['catalog_volume_parts']);
        $parts = array_column($snapshot['catalog_volume_parts'], 'id');
        DB::table('catalog_volume_part_cataloger')->whereIn('catalog_volume_part_id', $parts ?: [0])->delete();
        $insert('catalog_volume_part_cataloger', $snapshot['catalog_volume_part_cataloger']);

        $insert('source_items', $snapshot['source_items_dropped']);
        self::restoreColumns('source_items', $snapshot['source_items']);
        $insert('source_item_refs', $snapshot['source_item_refs']);
        self::restoreColumns('source_item_refs', $snapshot['source_item_targets']);
        // a page text the kept volume had too was deleted with the duplicate; the others moved
        $insert('source_pages', $snapshot['source_pages']);
        self::restoreColumns('source_entries', $snapshot['source_entries']);
        self::restoreColumns('field_evidence', $snapshot['field_evidence']);
        self::restoreColumns('manuscripts', $snapshot['manuscripts']);
        self::restoreColumns('manuscript_catalog_references', $snapshot['manuscript_catalog_references']);
        $insert('source_citation_forms', $snapshot['source_citation_forms'] ?? []);

        foreach ($snapshot['volume_ids'] as $id) {
            DB::table('catalog_volumes')->where('id', $id)->update(['manuscripts_count' => DB::table('manuscripts')->where('catalog_volume_id', $id)->count()]);
        }
        CatalogVolumePart::recount($parts);
        Cataloger::recount(collect($snapshot['catalog_volume_cataloger'])->pluck('cataloger_id')
            ->merge(collect($snapshot['catalog_volume_part_cataloger'])->pluck('cataloger_id')));
        self::forget($snapshot['source_ids']);
    }

    /** A snapshot as it is kept in an audit log, and back. */
    public static function pack(array $snapshot): string
    {
        return base64_encode(gzcompress(json_encode($snapshot, JSON_UNESCAPED_UNICODE), 9));
    }

    public static function unpack(string $packed): array
    {
        $json = @gzuncompress((string) base64_decode($packed, true));
        if ($json === false || ! is_array($snapshot = json_decode($json, true))) {
            throw new InvalidArgumentException('نسخه‌ی پشتیبان این ادغام خوانا نیست.');
        }

        return $snapshot;
    }

    /** Writes back the columns of each row, grouping the rows that take the same values. */
    public static function restoreColumns(string $table, array $rows): void
    {
        foreach (collect($rows)->groupBy(fn ($r) => json_encode(collect($r)->except('id')->all())) as $group) {
            $values = collect($group->first())->except('id')->all();
            foreach ($group->pluck('id')->chunk(1000) as $ids) {
                DB::table($table)->whereIn('id', $ids->all())->update($values);
            }
        }
    }

    public static function forget(array $sourceIds): void
    {
        // a source's title, codes and volumes are what its citations are read with
        CitationForms::forget();
        Cache::forget('sources:shelf');
        foreach (array_filter($sourceIds) as $id) {
            Cache::forget("sources:copies:{$id}");
            foreach (['works', 'manuscripts'] as $type) {
                Cache::forget('sources:linked:' . md5(implode('|', [$id, $type, null, ''])));
            }
        }
    }

    /** One key for the sources that are one catalogue: kind, library, printing and the title without its volume. */
    public static function catalogueKey(Source $source): string
    {
        return implode('|', [$source->kind, $source->library_id, $source->edition_of_id, $source->edition_label,
            self::normalize(self::baseTitle($source->title))]);
    }

    /** The title without its volume: «… گلپایگانی - جلد اول: آ - ج» and «… مرعشی نجفی - 13» → «… گلپایگانی», «… مرعشی نجفی». */
    public static function baseTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title));

        return trim(preg_replace(['/\s*-\s*(?:عکسی\s*)?جلد(?:\s.*)?$/u', '/\s*-\s*[0-9۰-۹]+$/u'], '', $title));
    }

    /** The two spellings of one title as one: «حضرت آیة الله» and «آیت‌الله», ي and ی, ك and ک, the non-joiner and a space. */
    public static function normalize(string $title): string
    {
        $title = strtr($title, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', "\u{200C}" => ' ']);
        $title = preg_replace(['/حضرت/u', '/آ[یي][ةت]\s*ال?له/u', '/\s+/u'], ['', 'آیت الله', ' '], $title);

        return trim($title);
    }

    /** The volume a title names: «- جلد هشتم: ن - ی» → 8, «- جلد سی و یکم» → 31, «- جلد 13», «- 13» → 13; else null. */
    public static function volumeNumber(string $title): ?int
    {
        if (preg_match('/-\s*(?:عکسی\s*)?جلد\s*([^:\-]+)/u', $title, $m) || preg_match('/-\s*([0-9۰-۹]+)\s*$/u', $title, $m)) {
            $words = trim(preg_replace('/\s+/u', ' ', strtr($m[1], ["\u{200C}" => ' '])));
            if (preg_match('/^[0-9۰-۹]+$/u', $words)) {
                return (int) strtr($words, array_combine(['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'], range(0, 9)));
            }
            // «بیست و هشتم»: the tens, then the ordinal
            $parts = explode(' و ', $words);
            $last = array_pop($parts);
            if (! isset(self::UNITS[$last])) {
                return null;
            }
            $number = self::UNITS[$last];
            foreach ($parts as $part) {
                if (! isset(self::TENS[$part])) {
                    return null;
                }
                $number += self::TENS[$part];
            }

            return $number;
        }

        return null;
    }

    /** Whether the title names a part of a volume: «- جلد نهم - بخش دوم», «- جلد ۳/۲۴: اهدایی طباطبائی», «(مجلد پنجم)». */
    public static function isPart(string $title): bool
    {
        return (bool) preg_match('/-\s*(?:عکسی\s*)?جلد[^:]*(?:\/|بخش|مجلد)/u', $title);
    }

    /** What the title says of the volume after its number: «- جلد اول: آ - ج» → «آ - ج», evened out; '' for none. */
    private static function subtitle(string $title): string
    {
        return preg_match('/-\s*(?:عکسی\s*)?جلد[^:]*:(.+)$/u', $title, $m) ? self::normalize($m[1]) : '';
    }

    public static function lowestVolume(Source $source): int
    {
        return $source->volumes->map(fn ($v) => self::volumeNumber($v->title) ?? $v->volume_number ?? PHP_INT_MAX)->min() ?? PHP_INT_MAX;
    }
}
