<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The filters of the list of copies on a work page (backlog item 79, step 14.99): library, city, century of copying,
 * script and four yes/no flags, read from the address so a filtered list can be shared. Each filter offers only the
 * values found in this work's copies, with their counts under the other filters. The scope is one work, so the
 * database answers, not the search index.
 */
class WorkCopyFilters
{
    /** The list shows the filters from this many copies on (or whenever one is set) */
    public const MIN_COPIES = 10;

    /** yes/no flags: param => [label, «yes» label, «no» label] */
    public const FLAGS = [
        'autograph' => ['دستخط مؤلف', 'فقط دستخط مؤلف', null],
        'dated' => ['تاریخ کتابت', 'تاریخ‌دار', 'بی‌تاریخ'],
        'scribe' => ['کاتب', 'کاتب‌دار', 'بی‌کاتب'],
        'facsimile' => ['اصل یا عکسی', 'عکسی یا میکروفیلم', 'اصل'],
    ];

    public const PARAMS = ['library', 'city', 'century', 'script', 'autograph', 'dated', 'scribe', 'facsimile'];

    /** The copy's century from its copy-date range, when the range lies within one century */
    private const CENTURY = 'CASE WHEN COALESCE(manuscripts.copy_date_from, manuscripts.copy_date_hijri_year) > 0
        AND FLOOR((COALESCE(manuscripts.copy_date_from, manuscripts.copy_date_hijri_year) - 1) / 100)
          = FLOOR((COALESCE(manuscripts.copy_date_to, manuscripts.copy_date_from, manuscripts.copy_date_hijri_year) - 1) / 100)
        THEN FLOOR((COALESCE(manuscripts.copy_date_from, manuscripts.copy_date_hijri_year) - 1) / 100) + 1 END';

    /** @var array<string, int|string> the valid filters asked for */
    public array $values = [];

    /** @param  int[]  $excluded  copies kept out of the list (photographs shown under their copy, step 14.16) */
    public function __construct(private int $workId, private array $excluded = [])
    {
    }

    public static function fromRequest(Request $request, int $workId, array $excluded = []): self
    {
        $filters = new self($workId, $excluded);
        foreach (self::PARAMS as $param) {
            $value = $request->query($param);
            if (! is_string($value) || $value === '') {
                continue;
            }
            $value = trim($value);
            $clean = match ($param) {
                'library', 'script' => ctype_digit($value) && (int) $value > 0 ? (int) $value : null,
                'century' => ctype_digit($value) && $value >= 1 && $value <= 15 ? (int) $value : null,
                'city' => mb_substr($value, 0, 255),
                'autograph' => $value === '1' ? '1' : null,
                default => in_array($value, ['0', '1'], true) ? $value : null,
            };
            if ($clean !== null) {
                $filters->values[$param] = $clean;
            }
        }

        return $filters;
    }

    public function isEmpty(): bool
    {
        return ! $this->values;
    }

    /** Apply the filters (all, or all but one for that filter's own counts) to a query of the work's copies */
    public function apply(Builder|\Illuminate\Database\Eloquent\Builder|HasMany $query, ?string $except = null): mixed
    {
        foreach ($this->values as $param => $value) {
            if ($param === $except) {
                continue;
            }
            match ($param) {
                'library' => $query->where('manuscripts.library_id', $value),
                'city' => $query->where('manuscripts.city', $value),
                'century' => $query->whereRaw('(' . self::CENTURY . ') = ?', [$value]),
                'script' => $query->whereIn('manuscripts.id', DB::table('manuscript_script')->where('script_id', $value)->select('manuscript_id')),
                'autograph' => $query->where('manuscripts.is_autograph', true),
                'dated' => $value === '1' ? $query->where(fn ($q) => $this->dated($q)) : $query->whereNot(fn ($q) => $this->dated($q)),
                'scribe' => $value === '1' ? $query->where(fn ($q) => $this->scribed($q)) : $query->whereNot(fn ($q) => $this->scribed($q)),
                'facsimile' => $query->where('manuscripts.is_facsimile', $value === '1'),
            };
        }

        return $query;
    }

    /** Dated as the list shows it: a copy date was recorded */
    private function dated($q): void
    {
        $q->whereNotNull('manuscripts.copy_date_raw')->where('manuscripts.copy_date_raw', '!=', '');
    }

    /** With a scribe as the list shows it: a scribe's name, or the author's own hand */
    private function scribed($q): void
    {
        $q->where(fn ($s) => $s->whereNotNull('manuscripts.scribe_name')->where('manuscripts.scribe_name', '!=', ''))
            ->orWhere('manuscripts.is_autograph', true);
    }

