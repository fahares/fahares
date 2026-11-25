<?php

namespace App\Models;

use App\Models\Concerns\HasSourceEntries;
use App\Models\Concerns\SyncsPrimaryContributor;
use App\Support\HijriYear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Scout\Searchable;

class Work extends Model
{
    use HasSourceEntries;
    use Searchable;
    use SyncsPrimaryContributor;

    protected $guarded = ['id'];

    /** What the work reads of its whole, kept for the life of the instance (whole, wholes, traitSource) */
    private array $partsMemo = [];

    protected static function booted(): void
    {
        // Keep the sortable year in step with the date text unless the caller set both
        static::saving(function (Work $work) {
            if ($work->isDirty('composition_date_raw') && ! $work->isDirty('composition_year_hijri')) {
                $work->composition_year_hijri = HijriYear::parse($work->composition_date_raw);
            }
            // ...and its range, certainty and hedge word, as for a copy's date (backlog item 96)
            if ($work->isDirty('composition_date_raw')) {
                $work->forceFill(self::compositionDateColumns($work->composition_date_raw));
            }
            // The city the place of composition names, beside its text (item 139, step 14.209)
            if ($work->isDirty('composition_place')) {
                $work->composition_city = \App\Support\CopyPlaces::cityOf($work->composition_place);
                $work->composition_region = \App\Support\CopyPlaces::regionOf($work->composition_place);
            }
        });
        // The parts that read their author, subject or language from this work are searched by them (step 14.308)
        static::saved(function (Work $work) {
            if ($work->wasChanged(['author_id', 'author_name', 'composition_date_raw', 'composition_year_hijri'])) {
                \App\Support\WorkParts::flushDates();
            }
            if ($work->wasChanged(['author_id', 'author_name', 'has_other_authors', 'subject_summary', 'language_summary', 'composition_date_raw', 'composition_year_hijri'])) {
                \App\Support\WorkParts::reindexBelow($work->id);
            }
        });
    }

    /** The range columns of a date of composition (CopyDateRange), as they are stored. */
    public static function compositionDateColumns(?string $raw): array
    {
        $range = \App\Support\CopyDateRange::parse($raw);

        return ['composition_date_from' => $range['from'], 'composition_date_to' => $range['to'], 'composition_date_certainty' => $range['certainty'],
            'composition_date_hedge_raw' => $range['hedge'] ? mb_substr($range['hedge'], 0, 60) : null, 'composition_date_kind' => $range['kind']];
    }

    /**
     * The catalogue's word on a work's entry as a whole (step 14.7), its label and its explanation: a description
     * that is only one of these labels («غیر همانند:») moves here (fahares:move-entry-status).
     */
    public const ENTRY_STATUSES = [
        'heterogeneous' => ['غیر همانند', 'نسخه‌های این مدخل متن‌هایی جدا با عنوانی یکسان‌اند'],
        'probably_heterogeneous' => ['ظاهراً غیر همانند', 'نسخه‌های این مدخل ظاهراً متن‌هایی جدا با عنوانی یکسان‌اند'],
        'unidentified' => ['ناشناخته', 'اثر در فهرست شناسایی نشده است'],
        'similarity_unknown' => ['همانندی نامعلوم', 'معلوم نیست نسخه‌های این مدخل یک متن‌اند'],
    ];

    protected static function primaryContributorPivot(): array
    {
        return ['work_people', 'work_id', 'author_id', WorkPerson::AUTHOR];
    }

    protected function casts(): array
    {
        return [
            'alternative_titles' => 'array',
            'composition_year_hijri' => 'integer',
            'composition_date_from' => 'integer',
            'composition_date_to' => 'integer',
            'manuscripts_count' => 'integer',
            'has_other_authors' => 'boolean',
        ];
    }

    public function getTitleAttribute(): string
    {
        return $this->primary_title ?? '';
    }

    public function getPersianSlugAttribute(): string
    {
        $title = $this->clean_title ?: $this->primary_title;
        $slug = \Illuminate\Support\Str::slug($title, '-', null);
        if (mb_strlen($slug) > 75) {
            $slug = mb_substr($slug, 0, 75);
            $lastHyphen = mb_strrpos($slug, '-');
            if ($lastHyphen > 30) {
                $slug = mb_substr($slug, 0, $lastHyphen);
            }
        }
        return $slug ?: 'work';
    }

