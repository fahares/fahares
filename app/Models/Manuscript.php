<?php

namespace App\Models;

use App\Models\Concerns\HasSourceEntries;
use App\Models\Concerns\SyncsPrimaryContributor;
use App\Support\CopyDateRange;
use App\Support\HijriYear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\ModelObserver;
use Laravel\Scout\Searchable;

class Manuscript extends Model
{
    use HasSourceEntries;
    use Searchable;
    use SyncsPrimaryContributor;

    protected $guarded = ['id'];

    public const PROVENANCE_RESEARCHER = 'researcher';

    public const PROVENANCE_CATALOGUE = 'catalogue';

    /**
     * The copies whose citation entry (Fankha's place of the copy in its catalogue volume) meets the conditions: its
     * source_volume_id (the catalogue volume), catalog_volume_part_id or cataloger_id (step 14.402, backlog item 170).
     * A subquery on the entries' indexes, so the copy query keeps its own columns and orders.
     */
    public function scopeWhereCitation($query, \Closure $conditions)
    {
        return $query->whereIn('manuscripts.id', SourceEntry::citations()->where($conditions)->select('legacy_id'));
    }

    /** The copies Fankha lists; one made in the panel has no catalog (backlog item 149). The corpus commands look among these. */
    public function scopeFromFankha($query)
    {
        // the copy's Fankha entry names its source (step 14.399, backlog item 170)
        return $query->whereHas('fankhaEntry', fn ($q) => $q->whereNotNull('source_id'));
    }

    /**
     * Where the knowledge of a copy made in the panel came from (ManuscriptCreator, backlog item 149): a researcher
     * who saw the copy, or a printed catalogue. Null for a copy of the catalogues' records.
     *
     * @return array{kind: string, text: ?string, suggestion_id: ?int, label: string, chip: string}|null
     */
    public function provenance(): ?array
    {
        $p = $this->metadata['provenance'] ?? null;
        if (! is_array($p) || empty($p['kind'])) {
            return null;
        }
        $text = filled($p['text'] ?? null) ? trim($p['text']) : null;
        [$label, $chip] = $p['kind'] === self::PROVENANCE_CATALOGUE
            ? [$text ?? 'برگرفته از فهرست چاپی', 'افزوده از فهرست']
            : [$text ? "به گزارش {$text}، که نسخه را دیده است" : 'بر پایۀ دیدن نسخه', 'افزودۀ پژوهشگر'];

        return ['kind' => $p['kind'], 'text' => $text, 'suggestion_id' => isset($p['suggestion_id']) ? (int) $p['suggestion_id'] : null, 'label' => $label, 'chip' => $chip];
    }

    /**
     * The copy's extent as a reader sees it: «۱۰ برگ (۳۴پ–۴۳ر)», «۲۸ صفحه (۱–۲۸)», «۸۶ برگ، ۱۷۰ صفحه» (backlog item 97).
     * The range is the leaves or pages the copy takes in its volume.
     */
    public function extentLabel(bool $withRange = true): ?string
    {
        $parts = array_filter([
            $this->folios ? \App\Support\Fa::digits($this->folios) . ' برگ' : null,
            $this->pages ? \App\Support\Fa::digits($this->pages) . ' صفحه' : null,
        ]);
        if (! $parts) {
            return null;
        }
        $range = $withRange && $this->range_from
            ? ' (' . \App\Support\Fa::digits($this->range_from === $this->range_to ? $this->range_from : $this->range_from . '–' . $this->range_to) . ')'
            : '';

        return implode('، ', $parts) . $range;
    }

    /** «برگ‌های ۳۴پ تا ۴۳ر», «صفحه‌های ۱ تا ۲۸», «برگ ۳۶ر»: where the copy sits in its volume, or null without a range. */
    public function rangeLabel(): ?string
    {
        if (! $this->range_from) {
            return null;
        }
        $pages = ! $this->folios && $this->pages;
        if ($this->range_from === $this->range_to) {
            return ($pages ? 'صفحۀ ' : 'برگ ') . \App\Support\Fa::digits($this->range_from);
        }

        return ($pages ? 'صفحه‌های ' : 'برگ‌های ') . \App\Support\Fa::digits($this->range_from) . ' تا ' . \App\Support\Fa::digits($this->range_to);
    }

    /**
     * The range columns of a copy date (CopyDateRange), as they are stored; a date that names the author's time
     * («نزدیک عصر مؤلف») takes its range from the death year of the work's author, when it is given, and one that
     * is a three-digit year far before the work's floor (copyFloors) is read again (CopyDateRange::reread).
     *
     * A duration («1084-1107ق») that the copy shares with another treatise of its volume ($sharedInVolume,
     * sharedVolumeDates) has no kind: the catalogue may mean the years the whole volume took, and then this treatise
     * was written at some time in them (step 14.188).
     */
    public static function copyDateColumns(?string $raw, ?int $authorDeath = null, ?int $floor = null, bool $sharedInVolume = false): array
    {
        $range = CopyDateRange::relative($raw, $authorDeath) ?? CopyDateRange::reread($raw, $floor) ?? CopyDateRange::parse($raw);

        return ['copy_date_from' => $range['from'], 'copy_date_to' => $range['to'], 'copy_date_certainty' => $range['certainty'],
            'copy_date_hedge_raw' => $range['hedge'] ? mb_substr($range['hedge'], 0, 60) : null,
            'copy_date_kind' => $sharedInVolume && $range['kind'] === \App\Support\DateSpan::SPAN ? null : $range['kind']];
    }

    /**
     * The volumes in which two treatises or more carry the same date text, as "library|volume|date text" keys
     * (volumeDateKey), for the copies given (rows with library_id, collection_key and copy_date_raw). Only a date
     * that reads as a duration is looked up, since only its kind depends on the answer: about one copy in a hundred.
     *
     * @return array<string, true>
     */
    public static function sharedVolumeDates(iterable $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            if ($row->collection_key !== null && $row->collection_key !== '' && (string) $row->copy_date_raw !== ''
                && CopyDateRange::parse($row->copy_date_raw)['kind'] === \App\Support\DateSpan::SPAN) {
                $keys[(int) $row->library_id][$row->collection_key] = true;
            }
        }
        $shared = [];
        foreach ($keys as $library => $volumes) {
            foreach (array_chunk(array_map('strval', array_keys($volumes)), 500) as $chunk) {
                $found = DB::table('manuscripts')->where('library_id', $library ?: null)->whereIn('collection_key', $chunk)
                    ->whereNotNull('copy_date_raw')->where('copy_date_raw', '<>', '')
                    ->groupBy('collection_key', 'copy_date_raw')->havingRaw('COUNT(*) > 1')->get(['collection_key', 'copy_date_raw']);
                foreach ($found as $f) {
                    $shared[self::volumeDateKey($library, $f->collection_key, $f->copy_date_raw)] = true;
                }
            }
        }