    /** The work's copies in the list, before any filter */
    private function base(): Builder
    {
        return DB::table('manuscripts')->where('manuscripts.work_id', $this->workId)
            ->when($this->excluded, fn ($q) => $q->whereNotIn('manuscripts.id', $this->excluded));
    }

    /** Copies in the list without filters */
    public function total(): int
    {
        return $this->base()->count();
    }

    /**
     * The options of each filter: [param => [[value, label, count], …]], each counted under the other filters, the
     * chosen value kept even when nothing is left under the others.
     *
     * @return array<string, list<array{0: int|string, 1: string, 2: int}>>
     */
    public function options(): array
    {
        $options = [];

        $libraries = $this->apply($this->base(), 'library')
            ->join('libraries', 'libraries.id', '=', 'manuscripts.library_id')
            ->groupBy('libraries.id', 'libraries.name', 'libraries.city')
            ->selectRaw('libraries.id, libraries.name, libraries.city, COUNT(*) AS n')
            ->orderByDesc('n')->orderBy('libraries.name')->get();
        $sameName = $libraries->countBy('name');
        $options['library'] = $libraries->map(fn ($l) => [(int) $l->id, $l->name . ($sameName[$l->name] > 1 && $l->city ? '، ' . $l->city : ''), (int) $l->n])->all();

        $options['city'] = $this->apply($this->base(), 'city')
            ->whereNotNull('manuscripts.city')->where('manuscripts.city', '!=', '')
            ->groupBy('manuscripts.city')->selectRaw('manuscripts.city AS v, COUNT(*) AS n')
            ->orderByDesc('n')->orderBy('v')->get()
            ->map(fn ($r) => [$r->v, $r->v, (int) $r->n])->all();

        $options['century'] = $this->apply($this->base(), 'century')
            ->selectRaw('(' . self::CENTURY . ') AS v, COUNT(*) AS n')
            ->groupByRaw('v')->havingRaw('v IS NOT NULL')->orderBy('v')->get()
            ->map(fn ($r) => [(int) $r->v, 'سده‌ی ' . Fa::num((int) $r->v), (int) $r->n])->all();

        $options['script'] = $this->apply($this->base(), 'script')
            ->join('manuscript_script', 'manuscript_script.manuscript_id', '=', 'manuscripts.id')
            ->join('scripts', 'scripts.id', '=', 'manuscript_script.script_id')
            ->groupBy('scripts.id', 'scripts.name')
            ->selectRaw('scripts.id, scripts.name, COUNT(DISTINCT manuscripts.id) AS n')
            ->orderByDesc('n')->orderBy('scripts.name')->get()
            ->map(fn ($r) => [(int) $r->id, $r->name, (int) $r->n])->all();

        foreach (self::FLAGS as $flag => [, $yes, $no]) {
            $others = $this->apply($this->base(), $flag);
            $all = (clone $others)->count();
            $yesCount = (new self($this->workId))->withValues([$flag => '1'])->apply($others)->count();
            $options[$flag] = array_values(array_filter([
                ['1', $yes, $yesCount],
                $no ? ['0', $no, $all - $yesCount] : null,
            ]));
        }

        // a chosen value with nothing left under the other filters still shows, so it can be seen and changed
        foreach ($this->values as $param => $value) {
            if (! collect($options[$param])->contains(fn ($o) => (string) $o[0] === (string) $value)) {
                $options[$param][] = [$value, $this->label($param, $value), 0];
            }
        }

        return $options;
    }

    private function withValues(array $values): self
    {
        $this->values = $values;

        return $this;
    }

    /** The label of one chosen value, for the chips above the list */
    public function label(string $param, int|string $value): string
    {
        return match ($param) {
            'library' => (string) (DB::table('libraries')->where('id', $value)->value('name') ?? 'کتابخانه‌ی #' . $value),
            'script' => (string) (DB::table('scripts')->where('id', $value)->value('name') ?? 'خط #' . $value),
            'century' => 'سده‌ی ' . Fa::num((int) $value),
            'city' => (string) $value,
            default => (string) (self::FLAGS[$param][$value === '1' ? 1 : 2] ?? $value),
        };
    }

    /** @return list<array{0: string, 1: string}> a chip per chosen filter: [label, its param] */
    public function chips(): array
    {
        return collect($this->values)->map(fn ($value, $param) => [$this->label($param, $value), $param])->values()->all();
    }
}