    public function getRouteKey(): string
    {
        $slug = $this->persian_slug;
        return $slug ? "{$this->id}-{$slug}" : (string) $this->id;
    }

    public function searchableAs(): string
    {
        return 'works_index';
    }

    /**
     * The work's estimated years of composition, [from, to], where fahares:estimate-dates placed it within
     * EstimateText::MAX_SPAN years; null for a dated work or one with no such estimate. An import reads it with the
     * works (makeAllSearchableUsing), a single work by a query of its own.
     */
    public function compositionEstimate(): ?array
    {
        // ...nor for a part that reads its date from the work it is a part of (step 14.315)
        if ($this->effective_composition_year) {
            return null;
        }
        if (! array_key_exists('estimate_from', $this->attributes)) {
            $row = \App\Support\DateEstimates\EstimateText::placedCompositions(\Illuminate\Support\Facades\DB::query())
                ->where('date_estimates.subject_id', $this->id)->first(['year_from', 'year_to']);
            $this->attributes['estimate_from'] = $row->year_from ?? null;
            $this->attributes['estimate_to'] = $row->year_to ?? null;
            // not columns of the table: kept out of what a save would write
            $this->syncOriginalAttributes(['estimate_from', 'estimate_to']);
        }

        return $this->attributes['estimate_from'] === null ? null : [(int) $this->attributes['estimate_from'], (int) $this->attributes['estimate_to']];
    }

    /** A batch given to the engine reads its Fankha entries in one query (step 14.399, backlog item 170). */
    public function makeSearchableUsing(\Illuminate\Support\Collection $models): \Illuminate\Support\Collection
    {
        return $models instanceof \Illuminate\Database\Eloquent\Collection ? $models->loadMissing('fankhaEntry') : $models;
    }

    public function makeAllSearchableUsing($query)
    {
        $estimate = fn (string $column) => \App\Support\DateEstimates\EstimateText::placedCompositions(\Illuminate\Support\Facades\DB::query())
            ->whereColumn('date_estimates.subject_id', 'works.id')->select("date_estimates.{$column}");

        return $query->select('works.*')->selectSub($estimate('year_from'), 'estimate_from')->selectSub($estimate('year_to'), 'estimate_to')->with(['subjects:id,name', 'languages:id,name', 'author:id,name,aliases,wrong_names,death_year_hijri', 'authors:people.id', 'manuscripts:id,work_id,incipit_text,explicit_text,is_autograph', 'baseWorks:works.id,primary_title,clean_title', 'workPeople.person:id,name,aliases',
            'sourceEntries' => fn ($q) => $q->select('id', 'citable_type', 'citable_id', 'source_id', 'status'),
            'fankhaEntry' => fn ($q) => $q->select('id', 'citable_type', 'citable_id', 'legacy_type', 'volume_number', 'page_start', 'page_end')]);
    }

    /**
     * The work's own titles as the search splits them (backlog item 95): those that name it, and those that name it
     * by the work it is made on («شرح نهج البلاغة», «الروضة البهیة فی شرح اللمعة»), indexed whole after them with
     * its base titles: the engine ranks each query word by its field, so a head in the first field would put
     * «اللمعة الماردینیة فی شرح الیاسمینیة» before al-Rawda for «شرح اللمعه». The relation is read by
     * WorkRelationParser, shared with the linking of backlog item 6.
     *
     * @return array{0: list<string>, 1: list<string>} naming titles and derivative titles, as written
     */
    public function searchTitles(): array
    {
        $titles = [$this->primary_title, $this->clean_title];
        foreach ((array) $this->alternative_titles as $item) {
            $titles[] = is_array($item) ? ($item['title'] ?? null) : $item;
        }

        $naming = $derivative = [];
        foreach (array_filter($titles, fn ($t) => is_string($t) && trim($t) !== '') as $title) {
            $relation = \App\Support\WorkRelationParser::parseTitle($title);
            if ($relation) {
                $derivative[] = $title;
            } else {
                $naming[] = $title;
            }
        }

        // A title that is only the base's, «نهج البلاغة» as the clean title of «نهج البلاغة (ترجمه)», does not name it
        $bases = array_map(fn ($t) => \App\Helpers\TextNormalizer::normalize(\App\Support\WorkRelationParser::parseTitle($t)['base']), $derivative);
        $naming = array_filter($naming, fn ($t) => ! in_array(\App\Helpers\TextNormalizer::normalize($t), $bases, true));

        return [array_values($naming), $derivative];
    }

