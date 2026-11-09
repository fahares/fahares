<?php

namespace App\Support;

use App\Models\CatalogVolume;
use App\Models\Language;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\ManuscriptPerson;
use App\Models\Person;
use App\Models\Script;
use App\Models\SourceEntry;
use App\Models\Subject;
use App\Models\Work;
use App\Models\WorkPerson;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The part of the data an insights page is about (backlog item 107): the copies of one author's works, of one
 * library, of a span of centuries and so on, read from the query string (`/insights?author=1110&century=5-8`).
 * Some filters are on the works (author, work, subject, language) and the rest on the copies; each side of the
 * page reads its own table narrowed by both. Unknown or malformed values are dropped, so an old link never fails.
 */
class InsightsScope
{
    /** The query parameters, in the order the heading names them */
    public const PARAMS = ['author', 'work', 'subject', 'language', 'scribe', 'library', 'city', 'copy_city', 'script', 'catalog', 'century'];

    private const WORK_PARAMS = ['author', 'work', 'subject', 'language'];

    // The temporary tables of the scope's copies and works (open)
    private const COPIES = 'insights_scope_copies';
    private const WORKS = 'insights_scope_works';

    private bool $open = false;

    /** @var array<string, int|string|array{0: int, 1: int}> the valid values, by parameter */
    public array $values = [];

    /** @var array<string, object> the records of the values, for their names */
    public array $records = [];

    public static function fromRequest(Request $request): self
    {
        return self::fromArray($request->only(self::PARAMS));
    }

    public static function fromArray(array $input): self
    {
        $scope = new self;
        foreach (self::PARAMS as $param) {
            $value = $input[$param] ?? null;
            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }
            $value = trim(strtr((string) $value, array_combine(mb_str_split('۰۱۲۳۴۵۶۷۸۹'), range(0, 9))));
            $id = ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
            $record = match ($param) {
                'author', 'scribe' => $id ? Person::find($id, ['id', 'name', 'slug']) : null,
                'work' => $id ? Work::find($id, ['id', 'primary_title', 'slug']) : null,
                'subject' => $id ? Subject::with('children:id,parent_id')->find($id, ['id', 'name', 'slug']) : null,
                'language' => $id ? Language::find($id, ['id', 'name']) : null,
                'script' => $id ? Script::find($id, ['id', 'name']) : null,
                'library' => $id ? Library::find($id, ['id', 'name', 'city', 'slug']) : null,
                'catalog' => $id ? CatalogVolume::find($id, ['id', 'title', 'volume_number']) : null,
                'city' => mb_strlen($value) <= 60 && DB::table('manuscripts')->where('city', $value)->exists() ? $value : null,
                'copy_city' => CopyPlaces::isCity($value) ? $value : null,
                'century' => self::centuryRange($value),
            };
            if ($record === null) {
                continue;
            }
            $scope->values[$param] = is_object($record) ? $id : $record;
            if (is_object($record)) {
                $scope->records[$param] = $record;
            }
        }