        return $shared;
    }

    public static function volumeDateKey(?int $library, ?string $volume, ?string $raw): string
    {
        return (int) $library . '|' . $volume . '|' . $raw;
    }

    /**
     * The death year of each work's author (its Hijri death year, else an end of its death range, else the middle of
     * the century the author lived in), for the copy dates that name the author's time.
     *
     * @return array<int, int> work id => year
     */
    public static function authorDeaths(iterable $workIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($workIds) ? $workIds : iterator_to_array($workIds)))));
        if (! $ids) {
            return [];
        }

        return DB::table('works')->join('people', 'people.id', '=', 'works.author_id')->whereIn('works.id', $ids)
            ->selectRaw('works.id AS id, COALESCE(people.death_year_hijri, people.death_to, people.death_from, people.century_hijri * 100 - 50) AS year')
            ->pluck('year', 'id')->filter()->map(fn ($y) => (int) $y)->all();
    }

    /**
     * The sortable year that goes with the range columns of a re-read date: its year when the readings are one, else
     * none, since the catalogue's three-digit year is not the copy's. Empty for any other date.
     *
     * @return array{copy_date_hijri_year?: ?int}
     */
    public static function rereadSortYear(array $columns): array
    {
        return CopyDateRange::rereadYear($columns['copy_date_hedge_raw'] ?? null) === null ? []
            : ['copy_date_hijri_year' => $columns['copy_date_from'] === $columns['copy_date_to'] ? $columns['copy_date_from'] : null];
    }

    /**
     * The earliest year a copy of each work can be of: the later of the work's composition and its author's birth
     * (the birth year, else LIFE years before the death). Works with neither are left out.
     *
     * @return array<int, int> work id => year
     */
    public static function copyFloors(iterable $workIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($workIds) ? $workIds : iterator_to_array($workIds)))));
        if (! $ids) {
            return [];
        }
        $life = \App\Support\DateEstimates\Loader::LIFE;

        // a part that reads its date from the work it is a part of: that work's composition (step 14.315)
        $inherited = collect(\App\Support\WorkParts::inheritedCompositions())->only($ids)->map(fn (Work $whole) => (int) $whole->composition_date_from)->filter(fn (int $y) => $y > 0)->all();

        return $inherited + DB::table('works')->leftJoin('people', 'people.id', '=', 'works.author_id')->whereIn('works.id', $ids)
            ->selectRaw("works.id AS id, GREATEST(COALESCE(works.composition_date_from, 0), COALESCE(people.birth_year_hijri, COALESCE(people.death_from, people.death_year_hijri, people.century_hijri * 100 - 99) - {$life}, 0)) AS year")
            ->pluck('year', 'id')->map(fn ($y) => (int) $y)->filter(fn (int $y) => $y > 0)->all();
    }

    protected static function booted(): void
    {
        // Keep the sortable year in step with the date text unless the caller set both
        static::saving(function (Manuscript $manuscript) {
            if ($manuscript->isDirty('copy_date_raw') && ! $manuscript->isDirty('copy_date_hijri_year')) {
                $manuscript->copy_date_hijri_year = HijriYear::parse($manuscript->copy_date_raw);
            }
            // ...and its range, certainty and hedge word (backlog item 96)
            if ($manuscript->isDirty('copy_date_raw') || $manuscript->isDirty('work_id')) {
                $death = CopyDateRange::namesAuthorTime($manuscript->copy_date_raw) ? self::authorDeaths([$manuscript->work_id])[$manuscript->work_id] ?? null : null;
                $floor = CopyDateRange::shortYear($manuscript->copy_date_raw) !== null ? self::copyFloors([$manuscript->work_id])[$manuscript->work_id] ?? null : null;
                // the same date on another treatise of its volume (its own row is not saved yet, so one other is enough)
                $shared = $manuscript->collection_key !== null && (string) $manuscript->copy_date_raw !== ''
                    && DB::table('manuscripts')->where('library_id', $manuscript->library_id)->where('collection_key', $manuscript->collection_key)
                        ->where('copy_date_raw', $manuscript->copy_date_raw)->when($manuscript->exists, fn ($q) => $q->where('id', '<>', $manuscript->id))->exists();
                $manuscript->forceFill(self::copyDateColumns($manuscript->copy_date_raw, $death, $floor, $shared));
                $manuscript->forceFill(self::rereadSortYear($manuscript->only(['copy_date_from', 'copy_date_to', 'copy_date_hedge_raw'])));
            }
            // The city the place of copying names, beside its text (item 139, step 14.209)
            if ($manuscript->isDirty('copy_place')) {
                $manuscript->copy_city = \App\Support\CopyPlaces::cityOf($manuscript->copy_place);
                $manuscript->copy_region = \App\Support\CopyPlaces::regionOf($manuscript->copy_place);
            }
            // The sort/collection key is the shelfmark cut to the indexed length
            if ($manuscript->isDirty('shelfmark') && ! $manuscript->isDirty('shelfmark_key')) {
                $manuscript->shelfmark_key = $manuscript->shelfmark === null ? null : mb_substr($manuscript->shelfmark, 0, 100);
            }
            // ...and so is the bound volume it names (backlog item 97), which the catalogue it cites can change
            $given = $manuscript->givenSources();
            if (($manuscript->isDirty('shelfmark') || array_key_exists('raw_text', $given)) && ! $manuscript->isDirty('collection_key')) {
                // a writer that sets the text gives it under its old name, which the entry takes after the save (step 14.407)
                $text = array_key_exists('raw_text', $given) ? $given['raw_text'] : ($manuscript->exists ? $manuscript->fankhaText() : null);
                $manuscript->collection_key = self::resolvedCollectionKey($manuscript->shelfmark, $text, $manuscript->getOriginal('collection_key'));
            }
        });

        // The works index carries each work's manuscript incipits/explicits (Work::manuscriptSearchTexts)
        static::saved(function (Manuscript $manuscript) {
            if (! $manuscript->wasRecentlyCreated && ! $manuscript->wasChanged(['incipit_text', 'explicit_text', 'work_id'])) {
                return;
            }
            $workIds = [$manuscript->work_id];
            if ($manuscript->wasChanged('work_id')) {
                $workIds[] = $manuscript->getPrevious()['work_id'] ?? null;
            }
            static::reindexWorks($workIds);
        });

        static::deleted(fn (Manuscript $manuscript) => static::reindexWorks([$manuscript->work_id]));

        // The «مجموعه» record of its volume follows its library and collection_key (backlog item 163, step 14.322)
        static::saved(function (Manuscript $manuscript) {
            if ($manuscript->wasRecentlyCreated || $manuscript->wasChanged(['collection_key', 'library_id', 'work_id', 'collection_pinned', 'collection_id'])) {
                // an editor moved it (collection_pinned, step 14.330): the collection it left is counted and derived again too
                $left = $manuscript->wasChanged('collection_id') ? (int) $manuscript->getOriginal('collection_id') : 0;
                \App\Support\Collections::sync([$manuscript->id]);
                if ($left) {
                    \App\Support\Collections::recount([$left]);
                    \App\Support\Collections::derive([$left]);
                    \App\Support\Collections::reindex([$left]);
                }
                $manuscript->collection_id = DB::table('manuscripts')->where('id', $manuscript->id)->value('collection_id');
                $manuscript->syncOriginalAttribute('collection_id');
            } elseif ($manuscript->wasChanged(\App\Support\CollectionValues::COPY_COLUMNS)
                && ($collection = DB::table('manuscripts')->where('id', $manuscript->id)->value('collection_id'))) {
                // what its volume is said to be follows what its treatises say (step 14.323); the link is written
                // beside the model, so the row is asked
                \App\Support\Collections::derive([(int) $collection]);
            }
        });
        // (the link is written beside the model, so the row is asked which collection it leaves)
        static::deleting(fn (Manuscript $manuscript) => $manuscript->setAttribute('collection_id', DB::table('manuscripts')->where('id', $manuscript->id)->value('collection_id')));
        static::deleted(function (Manuscript $manuscript) {
            if (! $manuscript->collection_id) {
                return;
            }
            $left = [(int) $manuscript->collection_id];
            \App\Support\Collections::recount($left);
            // what the volume is said to be no longer rests on the treatise that is gone (step 14.350): a copy removed,
            // merged away, or a panel copy undone; nothing to do for a collection the recount removed
            \App\Support\Collections::derive($left);
            \App\Support\Collections::reindex($left);
        });

        // The scribes' tab of the search holds when and where each scribe copied (step 14.155)
        static::updated(function (Manuscript $manuscript) {
            if ($manuscript->wasChanged(['copy_date_from', 'copy_date_to', 'copy_place'])) {
                Person::refreshScribesOf([$manuscript->id]);
            }
        });
    }

    protected static function reindexWorks(array $workIds): void
    {
        $workIds = array_values(array_unique(array_filter($workIds)));
        if ($workIds && ! ModelObserver::syncingDisabledFor(Work::class)) {
            Work::whereIn('id', $workIds)->searchable();
        }
    }

    protected static function primaryContributorPivot(): array
    {
        return ['manuscript_people', 'manuscript_id', 'scribe_id', ManuscriptPerson::SCRIBE];
    }

    protected function casts(): array
    {
        return [
            'is_bika' => 'boolean',
            'is_bita' => 'boolean',
            'is_autograph' => 'boolean',
            'copy_date_hijri_year' => 'integer',
            'copy_date_from' => 'integer',
            'copy_date_to' => 'integer',
            'folios' => 'integer',
            'pages' => 'integer',
            'range_from_number' => 'integer',
            'range_to_number' => 'integer',
            'lines' => 'integer',
            'line_counts' => 'array',
            'is_corrected' => 'boolean',
            'has_marginal_notes' => 'boolean',
            'is_ruled' => 'boolean',
            'has_catchwords' => 'boolean',
            'is_facsimile' => 'boolean',
            'is_collated' => 'boolean',
            'is_illuminated' => 'boolean',
            'is_illustrated' => 'boolean',
            'has_author_marginalia' => 'boolean',
            'copied_from_author_copy' => 'boolean',
            'collated_with_author_copy' => 'boolean',
            'corrected_by_author' => 'boolean',
            'has_other_scribes' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function getAccessionNumberAttribute(): ?string
    {
        return $this->shelfmark;
    }

    public function getPersianSlugAttribute(): string
    {
        $workTitle = null;
        if ($this->relationLoaded('work') && $this->work) {
            $workTitle = $this->work->clean_title ?: $this->work->primary_title;
        } elseif (!empty($this->work_id)) {
            $workTitle = $this->work?->clean_title ?: $this->work?->primary_title;
        }

        $libName = $this->libraryRecord?->name ?? $this->library;
        $shelfmark = $this->shelfmark;

        $parts = array_filter([$workTitle, $libName, $shelfmark]);
        $text = !empty($parts) ? implode(' ', $parts) : 'نسخه خطی';

        $slug = \Illuminate\Support\Str::slug($text, '-', null);
        if (mb_strlen($slug) > 80) {
            $slug = mb_substr($slug, 0, 80);
            $lastHyphen = mb_strrpos($slug, '-');
            if ($lastHyphen > 30) {
                $slug = mb_substr($slug, 0, $lastHyphen);
            }
        }
        return $slug ?: 'manuscript';
    }

    public function getRouteKey(): string
    {
        $slug = $this->persian_slug;
        return $slug ? "{$this->id}-{$slug}" : (string) $this->id;
    }

    public function getScribeDisplayNameAttribute(): string
    {
        if (!empty($this->scribe_name)) {
            return $this->scribe_name;
        }
        if ($this->is_autograph) {
            return 'مؤلف';
        }
        return 'نامشخص';
    }

    /** The library catalogue place Fankha cites for the copy, «[ف: 2-414]», as its own entry (backlog item 51). */
    public function citedEntry(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return $this->morphOne(\App\Models\SourceEntry::class, 'citable')->where('legacy_type', 'fankha_citation');
    }

    /** Fankha's citation of the copy's catalogue place, from its entry (step 14.399); eager load citedEntry for a list. */
    public function getCatalogCitationAttribute(): ?string
    {
        return $this->citedEntry?->citation_text;
    }

    public function getCenturyTextAttribute(): ?string
    {
        if ($this->copy_date_hijri_year) {
            $century = (int) ceil($this->copy_date_hijri_year / 100);
            return "قرن {$century} هـ.ق";
        }
        if (!empty($this->copy_date_raw) && preg_match('/قرن\s*(\d+)/u', $this->copy_date_raw, $m)) {
            return "قرن {$m[1]} هـ.ق";
        }
        return null;
    }

    public function getIncipitMatchesWorkAttribute(): bool
    {
        if (isset($this->metadata['incipit_matches_work'])) {
            return (bool) $this->metadata['incipit_matches_work'];
        }
        if (!empty($this->incipit_text) && in_array(trim($this->incipit_text), ['برابر', 'برابر است', 'برابر؛', 'برابر.'])) {
            return true;
        }
        if (!empty($text = $this->fankhaText())) {
            return (bool) preg_match('/(?:^|[؛\n])\s*آغاز(?:\s*و\s*انجام)?[:\s]\s*برابر/u', $text);
        }
        return false;
    }

    public function getExplicitMatchesWorkAttribute(): bool
    {
        if (isset($this->metadata['explicit_matches_work'])) {
            return (bool) $this->metadata['explicit_matches_work'];
        }
        if (!empty($this->explicit_text) && in_array(trim($this->explicit_text), ['برابر', 'برابر است', 'برابر؛', 'برابر.'])) {
            return true;
        }
        if (!empty($text = $this->fankhaText())) {
            return (bool) (
                preg_match('/(?:^|[؛\n])\s*آغاز\s*و\s*انجام[:\s]\s*برابر/u', $text) ||
                preg_match('/(?:^|[؛\n])\s*انجام[:\s]\s*برابر/u', $text)
            );
        }
        return false;
    }

    public function getIncipitAttribute(): ?string
    {
        $list = $this->incipits_list;
        return !empty($list[0]['text']) ? $list[0]['text'] : $this->incipit_text;
    }

    public function getExplicitAttribute(): ?string
    {
        $list = $this->explicits_list;
        return !empty($list[0]['text']) ? $list[0]['text'] : $this->explicit_text;
    }

    /**
     * The work's incipit or explicit a copy's «برابر 2» or «برابر جلد دوم» points at (text_excerpts, backlog item 98),
     * as an item of incipits_list or explicits_list; null when the text is no such pointer or it points at nothing.
     */
    public function pointedExcerpt(string $kind, string $text): ?array
    {
        if (! preg_match('/^برابر\s*\S/u', $text) || in_array($text, ['برابر است', 'برابر؛', 'برابر.'], true)) {
            return null;
        }
        $this->pointers ??= $this->excerpts()->with('sameAs')->get()->keyBy('kind');
        $target = $this->pointers->get($kind)?->sameAs;
        if (! $target || blank($target->text)) {
            return null;
        }

        return [
            'label' => 'منطبق بر ' . $target->heading . ' اثر (در مأخذ: «' . $text . '»)',
            'text' => $target->text,
            'is_work_match' => true,
            'is_placeholder' => false,
            'work_excerpt_id' => $target->id,
        ];
    }

    /** @var \Illuminate\Support\Collection<string, TextExcerpt>|null the copy's pointers by kind, loaded once */
    private ?\Illuminate\Support\Collection $pointers = null;

    public function getIncipitsListAttribute(): array
    {
        $meta = $this->metadata['incipits'] ?? [];
        $list = [];
        if (!empty($meta) && is_array($meta)) {
            $list = $meta;
        } elseif (!empty($this->incipit_text)) {
            $list = [['label' => null, 'text' => $this->incipit_text]];
        }

        $filtered = [];
        $hasBarabar = false;
        foreach ($list as $item) {
            $txt = trim($item['text'] ?? '');
            // «برابر 2», «برابر جلد دوم»: the work's excerpt the copy points at (backlog item 98)
            if (($pointed = $this->pointedExcerpt('incipit', $txt)) !== null) {
                $filtered[] = $pointed;
                continue;
            }
            if (in_array($txt, ['برابر', 'برابر است', 'برابر؛', 'برابر.'])) {
                $hasBarabar = true;
            } else {
                $filtered[] = $item;
            }
        }

        if (!empty($filtered)) {
            return $filtered;
        }

        if ($hasBarabar || $this->incipit_matches_work) {
            $workIncipit = !empty($this->work?->incipit_text) ? trim(preg_replace('/<!--\s*page:\s*\d+\s*-->/u', '', $this->work->incipit_text)) : null;
            if (!empty($workIncipit)) {
                return [[
                    'label' => 'منطبق بر آغاز اثر (در مأخذ: «برابر»)',
                    'text' => $workIncipit,
                    'is_work_match' => true,
                    'is_placeholder' => false,
                ]];
            } else {
                return [[
                    'label' => 'در مأخذ: «برابر»',
                    'text' => 'آغاز این نسخه در مأخذ فهرست‌نگاری، برابر با آغاز کتاب قید گردیده است.',
                    'is_work_match' => true,
                    'is_placeholder' => true,
                ]];
            }
        }

        return [];
    }

    public function getExplicitsListAttribute(): array
    {
        $meta = $this->metadata['explicits'] ?? [];
        $list = [];
        if (!empty($meta) && is_array($meta)) {
            $list = $meta;
        } elseif (!empty($this->explicit_text)) {
            $list = [['label' => null, 'text' => $this->explicit_text]];
        }

        $filtered = [];
        $hasBarabar = false;
        foreach ($list as $item) {
            $txt = trim($item['text'] ?? '');
            // «برابر 2», «برابر جلد دوم»: the work's excerpt the copy points at (backlog item 98)
            if (($pointed = $this->pointedExcerpt('explicit', $txt)) !== null) {
                $filtered[] = $pointed;
                continue;
            }
            if (in_array($txt, ['برابر', 'برابر است', 'برابر؛', 'برابر.'])) {
                $hasBarabar = true;
            } else {
                $filtered[] = $item;
            }
        }

        if (!empty($filtered)) {
            return $filtered;
        }

        if ($hasBarabar || $this->explicit_matches_work) {
            $workExplicit = !empty($this->work?->explicit_text) ? trim(preg_replace('/<!--\s*page:\s*\d+\s*-->/u', '', $this->work->explicit_text)) : null;
            if (!empty($workExplicit)) {
                return [[
                    'label' => 'منطبق بر انجام اثر (در مأخذ: «برابر»)',
                    'text' => $workExplicit,
                    'is_work_match' => true,
                    'is_placeholder' => false,
                ]];
            } else {
                return [[
                    'label' => 'در مأخذ: «برابر»',
                    'text' => 'انجام این نسخه در مأخذ فهرست‌نگاری، برابر با انجام کتاب قید گردیده است.',
                    'is_work_match' => true,
                    'is_placeholder' => true,
                ]];
            }
        }

        return [];
    }

    public function getOwnershipAndSealsListAttribute(): array
    {
        $seals = $this->metadata['ownership_and_seals'] ?? [];
        return is_array($seals) ? array_values(array_filter($seals)) : [];
    }

    public function getEditorialNotesListAttribute(): array
    {
        $notes = $this->metadata['editorial_notes'] ?? [];
        return is_array($notes) ? array_values(array_filter($notes)) : [];
    }

    public function getResidualNotesTextAttribute(): ?string
    {
        $res = $this->metadata['residual_notes'] ?? null;
        if (is_string($res) && trim($res) !== '') {
            return trim($res);
        }
        if (is_array($res) && !empty($res)) {
            return implode(' ؛ ', array_filter($res));
        }
        return null;
    }

    /**
     * Copy listed under a heterogeneous (غیر همانند) work entry: a distinct text sharing the entry's title.
     */
    public function getIsDistinctWorkAttribute(): bool
    {
        return (bool) ($this->metadata['is_distinct_work'] ?? false);
    }

    public function searchableAs(): string
    {
        return 'manuscripts_index';
    }

    /** A batch given to the engine reads its Fankha entries in one query (step 14.399, backlog item 170). */
    public function makeSearchableUsing(\Illuminate\Support\Collection $models): \Illuminate\Support\Collection
    {
        return $models instanceof \Illuminate\Database\Eloquent\Collection ? $models->loadMissing('fankhaEntry') : $models;
    }

    public function makeAllSearchableUsing($query)
    {
        return $query->with(['work:id,primary_title,clean_title,author_name,manuscripts_count', 'work.subjects:id,name', 'work.languages:id,name', 'work.authors:people.id', 'scripts:id,name', 'scribes:people.id', 'libraryRecord:id,full_name', 'notes:id,manuscript_id,type,also',
            'catalogueEntries' => fn ($q) => $q->select('id', 'citable_type', 'citable_id', 'source_volume_id'),
            'fankhaEntry' => fn ($q) => $q->select('id', 'citable_type', 'citable_id', 'legacy_type', 'volume_number', 'entry_number')]);
    }

    public function toSearchableArray(): array
    {
        $workTitle = null;
        $authorName = null;
        if ($this->relationLoaded('work') && $this->work) {
            $workTitle = $this->work->clean_title ?: $this->work->primary_title;
            $authorName = $this->work->author_name;
        } elseif (!empty($this->work_id)) {
            $w = $this->work;
            $workTitle = $w?->clean_title ?: $w?->primary_title;
            $authorName = $w?->author_name;
        }

        return [
            'id' => (int) $this->id,
            'work_id' => (int) $this->work_id,
            'work_title' => \App\Helpers\TextNormalizer::titleForms($workTitle),
            // Among equal matches, a manuscript of the work found in more manuscripts: «کافی» is al-Kafi, not
            // «کافی در ترسیم» (backlog item 93)
            'work_manuscripts_count' => (int) ($this->work?->manuscripts_count ?? 0),
            'author_name' => \App\Helpers\TextNormalizer::normalize($authorName),
            'library_id' => $this->library_id ? (int) $this->library_id : null,
            'library' => $this->library,
            'library_full_name' => $this->library_id ? $this->libraryRecord?->full_name : null,
            'city' => $this->city,
            'shelfmark' => \App\Helpers\TextNormalizer::normalize($this->shelfmark),
            'shelfmark_key' => $this->shelfmark_key,
            'scribe_id' => $this->scribe_id ? (int) $this->scribe_id : null,
            'scribe_name' => \App\Helpers\TextNormalizer::normalize($this->scribe_name ?: ($this->is_autograph ? 'مؤلف' : null)),
            'is_bika' => (bool) $this->is_bika,
            'is_bita' => (bool) $this->is_bita,
            'is_autograph' => (bool) $this->is_autograph,
            'copy_date_raw' => \App\Helpers\TextNormalizer::normalize($this->copy_date_raw),
            'copy_date_hijri_year' => $this->copy_date_hijri_year ? (int) $this->copy_date_hijri_year : null,
            // The bounds of the copying date and the century it lies in, as the filters of the search read them
            // (whereCopiedWithin, step 14.114)
            ...$this->copyBounds(),
            'copy_place' => \App\Helpers\TextNormalizer::normalize($this->copy_place),
            'commissioned_by' => \App\Helpers\TextNormalizer::normalize($this->commissioned_by),
            'script_names' => $this->script_names,
            'script_style' => $this->script_style,
            'folios' => $this->folios ? (int) $this->folios : null,
            'lines' => $this->lines ? (int) $this->lines : null,
            'dimensions' => $this->dimensions,
            'paper' => $this->paper,
            'binding' => $this->binding,
            'incipit_text' => \App\Helpers\TextNormalizer::normalize($this->incipit_text),
            'explicit_text' => \App\Helpers\TextNormalizer::normalize($this->explicit_text),
            // the document keeps these keys; the values are the Fankha entry's (step 14.399, backlog item 170)
            'volume_number' => (int) $this->fankhaEntry?->volume_number,
            'sequence_number' => (int) $this->fankhaEntry?->entry_number,
            'is_corrected' => (bool) $this->is_corrected,
            'has_marginal_notes' => (bool) $this->has_marginal_notes,
            'is_ruled' => (bool) $this->is_ruled,
            'has_catchwords' => (bool) $this->has_catchwords,
            'is_facsimile' => (bool) $this->is_facsimile,
            'is_collated' => (bool) $this->is_collated,
            'is_illuminated' => (bool) $this->is_illuminated,
            'is_illustrated' => (bool) $this->is_illustrated,
            'has_author_marginalia' => (bool) $this->has_author_marginalia,
            // how near the copy stands to the author's own (step 14.130)
            'copied_from_author_copy' => (bool) $this->copied_from_author_copy,
            'collated_with_author_copy' => (bool) $this->collated_with_author_copy,
            'corrected_by_author' => (bool) $this->corrected_by_author,
            'scripts' => $this->relationLoaded('scripts') ? $this->scripts->pluck('name')->all() : [],
            // The types of the copy's notes, a note's other types too (step 14.269)
            'note_types' => ManuscriptNote::typesOf($this->notes),
            // The work's subjects and languages, as filters of the manuscripts tab (backlog item 83, phase 5)
            'subjects' => $this->work?->subjects->pluck('name')->all() ?? [],
            'languages' => $this->work?->languages->pluck('name')->all() ?? [],
            // Every scribe, not only the first: «the manuscripts this person copied» (backlog item 83, phase 6)
            'scribe_ids' => $this->scribes->pluck('id')->map(fn ($id) => (int) $id)->all(),
            // Every author of the work, so the copies of an author's works can be listed (step 14.114)
            'author_ids' => $this->work?->authors->pluck('id')->map(fn ($id) => (int) $id)->all() ?? [],
            // The printed catalogue volumes that describe the copy, so a source page's count of a volume's copies
            // links to them (step 13.116)
            'source_volume_ids' => $this->catalogueEntries->pluck('source_volume_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            // What the copy reads from its «مجموعه», over its own hand, lines, scribe, size, paper, binding and note
            // types above (backlog item 163, step 14.329)
            ...\App\Support\CollectionIndex::fields($this),
        ];
    }

    /**
     * The first and last year the copy can have been made in (copy_date_from, copy_date_to), and the century the
     * whole range lies in, when it lies in one. The year alone is not read: a copy with a year but no range has a
     * solar date held as a hijri year (102 copies, step 14.114).
     *
     * @return array{copy_from: ?int, copy_to: ?int, copy_century: ?int}
     */
    public function copyBounds(): array
    {
        $from = $this->copy_date_from !== null ? (int) $this->copy_date_from : null;
        $to = $this->copy_date_to !== null ? (int) $this->copy_date_to : null;
        $century = $from !== null && $to !== null && $from >= 1 && intdiv($from - 1, 100) === intdiv($to - 1, 100) ? intdiv($from - 1, 100) + 1 : null;

        return ['copy_from' => $from, 'copy_to' => $to, 'copy_century' => $century];
    }

    /**
     * Copies whose whole copying date lies within the years, as copyBounds takes it. The search and the insights
     * page both filter by it, so a copy dated only «قرن ۱۳» is a copy of the 13th century in both (step 14.114).
     */
    public static function whereCopiedWithin($query, ?int $from, ?int $to, string $table = 'manuscripts')
    {
        return $query->when($from !== null, fn ($q) => $q->where("{$table}.copy_date_from", '>=', $from))
            ->when($to !== null, fn ($q) => $q->where("{$table}.copy_date_to", '<=', $to));
    }


    public function cataloger(): BelongsTo
    {
        return $this->belongsTo(Cataloger::class);
    }

    /** Its entries in the printed sources that name a volume and are not rejected: the volumes that describe it */
    public function catalogueEntries(): MorphMany
    {
        return $this->morphMany(SourceEntry::class, 'citable')->whereNotNull('source_volume_id')->where('status', '!=', SourceEntry::STATUS_REJECTED);
    }

    // the copy's catalogue volume and part are its citation entry's (citedEntry->sourceVolume, ->catalogVolumePart; step 14.407)

    /** The copy this one is a photograph or film of, when that is linked (facsimile_of, step 14.16) */
    public function facsimileOf(): ?self
    {
        $id = \Illuminate\Support\Facades\DB::table('manuscript_relations')->where('manuscript_id', $this->id)->where('type', 'facsimile_of')->orderBy('id')->value('related_manuscript_id');

        return $id ? self::find($id) : null;
    }

    /**
     * The photographs between this one and its mother, and the mother itself when it is in the records: a photograph
     * of a photograph whose mother is outside the records ends at that photograph, with no mother.
     *
     * @return array{chain: array<int, self>, mother: ?self}
     */
    public function facsimileChain(): array
    {
        $chain = [];
        $copy = $this;
        for ($i = 0; $i < 5 && ($next = $copy->facsimileOf()); $i++) {
            if (! $next->is_facsimile) {
                return ['chain' => $chain, 'mother' => $next];
            }
            $chain[] = $copy = $next;
        }

        return ['chain' => $chain, 'mother' => null];
    }

    /** What the catalogue says of a photograph's mother («نسخه اصل: …»), up to the first «؛»; not «همان نسخه بالا» */
    public function getOriginalNoteAttribute(): ?string
    {
        if (! preg_match('/(?:نسخه\s+اصل|اصل\s+نسخه)\s*:\s*([^؛\[\n]+)/u', (string) $this->fankhaText(), $m)) {
            return null;
        }
        $note = preg_replace('/^[\s.،]+|[\s.،]+$/u', '', $m[1]);
        if ($note === '' || preg_match('/^((احتمالا|ظاهرا)\S*\s+)?همان\s+نسخه/u', $note)) {
            return null;
        }

        return \Illuminate\Support\Str::limit($note, 160);
    }

    /** The copy a possibly_same relation of this one points at (step 14.16) */
    public function possiblySame(): ?self
    {
        $id = \Illuminate\Support\Facades\DB::table('manuscript_relations')->where('manuscript_id', $this->id)->where('type', 'possibly_same')->orderBy('id')->value('related_manuscript_id');

        return $id ? self::find($id) : null;
    }

    /** Whether the copy's own Fankha entry sits in a catalogue's addenda section (see config/catalogs.php). */
    public function getIsAddendumEntryAttribute(): bool
    {
        // from the copy's entry and its source; a list eager loads fankhaEntry.source:id,code (step 14.399, backlog item 170)
        $entry = $this->fankhaEntry;
        $code = $entry?->source?->code ?? 'fankha';
        foreach (config("catalogs.addenda.{$code}", []) as $range) {
            if ((int) $entry?->volume_number === $range['volume'] && $entry->page_start >= $range['from'] && $entry->page_start <= $range['to']) {
                return true;
            }
        }

        return false;
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    /** The copy's notes with their types, in the order of its text (step 14.254) */
    public function notes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ManuscriptNote::class)->orderBy('position');
    }

    private ?bool $keepsSealsList = null;

    /**
     * Whether the page shows the metadata list of seals, ownership and endowment rather than the typed notes of
     * provenance: the copy has no such note, or a reviewer corrected the list (an approved suggestion on it, or a
     * direct edit of the metadata alone), which the notes table does not hold (step 14.260).
     */
    public function keepsSealsList(): bool
    {
        return $this->keepsSealsList ??= $this->notes->whereIn('type', ManuscriptNote::GROUPS['provenance']['types'])->isEmpty()
            || $this->fieldSuggestions()->where('status', 'approved')->where('field_name', 'ownership_and_seals')->exists()
            || AuditLog::where('auditable_type', self::class)->where('auditable_id', $this->id)->where('action', 'verify')->get(['new_values'])
                ->contains(fn ($log) => array_keys(array_diff_key($log->new_values ?? [], ['suggestion_id' => 1, 'direct_edit' => 1, 'updated_at' => 1])) === ['metadata']
                    && str_contains((string) json_encode($log->new_values['metadata']), 'ownership_and_seals'));
    }

    /**
     * Whether the page still shows the two old lists of the metadata, «فوائد و رسائل» and «یادداشت‌های فهرست‌نگار»:
     * the typed notes of the import (step 14.260) never took their place. So it is for a copy without a note, and for
     * one whose notes were all added by a reviewer (step 14.318): an editor's note that the log shows was a note of
     * the import before, corrected or retyped, is not such a one, nor is the copy an import's note was removed from.
     */
    public function keepsOldNotes(): bool
    {
        if ($this->notes->isEmpty()) {
            return true;
        }
        if ($this->notes->contains(fn (ManuscriptNote $note) => $note->typed_by !== 'editor')) {
            return false;
        }

        return ! AuditLog::where('auditable_type', self::class)->where('auditable_id', $this->id)->where('action', 'verify')
            ->where('old_values', 'like', '%"notes"%')->get(['old_values'])
            ->contains(fn ($log) => ($log->old_values['notes']['typed_by'] ?? 'editor') !== 'editor');
    }

    /**
     * How many seals, ownership notes, endowments and acquisitions the copy has, for the lists of results: its typed
     * notes of provenance, or the metadata list of a copy without them. A list a reviewer corrected is not looked up
     * here (keepsSealsList costs two queries a copy).
     */
    public function provenanceCount(): int
    {
        return $this->notes->whereIn('type', ManuscriptNote::GROUPS['provenance']['types'])->count() ?: count($this->ownership_and_seals_list);
    }

    /**
     * The copy's typed notes by section of its page (ManuscriptNote::GROUPS), each section in the order of the text;
     * a section without notes is left out (step 14.260).
     *
     * @return array<string, \Illuminate\Support\Collection<int, ManuscriptNote>>
     */
    public function noteGroups(): array
    {
        $groups = [];
        foreach (ManuscriptNote::GROUPS as $key => $group) {
            $notes = $this->notes->whereIn('type', $group['types'])->values();
            if ($notes->isNotEmpty()) {
                $groups[$key] = $notes;
            }
        }

        return $groups;
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function libraryRecord(): BelongsTo
    {
        return $this->belongsTo(Library::class, 'library_id');
    }

    public function scribe(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'scribe_id');
    }

    /**
     * Every scribe of the copy in catalog order; the primary one (scribe_id) is at position 0.
     */
    public function scribes(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'manuscript_people')
            ->withPivotValue('role', ManuscriptPerson::SCRIBE)
            ->withPivot(['position', 'note'])
            ->orderByPivot('position');
    }

    /**
     * Every scribe row in catalog order, including names without a person record.
     */
    public function scribeEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ManuscriptPerson::class)->where('role', ManuscriptPerson::SCRIBE)->orderBy('position')->orderBy('id');
    }

    /**
     * The bound volume a shelfmark like «6415/8» belongs to: the number before the slash, plus a
     * text suffix that names another collection of the library («513/8-طباطبائی», «1804/10-عکسی»).
     * A numeric suffix («5722/2-29/62») is a second numbering and is ignored. A mark stuck to the part
     * («622/1ط», «1359/9عکسی», «149/1 معزی») names a collection too, except words that do not: «بخش3» (a
     * section of the treatise), «و …» (a second shelfmark), «تا …», «مکرر», «حاشیه» (a treatise in the
     * margin) and a parenthesis («462/33(آ)»). In the Majlis «-ط» is «-طباطبائی»; the stuck «ط» is kept
     * apart, as its copies are described in another volume of the library's catalogue (step 13.146). Where the
     * copy's text names a library whose stuck marks only describe the copy (DESCRIPTIVE_MARKS), the mark is ignored.
     *
     * @return array{base: string, collection: ?string, part: string}|null
     */
    public static function collectionKey(?string $shelfmark, ?string $rawText = null): ?array
    {
        // Persian digits and the dash variants the catalog uses («111/20 – ب») read as one form
        $shelfmark = strtr(trim((string) $shelfmark), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '–' => '-', '—' => '-', '‐' => '-', '‑' => '-']);
        // A copy with two shelfmarks (its microfilm and its photograph, or two reels) is of the volume the first one
        // names (step 14.322): «2297/1-ف و 2715/1-ف» of «2297-ف», «240/5 و 6927/2-عکس» of «240»; where the first
        // names no part, «9364 و 4981/3ف», of the second's, «4981-ف»
        if (preg_match('/^(.*?\d.*?)\s+و\s+(\d.*)$/su', $shelfmark, $two)) {
            $shelfmark = str_contains($two[1], '/') ? trim($two[1]) : $two[2];
        }
        // The volume number is plain: in «5679-29/19» (Golpayegani) the slash belongs to the old number, not to a part
        if (! preg_match('~^([^/-]*\d[^/-]*?)\s*/\s*([^-]*\d[^-]*?)\s*(?:-\s*(.+))?$~u', $shelfmark, $m)) {
            return null;
        }
        // «ـ ف», «: فیاض», «؛ کر»: the joiners before a mark are not part of it
        $clean = fn (string $t) => preg_replace('/^[\s_:؛ـ\x{200C}-]+|[\s_:؛ـ\x{200C}-]+$/u', '', $t);
        // «462/2(آ)-463/2(ب)-ف»: the second reel's number is no mark; the mark is the «ف» after it (step 14.322)
        $tail = preg_match('~^\d+\s*/.*-\s*([^\d/-]+)$~su', $m[3] ?? '', $second) ? $second[1] : ($m[3] ?? '');
        $tail = $clean($tail);
        $collection = $tail !== '' && preg_match('/\p{L}/u', $tail) ? ($tail === 'ط' ? 'طباطبائی' : $tail) : null;
        preg_match('/^(\d+)\s*(.*)$/su', trim($m[2]), $p);
        $stuck = $clean($p[2] ?? '');
        if ($collection === null && $stuck !== '' && preg_match('/\p{L}/u', $stuck) && ! preg_match('/^(?:بخش|تا\b|و(?:\s|\d|$)|مکرر|حاشیه|\(|\d)/u', $stuck)
            && ! self::describesCopy($stuck, $rawText)) {
            $collection = $stuck;
        }

        $base = trim($m[1]);
        $part = isset($p[1]) ? $p[1] : trim($m[2]);
        // «1/2591/104»: with a short first number and a long second one, the volume is the two («1/2591», as printed
        // in that library), and the third number is the treatise; «2267/6/10» keeps its volume 2267 (step 13.154)
        if (preg_match('/^\d{1,2}$/', $base) && preg_match('~^(\d{3,})\s*/\s*(\d+)~', trim($m[2]), $q)) {
            [$base, $part] = ["{$base}/{$q[1]}", $q[2]];
        }

        return [
            'base' => $base,
            'collection' => $collection,
            'part' => $part,
        ];
    }

    /**
     * The stored form of a copy's volume (the collection_key column): «8740» for «8740/7-58/190», «1068-طباطبائی» for
     * «1068/2-طباطبائی». A Majlis copy the Fankha text describes only from the Senate library's catalogue («[سنا: ف:
     * 2-61]») has the Senate's own numbering, so «791/1» of the Senate is not the Majlis volume 791: its key is
     * «791-سنا» (step 13.146). A copy of the National Library's Arabic series has «2897-ع» (App\Support\MelliSeries).
     */
    public static function collectionKeyOf(?string $shelfmark, ?string $rawText = null): ?string
    {
        $key = self::collectionKey($shelfmark, $rawText);
        if (! $key) {
            return null;
        }
        $collection = self::citesOnlySenate($rawText) ? 'سنا' . ($key['collection'] !== null ? '-' . $key['collection'] : '') : $key['collection'];
        // the National Library's Arabic series: «2897/1» of it is not the Persian volume 2897 (step 14.416)
        if ($collection === null && \App\Support\MelliSeries::arabic($rawText)) {
            $collection = \App\Support\MelliSeries::ARABIC;
        }

        return mb_substr($key['base'] . ($collection !== null ? '-' . $collection : ''), 0, 100);
    }

    /**
     * The volume key of a copy whose shelfmark has no part number: the shelfmark itself, as one number with an optional
     * mark («4824», «ض 20728», «163-طباطبائی»), with «-سنا» for a Majlis copy described only from the Senate catalogue.
     * Null for a shelfmark with a part (collectionKey), with no number («بدون شماره») or with two («39و40د»). Such a key
     * is stored only where fahares:link-plain-volumes finds the copies sharing it bound together (step 14.79).
     */
    public static function plainVolumeKeyOf(?string $shelfmark, ?string $rawText = null): ?string
    {
        // «ض 21509» and «ض21509», «163 طباطبائی» and «163-طباطبائی» are one shelfmark
        $mark = preg_replace('/[\s\x{200C}_ـ–—‐‑-]+/u', '-', trim(strtr((string) $shelfmark, self::DIGITS)));
        $mark = preg_replace('/(?<=\p{L})-?(?=\d)|(?<=\d)-?(?=\p{L})/u', ' ', trim($mark, '-'));
        if ($mark === '' || str_contains($mark, '/') || preg_match_all('/\d+/', $mark) !== 1) {
            return null;
        }

        // a plain number of the National Library's Arabic series: «2897-ع», the key its parts would have (step 14.416)
        if (preg_match('/^\d+$/', $mark) && \App\Support\MelliSeries::arabic($rawText)) {
            $mark .= '-' . \App\Support\MelliSeries::ARABIC;
        }

        return mb_substr($mark . (self::citesOnlySenate($rawText) ? '-سنا' : ''), 0, 100);
    }

    /**
     * The letter of a second number series the copy is of, shown beside its shelfmark where the shelfmark does not
     * carry it: «ع» for the National Library's Arabic series (App\Support\MelliSeries, step 14.416).
     *
     * @return array{mark: string, title: string}|null
     */
    public function seriesMark(): ?array
    {
        return \App\Support\MelliSeries::arabic($this->fankhaText())
            ? ['mark' => \App\Support\MelliSeries::ARABIC, 'title' => 'سری عربی شماره‌های کتابخانۀ ملی'] : null;
    }

    /** The number of a shelfmark that is only a number («38137», «۳۸۱۳۷»), else null. */
    public static function plainNumber(?string $shelfmark): ?int
    {
        $mark = trim(strtr((string) $shelfmark, self::DIGITS));

        return preg_match('/^\d{1,9}$/', $mark) ? (int) $mark : null;
    }

    /**
     * Whether a stored key was set by fahares:link-plain-volumes and still fits the shelfmark: the copy's own plain
     * key, or a run of the library's consecutive numbers («38131–38137») its number falls in (step 14.79).
     */
    public static function keepsLinkedKey(?string $stored, ?string $shelfmark, ?string $rawText = null): bool
    {
        if ($stored === null || self::collectionKeyOf($shelfmark, $rawText) !== null) {
            return false;
        }
        if ($stored === self::plainVolumeKeyOf($shelfmark, $rawText)) {
            return true;
        }
        $n = self::plainNumber($shelfmark);

        return $n !== null && preg_match('/^(\d+)–(\d+)$/u', $stored, $m) && $n >= (int) $m[1] && $n <= (int) $m[2] && ! self::citesOnlySenate($rawText)
            && ! \App\Support\MelliSeries::arabic($rawText);
    }

    /** The collection_key a copy should hold: the volume its shelfmark names, else the linked key it has if that still fits, else null. */
    public static function resolvedCollectionKey(?string $shelfmark, ?string $rawText, ?string $stored): ?string
    {
        return self::collectionKeyOf($shelfmark, $rawText) ?? (self::keepsLinkedKey($stored, $shelfmark, $rawText) ? $stored : null);
    }

    /** Whether a key names a run of separately numbered treatises bound together («38131–38137»). */
    public static function isRunKey(?string $key): bool
    {
        return $key !== null && (bool) preg_match('/^\d+–\d+$/u', $key);
    }

    private const DIGITS = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];

    /**
     * Stuck marks that describe the copy in a library rather than name a collection of it, by the library as the
     * Fankha text writes it: in Ehya «1004/7 عکسی» is one of volume 1004's photocopied treatises (1004/1 to 1004/10),
     * while in Marashi «1390/1عکسی» is in the library's photocopy collection.
     */
    private const DESCRIPTIVE_MARKS = ['مرکز احیاء' => ['عکسی']];

    private static function describesCopy(string $mark, ?string $rawText): bool
    {
        // «38. قم؛ مرکز احیاء؛ شماره نسخه: …»: the library is the second field of the first line
        if ($rawText === null || ! preg_match('/^\s*(?:\d+\.\s*)?[^؛\n]+؛\s*([^؛\n]+?)\s*؛/u', $rawText, $m)) {
            return false;
        }

        return in_array($mark, self::DESCRIPTIVE_MARKS[$m[1]] ?? [], true);
    }

    /** Whether a copy's text cites the Senate library's catalogue («[سنا: …]», «[سنا - ف: …]», «[سنا – ف: …]», «[سنا ف: …]») and not the Majlis one («[ف: …]»). */
    public static function citesOnlySenate(?string $rawText): bool
    {
        return $rawText !== null && preg_match('/\[\s*سنا\s*(?:[-–—]?\s*ف\s*)?:/u', $rawText) && ! preg_match('/\[\s*ف\s*:/u', $rawText);
    }

    /**
     * Whether the catalogue says the treatise is written in the margin of another: «در هامش» or «نوشته شده در حاشیه»
     * as a clause of its own, or a shelfmark marked «حاشیه» («3008/18 حاشیه»). Glosses or verses in the margin
     * («ابیاتی در هامش») are not counted.
     */
    public function inMargin(): bool
    {
        if (preg_match('~/\s*[\d۰-۹]+\s*حاشیه~u', (string) $this->shelfmark)) {
            return true;
        }
        foreach (preg_split('/\s*[؛،\n]\s*/u', (string) $this->fankhaText()) as $clause) {
            if (preg_match('/^(?:نوشته\s*شده\s+)?در\s+(?:هامش|حاشیه)(?:\s+(?:نوشته|نگاشته)\s*شده(?:\s+است)?)?\.?$/u', trim($clause))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The treatises bound in the same volume, this copy among them, in the order of their leaves (backlog item 97):
     * a copy whose range the catalogue gives takes its place by its first leaf, and one without a range keeps the
     * place its part number gives it. Empty when the shelfmark has no part. A copy fahares:fill-collection-keys has
     * not keyed yet finds its volume by the shelfmark's number.
     */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(ManuscriptCollection::class, 'collection_id');
    }

    /** @var \Illuminate\Support\Collection<int, self>|null the treatises of the copy's volume, found once */
    private ?\Illuminate\Support\Collection $siblings = null;

    /** The treatises of the copy's volume, itself among them, in the order of their leaves; found once for the model. */
    public function collectionSiblings(): \Illuminate\Support\Collection
    {
        return $this->siblings ??= $this->findCollectionSiblings();
    }

    /**
     * The «مجموعه» the copy reads from (backlog item 163, step 14.324): treatises bound together, two of them or
     * more. Null for a copy in no collection, alone in its volume, or one of a work's volumes under one number.
     */
    public function boundCollection(): ?ManuscriptCollection
    {
        $collection = $this->collection_id ? $this->collection : null;

        return $collection && $collection->kind === ManuscriptCollection::BOUND && $collection->copies_count >= 2 ? $collection : null;
    }

    /**
     * Whether the copy's own entry says something of a field of CollectionValues: a value, or for the scribe that
     * the hand is the author's. «بی‌کا» is no statement: the catalogue found no scribe named in this treatise, as in
     * nine of ten treatises that read their volume's scribe (step 14.324).
     */
    public function states(string $field): bool
    {
        return \App\Support\CollectionValues::compared($field, $this) !== null || ($field === 'scribe' && $this->is_autograph);
    }

    /**
     * Whether the copy reads a field from its collection: its hand, lines or scribe, where its own entry is silent
     * and its volume's treatises agree. Nothing is written in the copy's columns; the value is the collection's.
     */
    public function inheritsFromCollection(string $field): bool
    {
        return in_array($field, \App\Support\CollectionValues::INHERITED, true) && ! $this->states($field)
            && isset(($this->boundCollection()?->basis ?? [])[$field]);
    }

    /** The other treatises of the volume that state a field (a date, a place…), in the order of their leaves. */
    public function siblingsStating(string $field): \Illuminate\Support\Collection
    {
        return $this->collectionSiblings()->reject(fn (self $m) => $m->is($this))->filter(fn (self $m) => \App\Support\CollectionValues::compared($field, $m) !== null)->values();
    }

    /**
     * The seals, ownership notes, endowments and acquisitions the entries of the volume's other treatises give and
     * this copy's does not: they are the volume's history, whichever treatise's entry tells them. One row for a text,
     * with the treatises whose entries have it. Read when shown; no note is moved.
     *
     * @return \Illuminate\Support\Collection<int, array{note: ManuscriptNote, copies: \Illuminate\Support\Collection<int, self>}>
     */
    public function collectionNotes(): \Illuminate\Support\Collection
    {
        if (! $this->boundCollection()) {
            return collect();
        }
        $siblings = $this->collectionSiblings()->reject(fn (self $m) => $m->is($this))->keyBy('id');
        $fold = fn (ManuscriptNote $note) => $note->type . '|' . \App\Support\CollectionValues::folded($note->text);
        $own = $this->notes->whereIn('type', ManuscriptNote::GROUPS['provenance']['types'])->map($fold)->flip();

        return ManuscriptNote::whereIn('manuscript_id', $siblings->keys())->whereIn('type', ManuscriptNote::GROUPS['provenance']['types'])
            ->orderBy('manuscript_id')->orderBy('position')->get()
            ->reject(fn (ManuscriptNote $note) => $own->has($fold($note)))
            ->groupBy($fold)
            ->map(fn ($notes) => ['note' => $notes->first(), 'copies' => $notes->pluck('manuscript_id')->unique()->map(fn ($id) => $siblings[$id])->values()])
            ->values();
    }

    private function findCollectionSiblings(): \Illuminate\Support\Collection
    {
        $key = $this->collection_key ?? self::collectionKeyOf($this->shelfmark, $this->fankhaText());
        // the copies of its «مجموعه» record (step 14.322); a copy with none yet, or of no library, is found by its key
        $linked = $this->collection_id !== null;
        if ($key === null && ! $linked) {
            return collect();
        }
        $base = self::collectionKey($this->shelfmark)['base'] ?? null;
        $byPart = self::query()
            ->when($linked, fn ($q) => $q->where('collection_id', $this->collection_id), fn ($q) => $q
                ->when($this->library_id, fn ($q) => $q->where('library_id', $this->library_id), fn ($q) => $q->whereNull('library_id')->where('library', $this->library))
                ->when($this->collection_key !== null || $base === null,
                    fn ($q) => $q->where('collection_key', $key),
                    fn ($q) => $q->where('shelfmark_key', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $base) . '/%')))
            ->with(['work.author', 'scribe', 'fankhaEntry'])
            ->limit(400)
            ->get()
            ->filter(fn (self $m) => $linked || ($m->collection_key ?? self::collectionKeyOf($m->shelfmark, $m->fankhaText())) === $key)
            // a run of separately numbered treatises («38131–38137») is in the order of its numbers; in a volume
            // whose copies share one plain number, the copies with no range follow the ranged ones
            ->sortBy(fn (self $m) => [(int) (self::collectionKey($m->shelfmark)['part'] ?? (self::isRunKey($key) ? self::plainNumber($m->shelfmark) : 0)),
                $m->range_from_number === null ? 1 : 0, $m->id])
            ->values();

        return self::inLeafOrder($byPart);
    }

    /** Copies in part order put in leaf order: the ranged ones are sorted by their first and last leaf in the places they hold, the others stay. */
    public static function inLeafOrder(\Illuminate\Support\Collection $byPart): \Illuminate\Support\Collection
    {
        $slots = $byPart->keys()->filter(fn ($i) => $byPart[$i]->range_from_number !== null)->values();
        $ranged = $slots->map(fn ($i) => $byPart[$i])->sortBy(fn (self $m) => [(int) $m->range_from_number, (int) $m->range_to_number])->values();
        $ordered = $byPart->all();
        foreach ($slots as $n => $i) {
            $ordered[$i] = $ranged[$n];
        }

        return collect($ordered);
    }

    /** The copy's pointers at its work's incipits or explicits («برابر 2», «برابر جلد دوم»; backlog item 98). */
    public function excerpts(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(TextExcerpt::class, 'owner');
    }

    /** People other than the scribes: owners, endowers, correctors… in role order. */
    public function otherPeople(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ManuscriptPerson::class)->where('role', '!=', ManuscriptPerson::SCRIBE)->orderBy('position')->orderBy('id');
    }

    public function scripts(): BelongsToMany
    {
        return $this->belongsToMany(Script::class)->withPivot('is_primary');
    }

    public function fieldSuggestions(): MorphMany
    {
        return $this->morphMany(FieldSuggestion::class, 'suggestable');
    }

    public function scholarlyAnnotations(): MorphMany
    {
        return $this->morphMany(ScholarlyAnnotation::class, 'annotatable');
    }
}