    /**
     * Titles of the works this one is made on (backlog item 95): from the relations in work_relations, and read from
     * its own titles («شرح X» is made on X) and its related work recorded as text. Both since step 14.104: a work
     * related to the base text of its base («حاشية الروضة البهية» to «اللمعة») keeps «الروضة البهية» of its title.
     *
     * @return list<string> as written
     */
    public function baseTitles(): array
    {
        $bases = $this->relationLoaded('baseWorks') ? $this->baseWorks : $this->baseWorks()->get(['works.id', 'primary_title', 'clean_title']);
        $titles = $bases->flatMap(fn (Work $w) => [$w->primary_title, $w->clean_title])->all();
        foreach ($this->searchTitles()[1] as $title) {
            $titles[] = \App\Support\WorkRelationParser::parseTitle($title)['base'] ?? null;
        }
        // what the catalogue says the work is made on: its rows of «وابسته به» (WorkCatalogueEntries, step 14.285)
        $related = \Illuminate\Support\Facades\DB::table('work_text_relations')->where('work_id', $this->id)->where('source', \App\Support\WorkCatalogueEntries::SOURCE)
            ->where('side', 'related')->whereNotIn('type', \App\Support\WorkCatalogueEntries::NOT_ENTRIES)->orderBy('position')->orderBy('id')->pluck('text');
        foreach ($related as $text) {
            foreach (\App\Support\WorkRelationParser::parseEntries($text) as $entry) {
                array_push($titles, ...$entry['titles']);
            }
        }

        // One of each, compared normalized
        $unique = [];
        foreach (array_filter($titles, fn ($t) => is_string($t) && $t !== '') as $title) {
            $unique[\App\Helpers\TextNormalizer::normalize($title)] ??= $title;
        }

        return array_values($unique);
    }

    /**
     * Epithets naming the author by another work, «شارح نهج‌البلاغه» or «صاحب جواهر», normalized: kept out of
     * titles_names, so the author's works do not rank with that work's title (backlog item 95).
     */
    public const AUTHOR_EPITHETS = ['شارح', 'صاحب', 'مصنف', 'مولف', 'محشی', 'مترجم', 'ناظم'];

    /** The bibliographies and biographical works that mention this work, for the search filter «یادشده در» */
    public function bibliographyIds(): array
    {
        $kinds = \App\Support\SearchFilters::SOURCE_KINDS;
        $sourceIds = \Illuminate\Support\Facades\Cache::remember('search.bibliography-source-ids', 3600,
            fn () => \App\Models\Source::whereIn('kind', $kinds)->pluck('id')->all());

        return $this->sourceEntries
            ->filter(fn ($e) => in_array($e->source_id, $sourceIds, true) && $e->status !== SourceEntry::STATUS_REJECTED)
            ->pluck('source_id')->unique()->values()->map(fn ($id) => (int) $id)->all();
    }

    /** Caps on the manuscript incipits/explicits folded into one work's search document. */
    public const SEARCH_MANUSCRIPT_TEXTS_MAX = 50;
    public const SEARCH_MANUSCRIPT_TEXTS_MAX_CHARS = 20000;

