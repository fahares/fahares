<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class Person extends Model
{
    use Searchable;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // Derived columns follow their source unless the caller set them too
        static::saving(function (Person $person) {
            if ($person->isDirty('name') && ! $person->isDirty('normalized_name') && $person->exists) {
                $person->normalized_name = mb_substr(\App\Helpers\TextNormalizer::normalize($person->name), 0, 600);
            }
            if ($person->isDirty('death_year_hijri') && ! $person->isDirty('century_hijri') && $person->death_year_hijri) {
                $person->century_hijri = (int) ceil($person->death_year_hijri / 100);
            }
            // ...and the death range, certainty and hedge word, as for a copy's date (backlog item 96)
            if ($person->isDirty('life_dates_raw') || $person->isDirty('death_year_hijri')) {
                $person->forceFill(self::deathColumns($person->life_dates_raw, $person->death_year_hijri));
                $person->forceFill(self::deathKind($person->only(['death_from', 'death_to'])));
                // ...and life dates that give the year of death fill an empty death year (step 14.179)
                if (! $person->death_year_hijri && $person->death_from !== null && $person->death_from === $person->death_to) {
                    $person->death_year_hijri = $person->death_from;
                    $person->century_hijri = (int) ceil($person->death_from / 100);
                }
            }
        });
        // The author and scribe rows go with the person, as their old tables' cascade did; the other roles keep the name
        static::deleting(function (Person $person) {
            WorkPerson::where('person_id', $person->id)->where('role', WorkPerson::AUTHOR)->delete();
            ManuscriptPerson::where('person_id', $person->id)->where('role', ManuscriptPerson::SCRIBE)->delete();
        });
    }

    /**
     * The three or four digit year written before the dash of the life dates, a birth year («1040 - 1124 قمری») or a
     * year the person was alive in («1107ق- 12 قمری»); null when there is no dash or no such year.
     */
    public static function yearBeforeDash(?string $lifeDates): ?int
    {
        $text = strtr((string) $lifeDates, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        $parts = preg_split('/\s*[-–]\s*/u', $text);

        return count($parts) > 1 && preg_match('/(?<!\d)(\d{3,4})(?!\d)/u', $parts[0], $m) ? (int) $m[1] : null;
    }

    /** The highest number that a lone number in the life dates may be as a century («- 13» is «قرن 13»). */
    public const LAST_CENTURY = 15;

    /**
     * The death range columns (CopyDateRange) of the catalogue's life dates: the death is what follows the dash
     * («1040 - 1124 ؟ قمری» is 1124 with «؟», «730؟ - 834» a certain 834) and «ق13» is «قرن 13». Solar, Gregorian and
     * BC dates give no range. After a dash, one or two digits are a year of the first century («- 40 قمری»), or the
     * century when they are less than the birth year («1297-14 ق»). A number of 15 or less is the century as well
     * («- 13 ؟ قمری», «13ق - 13 قمری» are 1201-1300; step 14.173): the catalogue writes both the same way, and nearly
     * all of its people lived after year 15. It is the year only when the person's death year says so («- 11 قمری» of
     * a person whose death_year_hijri is 11). A death given as a span starts no earlier than the year before the dash
     * when that year lies in it: «1107ق- 12 قمری», alive in 1107 and dead in the 12th century, is 1107-1200 (step
     * 14.179). Without a range from the text, a death year is a range of its own.
     */
    public static function deathColumns(?string $lifeDates, ?int $deathYear): array
    {
        // death_kind is not among them: deathKind() gives it, so that the commands that compare these four stay as they are
        $range = ['from' => null, 'to' => null, 'certainty' => null, 'hedge' => null];
        $text = strtr((string) $lifeDates, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        if (trim($text) !== '' && ! preg_match('/میلاد|شمسی|خورشیدی|هجرت|(?<!\p{L})(?:م|ش|ق\.\s*م)(?!\p{L})/u', $text)) {
            $parts = preg_split('/\s*[-–]\s*/u', $text);
            $death = preg_replace('/(?<![\p{L}\d])ق\s*(\d{1,2})(?!\d)/u', 'قرن $1', end($parts));
            if (count($parts) > 1 && preg_match('/^\s*(\d{1,2})\s*(؟)?\s*(?:ق|قمری)?\s*(؟)?\s*$/u', $death, $m) && (int) $m[1] > 0) {
                $hedge = ($m[2] ?? '') . ($m[3] ?? '') !== '' ? '؟' : null;
                $birth = preg_match('/(?<!\d)(\d{1,4})(?!\d)/u', $parts[0], $b) ? (int) $b[1] : null;
                $century = ($birth !== null && (int) $m[1] < $birth) || ((int) $m[1] <= self::LAST_CENTURY && (int) $m[1] !== $deathYear);
                $range = $century
                    ? \App\Support\CopyDateRange::parse("قرن {$m[1]}" . ($hedge ? ' ؟' : ''))
                    : ['from' => (int) $m[1], 'to' => (int) $m[1], 'certainty' => \App\Support\PersonNameMatcher::certainty($hedge), 'hedge' => $hedge];
            } else {
                $range = \App\Support\CopyDateRange::parse($death);
            }
            $first = self::yearBeforeDash($lifeDates);
            if ($first !== null && $range['from'] !== null && $range['to'] !== null && $range['from'] < $first && $first <= $range['to']) {
                $range['from'] = $first;
            }
        }
        if ($range['from'] === null && $range['to'] === null && $deathYear) {
            $range = ['from' => $deathYear, 'to' => $deathYear, 'certainty' => null, 'hedge' => null];
        }

        return ['death_from' => $range['from'], 'death_to' => $range['to'], 'death_certainty' => $range['certainty'],
            'death_hedge_raw' => $range['hedge'] ? mb_substr($range['hedge'], 0, 60) : null];
    }

    /**
     * The kind column that goes with death columns (DateSpan, step 14.188): a death is one event, so a range of it is
     * always the years it may lie in.
     *
     * @return array{death_kind: ?string}
     */
    public static function deathKind(array $columns): array
    {
        return ['death_kind' => ($columns['death_from'] ?? null) !== ($columns['death_to'] ?? null) ? \App\Support\DateSpan::BETWEEN : null];
    }

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            // Misspelt forms (backlog item 90): searchable, not listed among the other names
            'wrong_names' => 'array',
            'birth_year_hijri' => 'integer',
            'death_year_hijri' => 'integer',
            'century_hijri' => 'integer',
            'death_year_gregorian' => 'integer',
            'death_from' => 'integer',
            'death_to' => 'integer',
            'is_author' => 'boolean',
            'is_scribe' => 'boolean',
            'is_translator' => 'boolean',
            'is_donor' => 'boolean',
            'works_count' => 'integer',
            'manuscripts_count' => 'integer',
            'scribed_from' => 'integer',
            'scribed_to' => 'integer',
        ];
    }

    /**
     * Life dates for display next to the name, in Persian digits: «۸۱۷–۸۹۸ ق», «د. ۸۹۸ ق»
     * or «سده‌ی ۱۰ ق». Null when nothing is known.
     */
    public function getLifeSpanAttribute(): ?string
    {
        $birth = $this->birth_year_hijri;
        $death = $this->death_year_hijri;

        $text = match (true) {
            $birth && $death => "{$birth}–{$death} ق",
            (bool) $death => "د. {$death} ق",
            (bool) $birth => "ز. {$birth} ق",
            (bool) $this->century_hijri => "سده‌ی {$this->century_hijri} ق",
            default => null,
        };

        return $text ? \App\Support\Fa::digits($text) : null;
    }

    public function getPersianSlugAttribute(): string
    {
        $slug = \Illuminate\Support\Str::slug($this->name, '-', null);
        if (mb_strlen($slug) > 75) {
            $slug = mb_substr($slug, 0, 75);
            $lastHyphen = mb_strrpos($slug, '-');
            if ($lastHyphen > 30) {
                $slug = mb_substr($slug, 0, $lastHyphen);
            }
        }
        return $slug ?: 'person';
    }

    public function getRouteKey(): string
    {
        $slug = $this->persian_slug;
        return $slug ? "{$this->id}-{$slug}" : (string) $this->id;
    }

    public function searchableAs(): string
    {
        return 'people_index';
    }

    /** Other names and misspelt forms: a search by either finds the person (backlog item 90). */
    public function searchAliases(): array
    {
        return array_values(array_merge((array) ($this->aliases ?? []), (array) ($this->wrong_names ?? [])));
    }

    /** What scribeFacts gives for this person, set for a whole batch by makeSearchableUsing */
    public ?array $scribeFacts = null;

    /** One query for the copies of all the scribes of a batch being indexed, not one for each */
    public function makeSearchableUsing(\Illuminate\Support\Collection $models): \Illuminate\Support\Collection
    {
        $facts = self::scribeFacts($models->where('is_scribe', true)->map->getKey()->all());
        foreach ($models as $person) {
            $person->scribeFacts = $facts[$person->id] ?? self::NO_COPIES;
        }

        return $models;
    }

    private const NO_COPIES = ['from' => null, 'to' => null, 'centuries' => [], 'cities' => []];

    /**
     * When and where each of the people copied (step 14.155), by person id: the first and last year of their dated
     * copies, the centuries a copy of theirs lies wholly in (Manuscript::copyBounds, as the copies' own century
     * filter), and the cities of their copies' places (CopyPlaces). A person without copies is not in the result.
     *
     * @return array<int, array{from: ?int, to: ?int, centuries: array<int, int>, cities: array<int, string>}>
     */
    public static function scribeFacts(array $ids): array
    {
        $facts = [];
        $rows = $ids ? \Illuminate\Support\Facades\DB::table('manuscript_people')->join('manuscripts', 'manuscripts.id', '=', 'manuscript_people.manuscript_id')
            ->where('manuscript_people.role', ManuscriptPerson::SCRIBE)->whereIn('manuscript_people.person_id', $ids)
            ->get(['manuscript_people.person_id', 'manuscripts.copy_date_from', 'manuscripts.copy_date_to', 'manuscripts.copy_place']) : [];
        foreach ($rows as $row) {
            $f = $facts[$row->person_id] ?? self::NO_COPIES;
            $from = $row->copy_date_from !== null && $row->copy_date_from >= 1 && $row->copy_date_from <= 1500 ? (int) $row->copy_date_from : null;
            $to = $row->copy_date_to !== null && $row->copy_date_to >= 1 && $row->copy_date_to <= 1500 ? (int) $row->copy_date_to : null;
            if ($from !== null) {
                $f['from'] = min($f['from'] ?? $from, $from);
            }
            if ($to !== null) {
                $f['to'] = max($f['to'] ?? $to, $to);
            }
            if ($from !== null && $to !== null && intdiv($from - 1, 100) === intdiv($to - 1, 100)) {
                $f['centuries'][intdiv($from - 1, 100) + 1] = true;
            }
            if ($city = \App\Support\CopyPlaces::cityOf($row->copy_place)) {
                $f['cities'][$city] = true;
            }
            $facts[$row->person_id] = $f;
        }

        return array_map(function ($f) {
            ksort($f['centuries']);

            return ['centuries' => array_keys($f['centuries']), 'cities' => array_keys($f['cities'])] + $f;
        }, $facts);
    }

    /**
     * When the person copied, beside the name in the scribes' tab: «کتابت ۱۰۸۵ ق» for one year, else the centuries
     * («کتابت سده‌ی ۱۱ ق», «کتابت سده‌های ۱۱ تا ۱۲ ق»), since a copy dated by its century alone gives no year.
     */
    public function getScribedSpanAttribute(): ?string
    {
        $from = $this->scribed_from ?? $this->scribed_to;
        $to = $this->scribed_to ?? $this->scribed_from;
        if (! $from) {
            return null;
        }
        [$first, $last] = [intdiv($from - 1, 100) + 1, intdiv($to - 1, 100) + 1];
        $text = match (true) {
            $from === $to => "کتابت {$from} ق",
            $first === $last => "کتابت سده‌ی {$first} ق",
            default => "کتابت سده‌های {$first} تا {$last} ق",
        };

        return \App\Support\Fa::digits($text);
    }

    public function toSearchableArray(): array
    {
        $aliases = $this->searchAliases();
        $copies = $this->scribeFacts ?? ($this->is_scribe ? self::scribeFacts([$this->id])[$this->id] ?? self::NO_COPIES : self::NO_COPIES);
        $normalizedWithAliases = trim($this->normalized_name . ' ' . implode(' ', $aliases));

        return [
            'id' => (int) $this->id,
            'name' => \App\Helpers\TextNormalizer::expandVariants($this->name),
            'normalized_name' => \App\Helpers\TextNormalizer::expandVariants($normalizedWithAliases),
            'aliases' => array_map(fn($a) => \App\Helpers\TextNormalizer::expandVariants($a), $aliases),
            'slug' => $this->slug,
            'transliteration' => $this->transliteration,
            'death_year_hijri' => $this->death_year_hijri ? (int) $this->death_year_hijri : null,
            'century_hijri' => $this->century_hijri ? (int) $this->century_hijri : null,
            'death_year_gregorian' => $this->death_year_gregorian ? (int) $this->death_year_gregorian : null,
            'is_author' => (bool) $this->is_author,
            'is_scribe' => (bool) $this->is_scribe,
            'is_translator' => (bool) $this->is_translator,
            'is_donor' => (bool) $this->is_donor,
            'works_count' => (int) $this->works_count,
            'manuscripts_count' => (int) $this->manuscripts_count,
            // The scribes' tab (step 14.155): when and where the person copied
            'scribed_from' => $copies['from'],
            'scribed_to' => $copies['to'],
            'copy_centuries' => $copies['centuries'],
            'copy_cities' => $copies['cities'],
        ];
    }

    /**
     * Works this person authored, alone or with others.
     */
    public function authoredWorks(): BelongsToMany
    {
        return $this->belongsToMany(Work::class, 'work_people')->withPivotValue('role', WorkPerson::AUTHOR)->withPivot(['position', 'note']);
    }

    public function works(): BelongsToMany
    {
        return $this->authoredWorks();
    }

    /**
     * Manuscripts this person copied, alone or with others.
     */
    public function scribedManuscripts(): BelongsToMany
    {
        return $this->belongsToMany(Manuscript::class, 'manuscript_people')->withPivotValue('role', ManuscriptPerson::SCRIBE)->withPivot(['position', 'note']);
    }

    /**
     * Works where this person is the primary author (works.author_id).
     */
    public function primaryWorks(): HasMany
    {
        return $this->hasMany(Work::class, 'author_id');
    }

    /** Manuscripts this person is connected with in another role (owner, endower…). */
    public function manuscriptRoles(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ManuscriptPerson::class)->where('role', '!=', ManuscriptPerson::SCRIBE);
    }

    /** Works this person is connected with in a role other than author (translator, dedicatee…). */
    public function workRoles(): HasMany
    {
        return $this->hasMany(WorkPerson::class)->where('role', '!=', WorkPerson::AUTHOR);
    }

    /**
     * Recompute works_count, manuscripts_count and the author and scribe flags from the person's rows of work_people
     * and manuscript_people. The flags are exactly «has a work» and «has a copy» (step 14.153): the search tabs of
     * authors and of scribes are divided by them, so a person whose last work is taken away stops being an author.
     * The rows' own hooks (RecountsPerson, SyncsPrimaryContributor) call this; what a bulk query changes is mended
     * each night by fahares:sync-person-roles.
     */
    public function recountContributions(): void
    {
        $works = $this->authoredWorks()->count();
        $manuscripts = $this->scribedManuscripts()->count();
        $copies = $manuscripts ? self::scribeFacts([$this->id])[$this->id] ?? self::NO_COPIES : self::NO_COPIES;
        $this->scribeFacts = $copies;

        $this->update([
            'works_count' => $works,
            'manuscripts_count' => $manuscripts,
            'is_author' => $works > 0,
            'is_scribe' => $manuscripts > 0,
            'scribed_from' => $copies['from'],
            'scribed_to' => $copies['to'],
        ]);
    }

    /**
     * After the date or the place of copies changed: their scribes' years, and their index documents, which hold
     * the centuries and cities too (a change of the place alone changes no column of the person).
     */
    public static function refreshScribesOf(array $manuscriptIds): void
    {
        $ids = $manuscriptIds ? \Illuminate\Support\Facades\DB::table('manuscript_people')->where('role', ManuscriptPerson::SCRIBE)
            ->whereIn('manuscript_id', $manuscriptIds)->whereNotNull('person_id')->distinct()->pluck('person_id')->all() : [];
        if ($ids) {
            static::withoutSyncingToSearch(fn () => static::recountMany($ids));
            static::whereIn('id', $ids)->get()->searchable();
        }
    }

    /** The people whose stored counts, flags or years of copying are not what their rows give (fahares:sync-person-roles) */
    public static function roleDrift(): \Illuminate\Support\Collection
    {
        $bound = fn (string $fn, string $column) => "{$fn}(CASE WHEN manuscripts.{$column} BETWEEN 1 AND 1500 THEN manuscripts.{$column} END)";
        $years = \Illuminate\Support\Facades\DB::table('manuscript_people')->join('manuscripts', 'manuscripts.id', '=', 'manuscript_people.manuscript_id')
            ->where('manuscript_people.role', ManuscriptPerson::SCRIBE)->whereNotNull('manuscript_people.person_id')->groupBy('manuscript_people.person_id')
            ->selectRaw("manuscript_people.person_id, {$bound('MIN', 'copy_date_from')} AS f, {$bound('MAX', 'copy_date_to')} AS t");
        $count = fn (string $table, string $role) => \Illuminate\Support\Facades\DB::table($table)->where('role', $role)->whereNotNull('person_id')
            ->groupBy('person_id')->selectRaw('person_id, COUNT(*) AS n');

        return \Illuminate\Support\Facades\DB::table('people')
            ->leftJoinSub($count('work_people', WorkPerson::AUTHOR), 'w', 'w.person_id', '=', 'people.id')
            ->leftJoinSub($count('manuscript_people', ManuscriptPerson::SCRIBE), 'm', 'm.person_id', '=', 'people.id')
            ->leftJoinSub($years, 'y', 'y.person_id', '=', 'people.id')
            ->whereRaw('(people.works_count <> COALESCE(w.n, 0) OR people.manuscripts_count <> COALESCE(m.n, 0)
                OR people.is_author <> (COALESCE(w.n, 0) > 0) OR people.is_scribe <> (COALESCE(m.n, 0) > 0)
                OR NOT (people.scribed_from <=> y.f) OR NOT (people.scribed_to <=> y.t))')
            ->orderBy('people.id')
            ->selectRaw('people.id, people.name, people.works_count, people.manuscripts_count, people.is_author, people.is_scribe,
                COALESCE(w.n, 0) AS works, COALESCE(m.n, 0) AS manuscripts,
                (NOT (people.scribed_from <=> y.f) OR NOT (people.scribed_to <=> y.t)) AS years_differ')
            ->get();
    }

    /** recountContributions for each of the people that exist; nulls and repeats are skipped */
    public static function recountMany(iterable $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', [...$ids]))));
        foreach ($ids ? static::whereIn('id', $ids)->get() : [] as $person) {
            $person->recountContributions();
        }
    }
}
