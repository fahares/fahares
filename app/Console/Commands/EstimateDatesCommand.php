<?php

namespace App\Console\Commands;

use App\Support\DateEstimates\DateVar;
use App\Models\Person;
use App\Models\Work;
use App\Support\DateEstimates\Engine;
use App\Support\DateEstimates\EstimateText;
use App\Support\DateEstimates\Loader;
use App\Support\DateEstimates\Solution;
use App\Support\MemoryLimit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Estimates the dates nothing documents, and lists the documented dates that cannot both be right (step 14.166,
 * docs/plans/date-estimates.md). The rules are Loader's, the reckoning Engine's.
 *
 * Without --apply it only counts. With it:
 *   - date_estimates is rebuilt whole: one row for each birth, death, composition or copy date that has no documented
 *     range, or only one end of one, and that the rules give a bound. A date in a direct conflict gets none (it is
 *     documented; the conflict is what to show), nor does one whose bounds leave no year.
 *   - date_conflicts keeps the rows found again (so a reviewer's dismissal stays), adds the new ones and drops those
 *     no longer found. A direct conflict is two documented dates that break a rule; an indirect one is two dates
 *     whose bounds leave a third date («via») no year.
 *
 * --validate hides a fifth of the documented dates of each kind (those documented within ten years), estimates them
 * from the rest and says how often the documented year lies inside the estimate. It writes nothing.
 */
class EstimateDatesCommand extends Command
{
    protected $signature = 'fahares:estimate-dates
        {--apply : Write date_estimates and date_conflicts; without it the command only counts}
        {--validate : Hold-out test of the estimates; writes nothing}
        {--seed=7 : Random seed of the hold-out test}';

    protected $description = 'Estimate undocumented dates from the ties between people, works and copies, and list conflicting dates';

    private const BUCKETS = [25, 50, 100, 150];

    /** More changed works than this are left to a full import of the index. */
    private const REINDEX_LIMIT = 5000;

    public function handle(): int
    {
        MemoryLimit::raiseTo('1G');
        $engine = Loader::load();
        $this->line('constraints: ' . number_format($engine->constraintCount()) . ', documented dates: ' . number_format(count($engine->seeded())));

        if ($this->option('validate')) {
            $this->validateEstimates($engine);

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $solution = $engine->solve();
        $conflicts = $this->conflictRows($engine, $solution);
        $this->reportConflicts($conflicts);

        // the works' placed estimates as they stand, to tell afterwards which search documents changed
        $placed = fn () => EstimateText::placedCompositions(DB::query())
            ->selectRaw("date_estimates.subject_id AS work_id, CONCAT(date_estimates.year_from, '-', date_estimates.year_to) AS years")->pluck('years', 'work_id')->all();
        $before = $apply ? $placed() : [];
        // ...and the years the people of no death year were last seen alive
        $alive = fn () => DB::table('people')->whereNull('death_year_hijri')->selectRaw('id, ' . EstimateText::ALIVE_YEAR_SQL . ' AS alive_year')
            ->havingNotNull('alive_year')->pluck('alive_year', 'id')->all();
        $aliveBefore = $apply ? $alive() : [];

        // the estimates are written as they are made: a quarter of a million rows are not held at once
        $shapes = [];
        $save = function () use ($engine, $solution, $conflicts, $apply, &$shapes) {
            if ($apply) {
                DB::table('date_estimates')->delete();
            }
            $chunk = [];
            foreach ($this->estimateRows($engine, $solution) as $row) {
                $shape = self::shape($row['year_from'], $row['year_to']);
                $shapes[$row['field']][$shape] = ($shapes[$row['field']][$shape] ?? 0) + 1;
                if (! $apply) {
                    continue;
                }
                $chunk[] = $row;
                if (count($chunk) >= 2000) {
                    DB::table('date_estimates')->insert($chunk);
                    $chunk = [];
                }
            }
            if ($apply) {
                $chunk && DB::table('date_estimates')->insert($chunk);
                $this->writeConflicts($conflicts);
            }
        };
        $apply ? DB::transaction($save) : $save();
        $this->reportEstimates($shapes);

        if (! $apply) {
            $this->line('Counted only. Use --apply to write.');

            return self::SUCCESS;
        }
        $this->reindexWorks($before, $placed());
        $this->reindexPeople($aliveBefore, $alive());
        $this->info('Wrote ' . number_format(array_sum(array_map('array_sum', $shapes))) . ' estimates and ' . number_format(count($conflicts)) . ' conflicts.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, array> conflict_key => row
     */
    private function conflictRows(Engine $engine, Solution $s): array
    {
        $rows = [];
        foreach ($engine->conflicts() as $c) {
            $rows += $this->conflictRow($c['rule'], true, $c['x'], $c['from'], $c['y'], $c['to'], $c['gap'], null);
        }
        // a date whose bounds leave no year: what gave its earliest year against what gave its latest
        foreach ($s->from as $var => $from) {
            if (! $s->isEmpty($var)) {
                continue;
            }
            $a = $s->fromVia[$var] ?? $var;
            $b = $s->toVia[$var] ?? $var;
            $rule = $s->fromRule[$var] ?? $s->toRule[$var];
            $rows += $this->conflictRow($rule, false, $a, $from, $b, $s->to[$var], $from - $s->to[$var], $var);
        }

        return $rows;
    }

    private function conflictRow(string $rule, bool $direct, int $a, int $from, int $b, int $to, int $gap, ?int $via): array
    {
        $row = ['rule' => $rule, 'direct' => $direct,
            'a_type' => DateVar::type($a), 'a_id' => DateVar::id($a), 'a_field' => DateVar::field($a), 'a_from' => $from,
            'b_type' => DateVar::type($b), 'b_id' => DateVar::id($b), 'b_field' => DateVar::field($b), 'b_to' => $to, 'gap' => $gap,
            'via_type' => $via === null ? null : DateVar::type($via), 'via_id' => $via === null ? null : DateVar::id($via),
            'via_field' => $via === null ? null : DateVar::field($via)];
        $key = sha1(implode('|', [$rule, (int) $direct, $a, $b, $via ?? '']));

        return [$key => ['conflict_key' => $key] + $row];
    }

    /**
     * @return \Generator<array>
     */
    private function estimateRows(Engine $engine, Solution $s): \Generator
    {
        foreach ($s->bounded() as $var) {
            $documented = $engine->seedFrom($var) !== null && $engine->seedTo($var) !== null;
            // the periods of a life are documented dates of the person, never an estimate of their own
            if ($documented || DateVar::isPeriod($var) || isset($s->quarantined[$var]) || $s->isEmpty($var)) {
                continue;
            }
            $row = ['subject_type' => DateVar::type($var), 'subject_id' => DateVar::id($var), 'field' => DateVar::field($var),
                'year_from' => $s->from[$var] ?? null, 'year_to' => $s->to[$var] ?? null];
            foreach (['from' => [$s->fromRule, $s->fromVia, $s->fromDepth], 'to' => [$s->toRule, $s->toVia, $s->toDepth]] as $side => [$rule, $via, $depth]) {
                $by = $via[$var] ?? null;
                $row += ["{$side}_rule" => $rule[$var] ?? null, "{$side}_evidence_type" => $by === null ? null : DateVar::type($by),
                    "{$side}_evidence_id" => $by === null ? null : DateVar::id($by), "{$side}_evidence_field" => $by === null ? null : DateVar::field($by),
                    "{$side}_depth" => $depth[$var] ?? null];
            }
            yield $row;
        }
    }

    private function reportConflicts(array $conflicts): void
    {
        $byRule = [];
        foreach ($conflicts as $c) {
            $key = ($c['direct'] ? 'direct' : 'indirect') . "\t" . $c['rule'] . "\t" . $c['a_field'] . ' > ' . $c['b_field'];
            $byRule[$key] = ($byRule[$key] ?? 0) + 1;
        }
        arsort($byRule);
        $this->table(['conflict', 'rule', 'dates', 'count'], array_map(fn ($k, $n) => [...explode("\t", $k), number_format($n)], array_keys($byRule), $byRule));

    }

    private function reportEstimates(array $shape): void
    {
        $columns = [...array_map(fn ($b) => "≤{$b}", self::BUCKETS), '>150', 'after', 'before'];
        $this->table(['estimates', 'all', ...$columns], array_map(
            fn ($field) => [$field, number_format(array_sum($shape[$field] ?? [])), ...array_map(fn ($c) => number_format($shape[$field][$c] ?? 0), $columns)],
            DateVar::FIELDS,
        ));
    }

    private static function shape(?int $from, ?int $to): string
    {
        if ($from === null || $to === null) {
            return $from === null ? 'before' : 'after';
        }
        foreach (self::BUCKETS as $bucket) {
            if ($to - $from <= $bucket) {
                return "≤{$bucket}";
            }
        }

        return '>150';
    }

    private function writeConflicts(array $conflicts): void
    {
        $now = now();
        foreach (array_chunk(array_values($conflicts), 1000) as $chunk) {
            DB::table('date_conflicts')->upsert(
                array_map(fn ($row) => $row + ['first_seen_at' => $now, 'last_seen_at' => $now], $chunk),
                ['conflict_key'],
                ['a_from', 'b_to', 'gap', 'last_seen_at'],
            );
        }
        // gone by its key, not by its time: two runs within one second carry the same last_seen_at
        $gone = array_diff(DB::table('date_conflicts')->pluck('conflict_key')->all(), array_keys($conflicts));
        foreach (array_chunk($gone, 1000) as $chunk) {
            DB::table('date_conflicts')->whereIn('conflict_key', $chunk)->delete();
        }
    }

    /**
     * The search documents of the works whose placed estimate changed (era_estimate_from, era_estimate_to and
     * composition_sort_year, step 14.197). When nearly all changed, as on the first run after the fields were added,
     * a full import is quicker and is left to the operator.
     */
    private function reindexWorks(array $before, array $after): void
    {
        $changed = array_keys(array_diff_assoc($after, $before) + array_diff_assoc($before, $after));
        if (count($changed) > self::REINDEX_LIMIT) {
            $this->warn(number_format(count($changed)) . ' works have a new or changed estimate: too many to reindex here. Run scout:import "App\\Models\\Work".');

            return;
        }
        foreach (array_chunk($changed, 200) as $ids) {
            (new Work)->makeAllSearchableUsing(Work::query())->whereIn('works.id', $ids)->get()->searchable();
        }
        $this->line('search documents refreshed: ' . number_format(count($changed)) . ' works');
    }

    /** The search documents of the people whose «alive in» year changed (alive_century, death_sort_year; step 14.198). */
    private function reindexPeople(array $before, array $after): void
    {
        $changed = array_keys(array_diff_assoc($after, $before) + array_diff_assoc($before, $after));
        if (count($changed) > self::REINDEX_LIMIT) {
            $this->warn(number_format(count($changed)) . ' people have a new or changed «alive in» year: too many to reindex here. Run scout:import "App\\Models\\Person".');

            return;
        }
        foreach (array_chunk($changed, 500) as $ids) {
            (new Person)->makeAllSearchableUsing(Person::query())->whereIn('people.id', $ids)->get()->searchable();
        }
        $this->line('search documents refreshed: ' . number_format(count($changed)) . ' people');
    }

    private function validateEstimates(Engine $engine): void
    {
        mt_srand((int) $this->option('seed'));
        $quarantined = $engine->solve()->quarantined;
        $pools = [];
        foreach ($engine->seeded() as $var) {
            [$from, $to] = [$engine->seedFrom($var), $engine->seedTo($var)];
            // the end of a date that lasted is no date of its own to hide
            if (! DateVar::isEnd($var) && $from !== null && $to !== null && $to - $from >= 0 && $to - $from <= 10 && ! isset($quarantined[$var])) {
                $pools[DateVar::kind($var)][] = $var;
            }
        }
        $rows = [];
        foreach ([DateVar::DEATH, DateVar::COMPOSITION, DateVar::COPY] as $kind) {
            $pool = $pools[$kind] ?? [];
            shuffle($pool);
            $hidden = array_fill_keys(array_slice($pool, 0, intdiv(count($pool), 5)), true);
            $s = $engine->solve($hidden);
            $n = ['both' => 0, 'inside' => 0, 'narrow' => 0, 'narrow_inside' => 0, 'after' => 0, 'after_ok' => 0, 'before' => 0, 'before_ok' => 0, 'none' => 0];
            foreach ($hidden as $var => $_) {
                [$a, $b] = [$engine->seedFrom($var), $engine->seedTo($var)];
                [$from, $to] = [$s->from[$var] ?? null, $s->to[$var] ?? null];
                if ($from !== null && $to !== null) {
                    $n['both']++;
                    $n['inside'] += $ok = (int) ($from <= $a && $b <= $to);
                    if ($to - $from <= 150) {
                        $n['narrow']++;
                        $n['narrow_inside'] += $ok;
                    }
                } elseif ($from !== null) {
                    $n['after']++;
                    $n['after_ok'] += (int) ($from <= $a);
                } elseif ($to !== null) {
                    $n['before']++;
                    $n['before_ok'] += (int) ($b <= $to);
                } else {
                    $n['none']++;
                }
            }
            $share = fn (int $part, int $all) => $all ? number_format($part) . ' (' . round(100 * $part / $all, 1) . '%)' : '0';
            $rows[] = [DateVar::FIELDS[$kind], number_format(count($hidden)), $share($n['both'], count($hidden)), $share($n['inside'], $n['both']),
                $share($n['narrow_inside'], $n['narrow']), $share($n['after_ok'], $n['after']), $share($n['before_ok'], $n['before']), number_format($n['none'])];
        }
        $this->table(['hidden', 'count', 'two-sided', 'of them right', 'right of ≤150 years', '«after» right', '«before» right', 'no bound'], $rows);
    }
}