    /**
     * Distinct incipits and explicits of this work's manuscripts, normalized, for the works index.
     * Drops «برابر …» cross-references and texts equal to the work's own; most frequent first, then capped.
     */
    public function manuscriptSearchTexts(): array
    {
        $manuscripts = $this->relationLoaded('manuscripts')
            ? $this->manuscripts
            : $this->manuscripts()->get(['id', 'work_id', 'incipit_text', 'explicit_text']);

        $own = array_filter([
            \App\Helpers\TextNormalizer::normalize($this->incipit_text),
            \App\Helpers\TextNormalizer::normalize($this->explicit_text),
        ]);

        $counts = [];
        foreach ($manuscripts as $ms) {
            foreach ([$ms->incipit_text, $ms->explicit_text] as $text) {
                $text = \App\Helpers\TextNormalizer::normalize($text);
                if ($text === '' || str_starts_with($text, 'برابر') || in_array($text, $own, true)) {
                    continue;
                }
                $counts[$text] = ($counts[$text] ?? 0) + 1;
            }
        }
        arsort($counts);

        $texts = [];
        $chars = 0;
        foreach (array_keys($counts) as $text) {
            $len = mb_strlen($text);
            if (count($texts) >= self::SEARCH_MANUSCRIPT_TEXTS_MAX || $chars + $len > self::SEARCH_MANUSCRIPT_TEXTS_MAX_CHARS) {
                break;
            }
            $texts[] = $text;
            $chars += $len;
        }

        return $texts;
    }

    /** The names of the people connected with the work in other roles: a linked person's name and other names, or the text. */
    public function relatedPeopleNames(): array
    {
        $names = [];
        foreach ($this->workPeople as $row) {
            array_push($names, ...array_filter([$row->person?->name ?? $row->name, ...(array) ($row->person?->aliases ?? [])], 'is_string'));
        }

        return array_values(array_unique(array_map(fn ($n) => \App\Helpers\TextNormalizer::normalize($n), $names)));
    }

