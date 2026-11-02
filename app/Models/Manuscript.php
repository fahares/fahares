<?php

namespace App\Models;

use App\Models\Concerns\HasSourceCitations;
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
    use HasSourceCitations;
    use HasSourceEntries;
    use Searchable;
    use SyncsPrimaryContributor;

    protected $guarded = ['id'];

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
            return ($pages ? 'صفحه‌ی ' : 'برگ ') . \App\Support\Fa::digits($this->range_from);
        }

        return ($pages ? 'صفحه‌های ' : 'برگ‌های ') . \App\Support\Fa::digits($this->range_from) . ' تا ' . \App\Support\Fa::digits($this->range_to);
    }

    /**
     * The range columns of a copy date (CopyDateRange), as they are stored; a date that names the author's time
     * («نزدیک عصر مؤلف») takes its range from the death year of the work's author, when it is given.
     */
    public static function copyDateColumns(?string $raw, ?int $authorDeath = null): array
    {
        $range = CopyDateRange::relative($raw, $authorDeath) ?? CopyDateRange::parse($raw);

        return ['copy_date_from' => $range['from'], 'copy_date_to' => $range['to'], 'copy_date_certainty' => $range['certainty'],
            'copy_date_hedge_raw' => $range['hedge'] ? mb_substr($range['hedge'], 0, 60) : null];
    }

    /**
     * The death year of each work's author (its Hijri death year, else an end of its death range), for the copy
     * dates that name the author's time.
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
            ->selectRaw('works.id AS id, COALESCE(people.death_year_hijri, people.death_to, people.death_from) AS year')
            ->pluck('year', 'id')->filter()->map(fn ($y) => (int) $y)->all();
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
                $manuscript->forceFill(self::copyDateColumns($manuscript->copy_date_raw, $death));
            }
            // The sort/collection key is the shelfmark cut to the indexed length
            if ($manuscript->isDirty('shelfmark') && ! $manuscript->isDirty('shelfmark_key')) {
                $manuscript->shelfmark_key = $manuscript->shelfmark === null ? null : mb_substr($manuscript->shelfmark, 0, 100);
            }
            // ...and so is the bound volume it names (backlog item 97), which the catalogue it cites can change
            if ($manuscript->isDirty(['shelfmark', 'raw_text']) && ! $manuscript->isDirty('collection_key')) {
                $manuscript->collection_key = self::collectionKeyOf($manuscript->shelfmark, $manuscript->raw_text);
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
            'sequence_number' => 'integer',
            'volume_number' => 'integer',
            'page_start' => 'integer',
            'page_end' => 'integer',
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
            'is_corrected' => 'boolean',
            'has_marginal_notes' => 'boolean',
            'is_ruled' => 'boolean',
            'has_catchwords' => 'boolean',
            'is_facsimile' => 'boolean',
            'is_collated' => 'boolean',
            'is_illuminated' => 'boolean',
            'is_illustrated' => 'boolean',
            'has_author_marginalia' => 'boolean',
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
        if ($this->is_bika) {
            return 'بی‌کاتب';
        }
        return 'نامشخص';
    }

    public function getCatalogCitationAttribute(): ?string
    {
        return $this->metadata['catalog_citation'] ?? null;
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
        if (!empty($this->raw_text)) {
            return (bool) preg_match('/(?:^|[؛\n])\s*آغاز(?:\s*و\s*انجام)?[:\s]\s*برابر/u', $this->raw_text);
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
        if (!empty($this->raw_text)) {
            return (bool) (
                preg_match('/(?:^|[؛\n])\s*آغاز\s*و\s*انجام[:\s]\s*برابر/u', $this->raw_text) ||
                preg_match('/(?:^|[؛\n])\s*انجام[:\s]\s*برابر/u', $this->raw_text)
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

    public function searchableAs(): string
    {
        return 'manuscripts_index';
    }

    public function makeAllSearchableUsing($query)
    {
        return $query->with(['work:id,primary_title,clean_title,author_name,manuscripts_count', 'work.subjects:id,name', 'work.languages:id,name', 'scripts:id,name', 'scribes:people.id', 'libraryRecord:id,full_name',
            'catalogueEntries' => fn ($q) => $q->select('id', 'citable_type', 'citable_id', 'source_volume_id')]);
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
            'volume_number' => (int) $this->volume_number,
            'sequence_number' => (int) $this->sequence_number,
            'is_corrected' => (bool) $this->is_corrected,
            'has_marginal_notes' => (bool) $this->has_marginal_notes,
            'is_ruled' => (bool) $this->is_ruled,
            'has_catchwords' => (bool) $this->has_catchwords,
            'is_facsimile' => (bool) $this->is_facsimile,
            'is_collated' => (bool) $this->is_collated,
            'is_illuminated' => (bool) $this->is_illuminated,
            'is_illustrated' => (bool) $this->is_illustrated,
            'has_author_marginalia' => (bool) $this->has_author_marginalia,
            'scripts' => $this->relationLoaded('scripts') ? $this->scripts->pluck('name')->all() : [],
            // The work's subjects and languages, as filters of the manuscripts tab (backlog item 83, phase 5)
            'subjects' => $this->work?->subjects->pluck('name')->all() ?? [],
            'languages' => $this->work?->languages->pluck('name')->all() ?? [],
            // Every scribe, not only the first: «the manuscripts this person copied» (backlog item 83, phase 6)
            'scribe_ids' => $this->scribes->pluck('id')->map(fn ($id) => (int) $id)->all(),
            // The printed catalogue volumes that describe the copy, so a source page's count of a volume's copies
            // links to them (step 13.116)
            'source_volume_ids' => $this->catalogueEntries->pluck('source_volume_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
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

    public function catalogVolume(): BelongsTo
    {
        return $this->belongsTo(CatalogVolume::class);
    }

    /** The part of its volume this copy is of (step 13.150). */
    public function catalogVolumePart(): BelongsTo
    {
        return $this->belongsTo(CatalogVolumePart::class);
    }

    /** Where the copy is described in library catalogues (backlog item 51); rejected matches are kept for the record only. */
    public function catalogReferences(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ManuscriptCatalogReference::class);
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
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
        // The volume number is plain: in «5679-29/19» (Golpayegani) the slash belongs to the old number, not to a part
        if (! preg_match('~^([^/-]*\d[^/-]*?)\s*/\s*([^-]*\d[^-]*?)\s*(?:-\s*(.+))?$~u', $shelfmark, $m)) {
            return null;
        }
        // «ـ ف», «: فیاض», «؛ کر»: the joiners before a mark are not part of it
        $clean = fn (string $t) => preg_replace('/^[\s_:؛ـ\x{200C}-]+|[\s_:؛ـ\x{200C}-]+$/u', '', $t);
        $tail = $clean($m[3] ?? '');
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
     * «791-سنا» (step 13.146).
     */
    public static function collectionKeyOf(?string $shelfmark, ?string $rawText = null): ?string
    {
        $key = self::collectionKey($shelfmark, $rawText);
        if (! $key) {
            return null;
        }
        $collection = self::citesOnlySenate($rawText) ? 'سنا' . ($key['collection'] !== null ? '-' . $key['collection'] : '') : $key['collection'];

        return mb_substr($key['base'] . ($collection !== null ? '-' . $collection : ''), 0, 100);
    }

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
        foreach (preg_split('/\s*[؛،\n]\s*/u', (string) $this->raw_text) as $clause) {
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
    public function collectionSiblings(): \Illuminate\Support\Collection
    {
        $key = $this->collection_key ?? self::collectionKeyOf($this->shelfmark, $this->raw_text);
        if ($key === null) {
            return collect();
        }
        $base = self::collectionKey($this->shelfmark)['base'] ?? null;
        $byPart = self::query()
            ->when($this->library_id, fn ($q) => $q->where('library_id', $this->library_id), fn ($q) => $q->whereNull('library_id')->where('library', $this->library))
            ->when($this->collection_key !== null || $base === null,
                fn ($q) => $q->where('collection_key', $key),
                fn ($q) => $q->where('shelfmark_key', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $base) . '/%'))
            ->with(['work', 'scribe'])
            ->limit(400)
            ->get()
            ->filter(fn (self $m) => ($m->collection_key ?? self::collectionKeyOf($m->shelfmark, $m->raw_text)) === $key)
            ->sortBy(fn (self $m) => [(int) (self::collectionKey($m->shelfmark)['part'] ?? 0), $m->id])
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
