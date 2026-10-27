<?php

namespace App\Support;

use App\Helpers\TextNormalizer;
use App\Models\Language;
use App\Models\Library;
use App\Models\Script;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The filters of one search tab, each defined once (backlog item 83, phase 5): as a Meilisearch filter for a
 * search with words, as a database condition for browsing without them, and as a label the page shows with a
 * way to remove it. Unknown or malformed values are dropped, so an old or hand-made link never fails.
 */
class SearchFilters
{
    /**
     * The query parameters of each tab's filters. The first ones narrow the tab to one record's list (the works of
     * an author, the manuscripts of a work or of a scribe), as the pages of those records link to it (phase 6).
     */
    public const PARAMS = [
        'works' => ['author_id', 'subject_id', 'language_id', 'century', 'work_form', 'source_id', 'has_autograph'],
        'manuscripts' => ['work_id', 'scribe_id', 'library_id', 'city', 'subject_id', 'language_id', 'century', 'year_from', 'year_to', 'script_id',
            'copy_city', 'facsimile', 'folios_from', 'folios_to', 'flags'],
        'people' => ['role', 'century'],
    ];

    public const WORK_FORMS = [
        'translation' => 'ترجمه',
        'selection' => 'گزیده و تلخیص',
        'treatise' => 'رساله',
        'verse' => 'منظوم (شعر)',
        'table' => 'جدول و تقویم',
        'compilation' => 'جنگ و مجموعه',
        'notes' => 'یادداشت‌ها و تعلیقات',
        'commentary' => 'شرح',
        'lecture_notes' => 'تقریرات',
    ];

    public const FLAGS = [
        'is_autograph' => 'دستخط مؤلف',
        'is_illuminated' => 'مذهّب (تذهیب و سرلوح)',
        'is_illustrated' => 'مصوّر (نگاره)',
        'is_corrected' => 'تصحیح‌شده',
        'has_marginal_notes' => 'دارای حواشی',
        'has_author_marginalia' => 'حواشی مؤلف',
        'is_collated' => 'مقابله‌شده',
        'is_bita' => 'بی‌تاریخ',
        'is_bika' => 'بی‌کاتب',
    ];

    public const ROLES = [
        'author' => 'پدیدآور',
        'scribe' => 'کاتب',
        'translator' => 'مترجم',
        'donor' => 'واقف و اهداکننده',
    ];

    /** The bibliographies and biographical works a work can be «mentioned in» */
    public const SOURCE_KINDS = [Source::KIND_BIBLIOGRAPHY, Source::KIND_BIOGRAPHICAL];

    public ?Subject $subject = null;
    public ?Library $library = null;
    public ?Script $script = null;
    public ?Language $language = null;
    public ?Source $source = null;
    public ?\App\Models\Work $work = null;
    public ?\App\Models\Person $person = null;

    /** The parameters that narrow a tab to one record's list; the counts of the other filters are taken within it */
    public const CONTEXT = ['work_id', 'scribe_id', 'author_id'];

    /** @param array<string, mixed> $values the valid values, by parameter */
    public function __construct(public string $type, public array $values = [])
    {
        $this->subject = isset($values['subject_id']) ? Subject::with(['children', 'parent'])->find($values['subject_id']) : null;
        $this->library = isset($values['library_id']) ? Library::find($values['library_id']) : null;
        $this->script = isset($values['script_id']) ? Script::find($values['script_id']) : null;
        $this->language = isset($values['language_id']) ? Language::find($values['language_id']) : null;
        $this->source = isset($values['source_id']) ? Source::whereIn('kind', self::SOURCE_KINDS)->find($values['source_id']) : null;
        $this->work = isset($values['work_id']) ? \App\Models\Work::find($values['work_id']) : null;
        $this->person = ($id = $values['scribe_id'] ?? $values['author_id'] ?? null) ? \App\Models\Person::find($id) : null;
        // A filter whose record is gone is no filter
        foreach (['subject_id' => 'subject', 'library_id' => 'library', 'script_id' => 'script', 'language_id' => 'language', 'source_id' => 'source',
            'work_id' => 'work', 'scribe_id' => 'person', 'author_id' => 'person'] as $param => $record) {
            if (isset($this->values[$param]) && ! $this->$record) {
                unset($this->values[$param]);
            }
        }
    }