    public function toSearchableArray(): array
    {
        $fankha = $this->fankhaEntry;
        $alts = [];
        if (!empty($this->alternative_titles)) {
            foreach ($this->alternative_titles as $item) {
                $title = is_array($item) ? ($item['title'] ?? null) : $item;
                if (is_string($title) && $title !== '') {
                    array_push($alts, ...\App\Helpers\TextNormalizer::titleForms($title));
                }
            }
        }

        // A part with no author, subject or language of its own is found by those of the work it is a part of (step 14.308)
        $from = $this->traitSource('author');
        $subjectFrom = $this->traitSource('subject');
        $languageFrom = $this->traitSource('language');
        $author = $from->author_id ? $from->author : null;
        $authorAliases = (array) ($author?->aliases ?? []);
        $authorWrongNames = (array) ($author?->wrong_names ?? []);

        // The linked person's name as it is now, beside the name the record carries: a person renamed after the
        // work was made is found by either (step 14.295)
        $authorNow = $author?->name;
        $authorSearchText = trim($from->author_name . ($authorNow && $authorNow !== $from->author_name ? ' ' . $authorNow : ''));
        if (!empty($authorAliases)) {
            $authorSearchText = trim($authorSearchText . ' ' . implode(' ', $authorAliases));
        }

        $cleanTitle = $this->clean_title ?: $this->primary_title;
        // Each title in its own words, then its forms without the article and with «ت» (backlog item 93)
        $titles = \App\Helpers\TextNormalizer::titleForms($this->primary_title);
        $cleanTitles = \App\Helpers\TextNormalizer::titleForms($cleanTitle);
        $forms = fn (array $list) => array_merge([], ...array_map(fn ($t) => \App\Helpers\TextNormalizer::titleForms($t), $list));
        [$namingTitles, $derivativeTitles] = $this->searchTitles();
        $authorNames = array_map(fn ($a) => \App\Helpers\TextNormalizer::normalize($a), array_filter([$from->author_name, $authorNow, ...$authorAliases]));
        $authorNames = array_filter($authorNames, fn ($a) => ! in_array(strtok($a, ' '), self::AUTHOR_EPITHETS, true));

        return [
            'id' => (int) $this->id,
            // The titles and the author together, the first field searched: a match in any of them ranks the same,
            // and before one in the incipits or the description (backlog item 93). Without the titles naming the work
            // by another one or the epithets naming the author by a work (backlog item 95)
            'titles_names' => array_values(array_unique([...$forms($namingTitles), ...$authorNames])),
            // The titles of the works this one is made on, and its own titles naming it by them: after its own
            // titles, so «نهج البلاغه» is Nahj al-Balagha, then its commentaries by their manuscripts (backlog item 95)
            'base_titles' => array_values(array_unique($forms([...$derivativeTitles, ...$this->baseTitles()]))),
            'primary_title' => $titles,
            'clean_title' => $cleanTitles,
            'slug' => $this->slug,
            'transliteration' => $this->transliteration,
            'alternative_titles' => $alts,
            'author_name' => \App\Helpers\TextNormalizer::normalize($authorSearchText),
            // A misspelt form of the author's name finds the work too, without ranking it like a name (backlog item 90)
            'author_aliases' => array_map(fn($a) => \App\Helpers\TextNormalizer::normalize($a), [...$authorAliases, ...$authorWrongNames]),
            // Translators, dedicatees, commissioners… (work_people): «ناصرالدین شاه» finds the works dedicated to
            // him, after the works whose title or author matches (backlog item 35)
            'related_people' => $this->relatedPeopleNames(),
            'related_person_ids' => $this->workPeople->pluck('person_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'author_id' => $from->author_id ? (int) $from->author_id : null,
            // a part's year is its own, or that of the whole it reads its date from (step 14.315)
            'composition_year_hijri' => ($composed = $this->effective_composition_year) ? (int) $composed : null,
            // the document keeps these keys; the values are the Fankha entry's (step 14.399, backlog item 170)
            'volume_number' => (int) $fankha?->volume_number,
            'page_start' => (int) $fankha?->page_start,
            'page_end' => $fankha?->page_end ? (int) $fankha->page_end : null,
            'work_form' => $this->work_form,
            'subject_summary' => $subjectFrom->subject_summary,
            'language_summary' => $languageFrom->language_summary,
            'manuscripts_count' => (int) $this->manuscripts_count,
            // Search filters and orders of backlog item 83, phase 5: the work's era is its composition, or else its
            // author's death, which only 14% of works lack against 86% without a composition year
            // an author known by the century alone counts by its middle (Person::eraYear, step 14.213)
            'author_death_year' => $author?->eraYear(),
            'era_year' => ($era = $composed ?: $author?->eraYear()) ? (int) $era : null,
            // A work with no era may be found by its estimated composition, when the reader asks for estimates
            // (step 14.197); and the order by composition may fall back on the middle of the estimate
            'era_estimate_from' => $era ? null : ($this->compositionEstimate()[0] ?? null),
            'era_estimate_to' => $era ? null : ($this->compositionEstimate()[1] ?? null),
            'composition_sort_year' => $composed ? (int) $composed
                : (($estimate = $this->compositionEstimate()) ? intdiv($estimate[0] + $estimate[1], 2) : null),
            'has_autograph' => $this->manuscripts->contains('is_autograph', true),
            'bibliographies' => $this->bibliographyIds(),
            // Every author, not only the first: «the works of this person» (backlog item 83, phase 6)
            'author_ids' => $from->authors->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'incipit_text' => \App\Helpers\TextNormalizer::normalize($this->incipit_text),
            'explicit_text' => \App\Helpers\TextNormalizer::normalize($this->explicit_text),
            'description' => $this->description ? \App\Helpers\TextNormalizer::normalize(mb_substr($this->description, 0, 500)) : null,
            'manuscript_texts' => $this->manuscriptSearchTexts(),
            'subjects' => $subjectFrom->is($this) ? ($this->relationLoaded('subjects') ? $this->subjects->pluck('name')->all() : []) : $subjectFrom->subjects->pluck('name')->all(),
            'languages' => $languageFrom->is($this) ? ($this->relationLoaded('languages') ? $this->languages->pluck('name')->all() : []) : $languageFrom->languages->pluck('name')->all(),
        ];
    }

    /**
     * The author as a page names them (step 14.295): the linked person's name as it is now, since the person may have
     * been renamed after the work was made; the record's own text for a work of several authors («الف و ب») or of no
     * linked person.
     */
    public function getAuthorLabelAttribute(): ?string
    {
        return $this->traitSource('author')->own_author_label;
    }

    /** The author this record itself names, without what a part reads from its whole */
    public function getOwnAuthorLabelAttribute(): ?string
    {
        return $this->author_id && ! $this->has_other_authors ? ($this->author?->name ?? $this->author_name) : $this->author_name;
    }

    /** The linked author a page shows: the work's own, or for a part with no author that of its whole (step 14.308) */
    public function getEffectiveAuthorAttribute(): ?Person
    {
        $from = $this->traitSource('author');

        return $from->author_id ? $from->author : null;
    }

    /** The author's name as the record carries it, the work's own or its whole's */
    public function getEffectiveAuthorNameAttribute(): ?string
    {
        return $this->traitSource('author')->author_name;
    }

    /**
     * The one work this work is a part of (work_relations of type «part», step 14.308); null for a work that is a
     * part of none, or of more than one: such a work reads nothing from either.
     */
    public function whole(): ?Work
    {
        if (! array_key_exists('whole', $this->partsMemo)) {
            $id = \App\Support\WorkParts::wholeId((int) $this->id);
            $this->partsMemo['whole'] = $id ? self::find($id) : null;
        }

        return $this->partsMemo['whole'];
    }

    /** @return \Illuminate\Support\Collection<int, Work> the wholes above the work, the nearest first */
    public function wholes(): \Illuminate\Support\Collection
    {
        if (! array_key_exists('wholes', $this->partsMemo)) {
            $ids = \App\Support\WorkParts::chainIds((int) $this->id);
            $found = $ids ? self::whereIn('id', $ids)->get()->keyBy('id') : collect();
            $this->partsMemo['wholes'] = collect($ids)->map(fn ($id) => $found->get($id))->filter()->values();
        }

        return $this->partsMemo['wholes'];
    }

    /** Has the record this trait itself? An author: a person or a name; a subject or a language: its text or a link */
    public function hasOwn(string $trait): bool
    {
        return match ($trait) {
            'author' => (bool) $this->author_id || trim((string) $this->author_name) !== '',
            'subject' => trim((string) $this->subject_summary) !== '' || ($this->relationLoaded('subjects') ? $this->subjects->isNotEmpty() : $this->subjects()->exists()),
            'language' => trim((string) $this->language_summary) !== '' || ($this->relationLoaded('languages') ? $this->languages->isNotEmpty() : $this->languages()->exists()),
            'composition' => trim((string) $this->composition_date_raw) !== '' || $this->composition_year_hijri || $this->composition_date_from !== null || $this->composition_date_to !== null,
            default => throw new \InvalidArgumentException("No such trait: {$trait}"),
        };
    }

    /**
     * The work a trait of this one is read from (WorkParts::TRAITS): the work itself when it has the trait or is no
     * part of another, else the nearest whole above it that has it. A part keeps what it has: «مرکب‌سازی» with an
     * author of its own stays that author's, whatever its whole says.
     */
    public function traitSource(string $trait): Work
    {
        if (! isset($this->partsMemo['source'][$trait])) {
            $source = $this;
            if (\App\Support\WorkParts::wholeId((int) $this->id) !== null && ! $this->hasOwn($trait)) {
                $source = $this->wholes()->first(fn (Work $whole) => $whole->hasOwn($trait)) ?? $this;
                // The date is the author's: a part by another author than the dated whole's (a later selection from
                // it) does not take that whole's date (step 14.315)
                if ($trait === 'composition' && ! $source->is($this) && ! $this->sharesAuthorWith($source)) {
                    $source = $this;
                }
            }
            $this->partsMemo['source'][$trait] = $source;
        }

        return $this->partsMemo['source'][$trait];
    }

    /** Is this work's author the other's: both read from one work, or the same linked person? */
    private function sharesAuthorWith(Work $other): bool
    {
        $mine = $this->traitSource('author');
        $theirs = $other->traitSource('author');

        return $mine->is($theirs) || ($mine->author_id && (int) $mine->author_id === (int) $theirs->author_id);
    }

    /** The year of composition the work is ordered and filtered by: its own, or that of the whole its date is read from */
    public function getEffectiveCompositionYearAttribute(): ?int
    {
        $year = $this->traitSource('composition')->composition_year_hijri;

        return $year ? (int) $year : null;
    }

    /** Is the trait read from a whole? */
    public function inherits(string $trait): bool
    {
        return ! $this->traitSource($trait)->is($this);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'author_id');
    }

