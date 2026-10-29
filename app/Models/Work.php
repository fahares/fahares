<?php

namespace App\Models;

use App\Models\Concerns\HasSourceCitations;
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
    use HasSourceCitations;
    use HasSourceEntries;
    use Searchable;
    use SyncsPrimaryContributor;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // Keep the sortable year in step with the date text unless the caller set both
        static::saving(function (Work $work) {
            if ($work->isDirty('composition_date_raw') && ! $work->isDirty('composition_year_hijri')) {
                $work->composition_year_hijri = HijriYear::parse($work->composition_date_raw);
            }
        });
    }

    protected static function primaryContributorPivot(): array
    {
        return ['work_author', 'work_id', 'author_id'];
    }

    protected function casts(): array
    {
        return [
            'alternative_titles' => 'array',
            'bibliography' => 'array',
            'commentaries_and_glosses' => 'array',
            'composition_year_hijri' => 'integer',
            'volume_number' => 'integer',
            'page_start' => 'integer',
            'page_end' => 'integer',
            'manuscripts_count' => 'integer',
            'has_other_authors' => 'boolean',
        ];
    }

    public function getTitleAttribute(): string
    {
        return $this->primary_title ?? '';
    }

    public function getCleanRawTextAttribute(): ?string
    {
        if (empty($this->raw_text)) {
            return null;
        }
        $cleaned = preg_replace('/<!--\s*page:\s*\d+\s*-->/u', '', $this->raw_text);
        $cleaned = str_replace(["\r\n", "\r"], "\n", $cleaned);
        $cleaned = preg_replace('/^[ \t]+$/m', '', $cleaned);
        $cleaned = preg_replace('/[ \t]{2,}/u', ' ', $cleaned);
        $cleaned = preg_replace("/\n{3,}/u", "\n\n", $cleaned);
        return trim($cleaned);
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

    public function makeAllSearchableUsing($query)
    {
        return $query->with(['subjects:id,name', 'languages:id,name', 'author:id,name,aliases,death_year_hijri', 'authors:people.id', 'manuscripts:id,work_id,incipit_text,explicit_text,is_autograph', 'baseWorks:works.id,primary_title,clean_title',
            'sourceEntries' => fn ($q) => $q->select('id', 'citable_type', 'citable_id', 'source_id', 'status')]);
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
     * Titles of the works this one is made on (backlog item 95): from the relations in work_relations when there
     * are any, else read from its own titles («شرح X» is made on X) and its related work recorded as text.
     *
     * @return list<string> as written
     */
    public function baseTitles(): array
    {
        $bases = $this->relationLoaded('baseWorks') ? $this->baseWorks : $this->baseWorks()->get(['works.id', 'primary_title', 'clean_title']);
        if ($bases->isNotEmpty()) {
            $titles = $bases->flatMap(fn (Work $w) => [$w->primary_title, $w->clean_title])->all();
        } else {
            $titles = [];
            foreach ($this->searchTitles()[1] as $title) {
                $titles[] = \App\Support\WorkRelationParser::parseTitle($title)['base'] ?? null;
            }
            foreach (\App\Support\WorkRelationParser::parseEntries($this->related_work) as $entry) {
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

    public function toSearchableArray(): array
    {
        $alts = [];
        if (!empty($this->alternative_titles)) {
            foreach ($this->alternative_titles as $item) {
                $title = is_array($item) ? ($item['title'] ?? null) : $item;
                if (is_string($title) && $title !== '') {
                    array_push($alts, ...\App\Helpers\TextNormalizer::titleForms($title));
                }
            }
        }

        $authorAliases = [];
        if ($this->relationLoaded('author') && $this->author) {
            $authorAliases = (array) ($this->author->aliases ?? []);
        } elseif (!empty($this->author_id)) {
            $authorAliases = (array) ($this->author?->aliases ?? []);
        }

        $authorSearchText = $this->author_name;
        if (!empty($authorAliases)) {
            $authorSearchText = trim($authorSearchText . ' ' . implode(' ', $authorAliases));
        }

        $cleanTitle = $this->clean_title ?: $this->primary_title;
        // Each title in its own words, then its forms without the article and with «ت» (backlog item 93)
        $titles = \App\Helpers\TextNormalizer::titleForms($this->primary_title);
        $cleanTitles = \App\Helpers\TextNormalizer::titleForms($cleanTitle);
        $forms = fn (array $list) => array_merge([], ...array_map(fn ($t) => \App\Helpers\TextNormalizer::titleForms($t), $list));
        [$namingTitles, $derivativeTitles] = $this->searchTitles();
        $authorNames = array_map(fn ($a) => \App\Helpers\TextNormalizer::normalize($a), array_filter([$this->author_name, ...$authorAliases]));
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
            'author_aliases' => array_map(fn($a) => \App\Helpers\TextNormalizer::normalize($a), $authorAliases),
            'author_id' => $this->author_id ? (int) $this->author_id : null,
            'composition_year_hijri' => $this->composition_year_hijri ? (int) $this->composition_year_hijri : null,
            'volume_number' => (int) $this->volume_number,
            'page_start' => (int) $this->page_start,
            'page_end' => $this->page_end ? (int) $this->page_end : null,
            'work_form' => $this->work_form,
            'subject_summary' => $this->subject_summary,
            'language_summary' => $this->language_summary,
            'manuscripts_count' => (int) $this->manuscripts_count,
            // Search filters and orders of backlog item 83, phase 5: the work's era is its composition, or else its
            // author's death, which only 14% of works lack against 86% without a composition year
            'author_death_year' => $this->author?->death_year_hijri ? (int) $this->author->death_year_hijri : null,
            'era_year' => ($era = $this->composition_year_hijri ?: $this->author?->death_year_hijri) ? (int) $era : null,
            'has_autograph' => $this->manuscripts->contains('is_autograph', true),
            'bibliographies' => $this->bibliographyIds(),
            // Every author, not only the first: «the works of this person» (backlog item 83, phase 6)
            'author_ids' => $this->authors->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'incipit_text' => \App\Helpers\TextNormalizer::normalize($this->incipit_text),
            'explicit_text' => \App\Helpers\TextNormalizer::normalize($this->explicit_text),
            'description' => $this->description ? \App\Helpers\TextNormalizer::normalize(mb_substr($this->description, 0, 500)) : null,
            'manuscript_texts' => $this->manuscriptSearchTexts(),
            'subjects' => $this->relationLoaded('subjects') ? $this->subjects->pluck('name')->all() : [],
            'languages' => $this->relationLoaded('languages') ? $this->languages->pluck('name')->all() : [],
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
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
        return $this->belongsToMany(Person::class, 'work_author')
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
        return $this->hasMany(WorkAuthor::class)->orderBy('position')->orderBy('id');
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
            ->orderBy('sequence_number')
            ->first();
    }

    private function plausibleCopyYearFloor(): int
    {
        $floor = 300;
        if ($this->composition_year_hijri) {
            $floor = max($floor, $this->composition_year_hijri - 40);
        } elseif ($this->author?->death_year_hijri) {
            $floor = max($floor, $this->author->death_year_hijri - 90);
        } elseif ($this->author?->century_hijri) {
            $floor = max($floor, ($this->author->century_hijri - 2) * 100);
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