        return $scope;
    }

    /** «7» or «5-8» as [5, 8], within the centuries of the data */
    private static function centuryRange(string $value): ?array
    {
        if (! preg_match('/^(\d{1,2})(?:\s*[-–]\s*(\d{1,2}))?$/', $value, $m)) {
            return null;
        }
        $from = (int) $m[1];
        $to = (int) ($m[2] ?? $m[1]);
        [$from, $to] = [min($from, $to), max($from, $to)];

        return $from >= 1 && $to <= 15 ? [$from, $to] : null;
    }

    public function isEmpty(): bool
    {
        return ! $this->values;
    }

    public function get(string $param): mixed
    {
        return $this->values[$param] ?? null;
    }

    /** The values as query parameters, in a fixed order, so one scope has one link and one cache key */
    public function query(): array
    {
        $query = [];
        foreach ($this->values as $param => $value) {
            $query[$param] = $param === 'century' ? ($value[0] === $value[1] ? (string) $value[0] : "{$value[0]}-{$value[1]}") : $value;
        }

        return $query;
    }

    /** The query without one filter, for the way to remove it */
    public function without(string $param): array
    {
        return array_diff_key($this->query(), [$param => true]);
    }

    public function key(): string
    {
        return md5(http_build_query($this->query()));
    }

    public function hasCopyFilter(): bool
    {
        return (bool) array_diff(array_keys($this->values), self::WORK_PARAMS);
    }

    public function hasWorkFilter(): bool
    {
        return (bool) array_intersect(array_keys($this->values), self::WORK_PARAMS);
    }

    /**
     * Copies the scope's copies and works into two narrow temporary tables, with the columns the charts read, so
     * each chart reads them instead of looking its rows up again in the large tables (a library of 48 000 copies
     * took two minutes that way, and the whole data 40 s, as each chart read the large table again). A temporary
     * table lives only in this connection; close() drops it.
     */
    public function open(): void
    {
        $this->close();
        $features = implode(', ', config('insights.features'));
        $copies = $this->conditions(DB::table('manuscripts'))->select(DB::raw(
            "id, work_id, scribe_id, library_id, catalog_volume_id, city, copy_place, copy_date_from, copy_date_to, copy_date_hijri_year, copy_date_certainty, folios, `lines`, {$features}"
        ));
        DB::statement('CREATE TEMPORARY TABLE ' . self::COPIES . ' (PRIMARY KEY (id), KEY (work_id)) ' . $copies->toSql(), $copies->getBindings());

        $works = $this->hasCopyFilter()
            ? DB::table('works')->whereIn('id', DB::table(self::COPIES)->whereNotNull('work_id')->distinct()->select('work_id'))
            : $this->conditions(DB::table('works'));
        $works->select(['id', 'author_id', 'composition_date_from', 'composition_date_to', 'composition_date_certainty', 'composition_place', 'composition_year_hijri']);
        DB::statement('CREATE TEMPORARY TABLE ' . self::WORKS . ' (PRIMARY KEY (id), KEY (author_id)) ' . $works->toSql(), $works->getBindings());
        $this->open = true;
    }

    public function close(): void
    {
        DB::statement('DROP TEMPORARY TABLE IF EXISTS ' . self::COPIES . ', ' . self::WORKS);
        $this->open = false;
    }

    /** The manuscripts table, or the scope's copies under its name, for a query or a join */
    public function manuscriptsTable(): string
    {
        return $this->table(self::COPIES, 'manuscripts');
    }

    public function worksTable(): string
    {
        return $this->table(self::WORKS, 'works');
    }

    /** The copies of the scope */
    public function manuscripts(): Builder
    {
        return DB::table($this->manuscriptsTable());
    }

    /** The works of the scope: those the work filters name, with a copy the copy filters keep */
    public function works(): Builder
    {
        return DB::table($this->worksTable());
    }

    /** Narrows a query to the scope by a column of copy ids or of work ids; nothing for the whole data */
    public function whereManuscript(Builder $query, string $column): Builder
    {
        return $this->isEmpty() ? $query : $query->join(self::COPIES, self::COPIES . '.id', '=', $column);
    }

    public function whereWork(Builder $query, string $column): Builder
    {
        return $this->isEmpty() ? $query : $query->join(self::WORKS, self::WORKS . '.id', '=', $column);
    }

    private function table(string $temporary, string $name): string
    {
        if (! $this->open) {
            throw new \LogicException('InsightsScope::open() first');
        }

        return "{$temporary} as {$name}";
    }

    /** The scope's conditions on the manuscripts table, or on the works table (its work filters only) */
    private function conditions(Builder $query): Builder
    {
        $copies = $query->from === 'manuscripts';
        foreach ($this->values as $param => $value) {
            if (! $copies && ! in_array($param, self::WORK_PARAMS, true)) {
                continue;
            }
            match ($param) {
                'author', 'work', 'subject', 'language' => $this->onWork($query, $copies ? 'manuscripts.work_id' : 'works.id', $param, $value),
                'scribe' => $query->whereIn('manuscripts.id', DB::table('manuscript_people')->where('role', ManuscriptPerson::SCRIBE)->where('person_id', $value)->select('manuscript_id')),
                'library' => $query->where('manuscripts.library_id', $value),
                'city' => $query->where('manuscripts.city', $value),
                'copy_city' => $query->whereIn('manuscripts.copy_place', CopyPlaces::variants($value) ?: ['']),
                'script' => $query->whereIn('manuscripts.id', DB::table('manuscript_script')->where('script_id', $value)->select('manuscript_id')),
                // The copies the volume describes, as the search's catalogue filter and the source pages take them
                // (step 14.114): its own copies, and those it describes after another catalogue
                'catalog' => $query->whereIn('manuscripts.id', DB::table('source_entries')->where('source_volume_id', $value)
                    ->where('status', '!=', SourceEntry::STATUS_REJECTED)->where('citable_type', Manuscript::class)->select('citable_id')),
                // Copies dated within the centuries, the whole range of the date, as the search takes them
                'century' => Manuscript::whereCopiedWithin($query, ($value[0] - 1) * 100 + 1, $value[1] * 100),
            };
        }

        return $query;
    }

    private function onWork(Builder $query, string $column, string $param, int $value): void
    {
        match ($param) {
            'work' => $query->where($column, $value),
            'author' => $query->whereIn($column, DB::table('work_people')->where('role', WorkPerson::AUTHOR)->where('person_id', $value)->select('work_id')),
            'subject' => $query->whereIn($column, DB::table('subject_work')
                ->whereIn('subject_id', collect([$value])->concat($this->records['subject']->children->pluck('id')))->select('work_id')),
            'language' => $query->whereIn($column, DB::table('language_work')->where('language_id', $value)->select('work_id')),
        };
    }

    /**
     * The scope's filters that a search tab has too, as its parameters, so a link from a chart to the search keeps
     * them: all of them on the manuscripts tab (step 14.114), the work filters on the works tab.
     */
    public function searchParams(string $type): array
    {
        $map = match ($type) {
            'manuscripts' => ['work' => 'work_id', 'scribe' => 'scribe_id', 'library' => 'library_id', 'city' => 'city', 'subject' => 'subject_id',
                'language' => 'language_id', 'script' => 'script_id', 'copy_city' => 'copy_city', 'author' => 'author_id', 'catalog' => 'source_volume_id'],
            'works' => $this->hasCopyFilter() ? [] : ['author' => 'author_id', 'subject' => 'subject_id', 'language' => 'language_id'],
            default => [],
        };
        $params = [];
        foreach ($map as $param => $name) {
            if (isset($this->values[$param])) {
                $params[$name] = $this->values[$param];
            }
        }
        if ($type === 'manuscripts' && ($century = $this->get('century'))) {
            $params['year_from'] = ($century[0] - 1) * 100 + 1;
            $params['year_to'] = $century[1] * 100;
        }

        return $params;
    }

    /**
     * The filters as [parameter, label, link to the record or null], for the heading and its chips.
     *
     * @return array<int, array{0: string, 1: string, 2: ?string}>
     */
    public function labels(): array
    {
        $labels = [];
        foreach ($this->values as $param => $value) {
            $r = $this->records[$param] ?? null;
            $labels[] = match ($param) {
                'author' => [$param, 'آثار ' . $r->name, route('people.show', $r)],
                'work' => [$param, 'اثر «' . $r->primary_title . '»', route('works.show', $r)],
                'subject' => [$param, 'موضوع ' . $r->name, route('subjects.show', $r)],
                'language' => [$param, 'به زبان ' . $r->name, null],
                'scribe' => [$param, 'کتابت ' . $r->name, route('people.show', $r)],
                'library' => [$param, 'کتابخانه‌ی ' . $r->name . ' (' . $r->city . ')', route('libraries.show', $r)],
                'city' => [$param, 'کتابخانه‌های ' . $value, null],
                'copy_city' => [$param, 'کتابت‌شده در ' . $value, null],
                'script' => [$param, 'به خط ' . $r->name, null],
                'catalog' => [$param, (str_starts_with($r->title, 'فهرست') ? '' : 'فهرست ') . $r->title . ($r->volume_number ? ' ج ' . Fa::digits($r->volume_number) : ''), null],
                'century' => [$param, $value[0] === $value[1] ? 'سده‌ی ' . Fa::digits($value[0]) : 'سده‌های ' . Fa::digits($value[0]) . ' تا ' . Fa::digits($value[1]), null],
            };
        }

        return $labels;
    }
}