    /**
     * Every author of the work in catalog order; the primary one (author_id) is at position 0.
     */
    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'work_people')
            ->withPivotValue('role', WorkPerson::AUTHOR)
            ->withPivot(['position', 'note'])
            ->orderByPivot('position');
    }

    /** The works this one is made on, by the relations in work_relations: a commentary's base text */
    public function baseWorks(): BelongsToMany
    {
        return $this->belongsToMany(Work::class, 'work_relations', 'work_id', 'related_work_id')->withPivot('type');
    }

    /**
     * Every author row in catalog order, including names without a person record.
     */
    public function authorEntries(): HasMany
    {
        return $this->hasMany(WorkPerson::class)->where('role', WorkPerson::AUTHOR)->orderBy('position')->orderBy('id');
    }

    /** People other than the authors: translators, dedicatees, commissioners… in role order. */
    public function workPeople(): HasMany
    {
        return $this->hasMany(WorkPerson::class)->where('role', '!=', WorkPerson::AUTHOR)->orderBy('position')->orderBy('id');
    }

    /** Numbered and part incipits and explicits beyond the main ones (backlog item 98), in their order. */
    public function excerpts(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(TextExcerpt::class, 'owner')->orderBy('kind')->orderBy('is_part')->orderBy('position')->orderBy('id');
    }

    public function manuscripts(): HasMany
    {
        return $this->hasMany(Manuscript::class);
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class);
    }

    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'target_work_id');
    }

    public function fieldSuggestions(): MorphMany
    {
        return $this->morphMany(FieldSuggestion::class, 'suggestable');
    }

    public function scholarlyAnnotations(): MorphMany
    {
        return $this->morphMany(ScholarlyAnnotation::class, 'annotatable');
    }

    public function getCopyCenturyTextAttribute(): ?string
    {
        if (array_key_exists('copy_century_text', $this->attributes)) {
            return $this->attributes['copy_century_text'];
        }

        $minAllowedYear = $this->plausibleCopyYearFloor();

        $range = $this->manuscripts()
            ->whereNotNull('copy_date_hijri_year')
            ->where('copy_date_hijri_year', '>=', $minAllowedYear)
            ->where('copy_date_hijri_year', '<=', 1500)
            ->selectRaw('MIN(copy_date_hijri_year) as min_year, MAX(copy_date_hijri_year) as max_year')
            ->first();

        if ($range && $range->min_year && $range->max_year) {
            $minCentury = (int) ceil($range->min_year / 100);
            $maxCentury = (int) ceil($range->max_year / 100);
            return $minCentury === $maxCentury
                ? "قرن {$minCentury} هـ.ق"
                : "از قرن {$minCentury} تا {$maxCentury} هـ.ق";
        }

        return null;
    }

    /**
     * The earliest copy with a plausible copy date: years far before the work was written
     * (or its author lived) are parsing errors and are skipped.
     */
    public function earliestDatedManuscript(): ?Manuscript
    {
        return $this->manuscripts()
            ->with('libraryRecord')
            ->whereNotNull('copy_date_hijri_year')
            ->whereBetween('copy_date_hijri_year', [$this->plausibleCopyYearFloor(), 1500])
            ->orderBy('copy_date_hijri_year')
            ->orderByFankhaColumn('entry_number')
            ->first();
    }

    private function plausibleCopyYearFloor(): int
    {
        $floor = 300;
        $author = $this->effective_author;
        if ($composed = $this->effective_composition_year) {
            $floor = max($floor, $composed - 40);
        } elseif ($author?->death_year_hijri) {
            $floor = max($floor, $author->death_year_hijri - 90);
        } elseif ($author?->century_hijri) {
            $floor = max($floor, ($author->century_hijri - 2) * 100);
        }

        return $floor;
    }

    public function getHasAutographAttribute(): bool
    {
        if (array_key_exists('has_autograph', $this->attributes)) {
            return (bool) $this->attributes['has_autograph'];
        }
        return $this->manuscripts()->where('is_autograph', true)->exists();
    }
}