    public static function fromRequest(Request $request, string $type): self
    {
        $values = [];
        foreach (self::PARAMS[$type] ?? [] as $param) {
            $value = self::clean($param, $param === 'flags' ? [...(array) $request->input('flags', []), ...(array) $request->input('flag')] : $request->input($param));
            if ($value !== null) {
                $values[$param] = $value;
            }
        }

        return new self($type, $values);
    }

    private static function clean(string $param, mixed $value): mixed
    {
        if (is_array($value) && $param !== 'flags') {
            return null;
        }

        return match ($param) {
            'subject_id', 'library_id', 'script_id', 'language_id', 'source_id', 'work_id', 'scribe_id', 'author_id' => ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : null,
            'century' => ctype_digit((string) $value) && $value >= 1 && $value <= 15 ? (int) $value : null,
            'year_from', 'year_to', 'folios_from', 'folios_to' => ctype_digit(TextNormalizer::normalize((string) $value)) ? (int) TextNormalizer::normalize((string) $value) : null,
            'city' => is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 60) : null,
            'copy_city' => CopyPlaces::isCity($value) ? $value : null,
            'work_form' => isset(self::WORK_FORMS[$value]) ? $value : null,
            'facsimile' => in_array($value, ['0', '1'], true) ? $value : null,
            'has_autograph' => $value === '1' ? '1' : null,
            'role' => isset(self::ROLES[$value]) ? $value : null,
            'flags' => array_values(array_unique(array_intersect(array_filter((array) $value, 'is_string'), array_keys(self::FLAGS)))) ?: null,
            default => null,
        };
    }

    public function get(string $param): mixed
    {
        return $this->values[$param] ?? null;
    }

    public function isEmpty(): bool
    {
        return ! $this->values;
    }

    /** The subject and its children, by name for the index and by id for the database */
    private function subjectFamily(): \Illuminate\Support\Collection
    {
        return collect([$this->subject])->concat($this->subject->children);
    }

    /** The column that holds the work: the work's own id, or the manuscript's work */
    private function workKey(): string
    {
        return $this->type === 'works' ? 'works.id' : 'manuscripts.work_id';
    }

    /** [from, to] of the copying or composition years the filters ask for, or null */
    private function yearRange(): ?array
    {
        $from = $this->get('year_from');
        $to = $this->get('year_to');
        if ($century = $this->get('century')) {
            $from = max($from ?? 0, ($century - 1) * 100 + 1);
            $to = min($to ?? PHP_INT_MAX, $century * 100);
        }

        return $from === null && $to === null ? null : [$from, $to === PHP_INT_MAX ? null : $to];
    }

    /** The Meilisearch filter of all the tab's filters, or null */
    public function engine(): ?string
    {
        $quote = fn ($v) => '"' . addslashes((string) $v) . '"';
        $parts = [];
        $yearField = $this->type === 'works' ? 'era_year' : 'copy_date_hijri_year';

        foreach ($this->values as $param => $value) {
            $parts[] = match ($param) {
                'work_id' => "work_id = {$value}",
                'scribe_id' => "scribe_ids = {$value}",
                'author_id' => "author_ids = {$value}",
                'subject_id' => 'subjects IN [' . $this->subjectFamily()->map(fn ($s) => $quote($s->name))->implode(', ') . ']',
                'language_id' => 'languages = ' . $quote($this->language->name),
                'library_id' => 'library_id = ' . $value,
                'city' => 'city = ' . $quote($value),
                'script_id' => 'scripts = ' . $quote($this->script->name),
                'copy_city' => ($places = collect(CopyPlaces::variants($value))->map(fn ($p) => $quote(TextNormalizer::normalize($p)))->unique()->implode(', ')) !== ''
                    ? "copy_place IN [{$places}]" : 'id = 0',
                'facsimile' => 'is_facsimile = ' . ($value === '1' ? 'true' : 'false'),
                'folios_from' => "folios >= {$value}",
                'folios_to' => "folios <= {$value}",
                'flags' => implode(' AND ', array_map(fn ($f) => "{$f} = true", $value)),
                'work_form' => 'work_form = ' . $quote($value),
                'source_id' => "bibliographies = {$value}",
                'has_autograph' => 'has_autograph = true',
                'role' => "is_{$value} = true",
                'century' => $this->type === 'people' ? "century_hijri = {$value}" : null,
                default => null,
            };
        }
        if ($this->type !== 'people' && ($range = $this->yearRange())) {
            [$from, $to] = $range;
            $parts[] = implode(' AND ', array_filter([$from !== null ? "{$yearField} >= {$from}" : null, $to !== null ? "{$yearField} <= {$to}" : null]));
        }

        $parts = array_filter($parts);

        return $parts ? implode(' AND ', array_map(fn ($p) => "({$p})", $parts)) : null;
    }

    /** The Meilisearch filter of the list the tab is narrowed to (CONTEXT), or null */
    public function contextEngine(): ?string
    {
        $context = new self($this->type, array_intersect_key($this->values, array_flip(self::CONTEXT)));

        return $context->engine();
    }

    /** The same filters on a query of the tab's model */
    public function apply(Builder $query): Builder
    {
        foreach ($this->values as $param => $value) {
            match ($param) {
                // Subqueries on the link tables, not whereHas: the database runs them as one semi-join instead of
                // a lookup per manuscript (10 s for a subject over 324 000 manuscripts)
                'work_id' => $query->where('manuscripts.work_id', $value),
                'scribe_id' => $query->whereIn('manuscripts.id', DB::table('manuscript_scribe')->where('person_id', $value)->select('manuscript_id')),
                'author_id' => $query->whereIn('works.id', DB::table('work_author')->where('person_id', $value)->select('work_id')),
                'subject_id' => $query->whereIn($this->workKey(), DB::table('subject_work')->whereIn('subject_id', $this->subjectFamily()->pluck('id'))->select('work_id')),
                'language_id' => $query->whereIn($this->workKey(), DB::table('language_work')->where('language_id', $value)->select('work_id')),
                'library_id' => $query->where('library_id', $value),
                'city' => $query->where('city', $value),
                'script_id' => $query->whereIn('manuscripts.id', DB::table('manuscript_script')->where('script_id', $value)->select('manuscript_id')),
                'copy_city' => $query->whereIn('copy_place', CopyPlaces::variants($value)),
                'facsimile' => $query->where('is_facsimile', $value === '1'),
                'folios_from' => $query->where('folios', '>=', $value),
                'folios_to' => $query->where('folios', '<=', $value),
                'flags' => array_map(fn ($f) => $query->where($f, true), $value),
                'work_form' => $query->where('work_form', $value),
                'source_id' => $query->whereExists(fn ($q) => $q->from('source_entries')->whereColumn('source_entries.citable_id', 'works.id')
                    ->where('source_entries.citable_type', \App\Models\Work::class)->where('source_entries.source_id', $value)
                    ->where('source_entries.status', '!=', SourceEntry::STATUS_REJECTED)),
                'has_autograph' => $query->whereIn('works.id', DB::table('manuscripts')->where('is_autograph', true)->whereNotNull('work_id')->select('work_id')),
                'role' => $query->where("is_{$value}", true),
                'century' => $this->type === 'people' ? $query->where('century_hijri', $value) : null,
                default => null,
            };
        }
        if ($this->type !== 'people' && ($range = $this->yearRange())) {
            [$from, $to] = $range;
            if ($this->type === 'works') {
                // The year of composition, or else the author's death (the work's «era», as in the index); written
                // so both halves can use an index, rather than a lookup of the author for every work
                $between = fn ($q, string $column) => $q->when($from !== null, fn ($q) => $q->where($column, '>=', $from))
                    ->when($to !== null, fn ($q) => $q->where($column, '<=', $to));
                $query->where(fn ($q) => $q->where(fn ($q) => $between($q, 'works.composition_year_hijri')->whereNotNull('works.composition_year_hijri'))
                    ->orWhere(fn ($q) => $q->whereNull('works.composition_year_hijri')
                        ->whereIn('works.author_id', $between(DB::table('people')->whereNotNull('death_year_hijri'), 'death_year_hijri')->select('id'))));
            } else {
                $from !== null && $query->where('copy_date_hijri_year', '>=', $from);
                $to !== null && $query->where('copy_date_hijri_year', '<=', $to);
            }
        }

        return $query;
    }

    /**
     * The active filters as [label, the parameters to drop to remove it], one per flag.
     *
     * @return array<int, array{0: string, 1: array}>
     */
    public function active(): array
    {
        $fa = fn ($v) => Fa::digits($v);
        $chips = [];
        foreach ($this->values as $param => $value) {
            $label = match ($param) {
                'work_id' => 'نسخه‌های اثر: ' . $this->work->primary_title,
                'scribe_id' => 'کتابت ' . $this->person->name,
                'author_id' => 'آثار ' . $this->person->name,
                'subject_id' => 'موضوع: ' . ($this->subject->title ?: $this->subject->name) . ($this->subject->children->isNotEmpty() ? ' (با زیرموضوع‌ها)' : ''),
                'language_id' => 'زبان: ' . $this->language->name,
                'library_id' => 'کتابخانه: ' . $this->library->short_name . ($this->library->city ? '، ' . $this->library->city : ''),
                'city' => 'شهر کتابخانه: ' . $value,
                'script_id' => 'خط: ' . $this->script->name,
                'copy_city' => 'محل کتابت: ' . $value,
                'facsimile' => $value === '1' ? 'فقط عکسی' : 'فقط اصل (بی عکسی)',
                'work_form' => 'قالب: ' . self::WORK_FORMS[$value],
                'source_id' => 'یادشده در ' . ($this->source->short_name ?: $this->source->title),
                'has_autograph' => 'دارای دستخط مؤلف',
                'role' => 'نقش: ' . self::ROLES[$value],
                'century' => 'سده‌ی ' . $fa($value) . match ($this->type) { 'works' => ' (تألیف یا وفات مؤلف)', 'people' => ' (وفات)', default => ' (کتابت)' },
                default => null,
            };
            if ($label) {
                $chips[] = [$label, [$param]];
            }
            if ($param === 'flags') {
                foreach ($value as $flag) {
                    $chips[] = [self::FLAGS[$flag], ['flags' => array_values(array_diff($value, [$flag]))]];
                }
            }
        }
        foreach ([['year_from', 'year_to', 'سال'], ['folios_from', 'folios_to', 'برگ']] as [$fromKey, $toKey, $noun]) {
            $from = $this->get($fromKey);
            $to = $this->get($toKey);
            if ($from !== null || $to !== null) {
                $chips[] = [$noun . ': ' . ($from !== null ? 'از ' . $fa($from) . ' ' : '') . ($to !== null ? 'تا ' . $fa($to) : ''), [$fromKey, $toKey]];
            }
        }

        return $chips;
    }

    /** The search page's address without one active filter */
    public static function removeUrl(array $drop): string
    {
        $query = request()->except(['page', 'flag']);
        foreach ($drop as $key => $value) {
            if (is_int($key)) {
                unset($query[$value]);
            } elseif ($value) {
                $query[$key] = $value;
            } else {
                unset($query[$key]);
            }
        }

        return route('search', $query);
    }
}
