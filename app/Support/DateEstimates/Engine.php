<?php

namespace App\Support\DateEstimates;

/**
 * Ranges of years for unknown dates, from the documented ones and the rules that tie them (step 14.166,
 * docs/plans/date-estimates.md). It knows nothing of people, works or copies: a date is a number (DateVar), a
 * documented date a seed (from, to; either may be missing), and a rule a constraint «x is no later than y + c».
 *
 *   - Two seeds that break a constraint are a direct conflict (conflicts()). Both are quarantined: they give no bound
 *     to anything, since one of them is wrong and nothing says which.
 *   - The other seeds spread in MAX_DEPTH rounds. A round uses only the bounds the round before set, so a bound's
 *     depth is the number of rules between it and a documented date. From «x ≤ y + c»: x's latest year is at most y's
 *     latest + c, and y's earliest year is at least x's earliest − c.
 *   - Every bound keeps the rule and the neighbour that gave it.
 */
final class Engine
{
    public const MAX_DEPTH = 3;

    /** @var array<int, int> */
    private array $seedFrom = [];

    /** @var array<int, int> */
    private array $seedTo = [];

    /** The constraints, in four lists of the same length: x ≤ y + c, by rule. */
    private array $cx = [];

    private array $cy = [];

    private array $cc = [];

    private array $cr = [];

    public function seed(int $var, ?int $from, ?int $to): void
    {
        if ($from !== null) {
            $this->seedFrom[$var] = $from;
        }
        if ($to !== null) {
            $this->seedTo[$var] = $to;
        }
    }

    /** $x is no later than $y + $c. */
    public function constrain(int $x, int $y, int $c, string $rule): void
    {
        $this->cx[] = $x;
        $this->cy[] = $y;
        $this->cc[] = $c;
        $this->cr[] = $rule;
    }

    public function constraintCount(): int
    {
        return count($this->cx);
    }

    public function seedFrom(int $var): ?int
    {
        return $this->seedFrom[$var] ?? null;
    }

    public function seedTo(int $var): ?int
    {
        return $this->seedTo[$var] ?? null;
    }

    /** @return int[] every date with a documented bound */
    public function seeded(): array
    {
        return array_keys($this->seedFrom + $this->seedTo);
    }

    /**
     * The constraints two documented dates break: x's earliest year is later than y's latest + c.
     *
     * @return list<array{rule: string, x: int, from: int, y: int, to: int, gap: int}>
     */
    public function conflicts(): array
    {
        $found = [];
        foreach ($this->cx as $i => $x) {
            $y = $this->cy[$i];
            if (isset($this->seedFrom[$x], $this->seedTo[$y]) && $this->seedFrom[$x] > $this->seedTo[$y] + $this->cc[$i]) {
                $found[] = ['rule' => $this->cr[$i], 'x' => $x, 'from' => $this->seedFrom[$x], 'y' => $y, 'to' => $this->seedTo[$y],
                    'gap' => $this->seedFrom[$x] - $this->seedTo[$y] - $this->cc[$i]];
            }
        }

        return $found;
    }

    /**
     * @param  array<int, true>  $hidden  dates whose seeds are left out, as keys (the hold-out test)
     */
    public function solve(array $hidden = []): Solution
    {
        $quarantined = [];
        foreach ($this->conflicts() as $conflict) {
            $quarantined[$conflict['x']] = true;
            $quarantined[$conflict['y']] = true;
        }
        $out = $quarantined + $hidden;
        $s = new Solution;
        $s->quarantined = $quarantined;
        $s->from = array_diff_key($this->seedFrom, $out);
        $s->to = array_diff_key($this->seedTo, $out);

        // the bounds the round before set: at first the seeds
        $frontFrom = $s->from;
        $frontTo = $s->to;
        for ($depth = 1; $depth <= self::MAX_DEPTH && ($frontFrom || $frontTo); $depth++) {
            $newFrom = $newFromBy = $newTo = $newToBy = [];
            foreach ($this->cx as $i => $x) {
                $y = $this->cy[$i];
                if (isset($frontTo[$y])) {
                    $year = $frontTo[$y] + $this->cc[$i];
                    if ($year < ($newTo[$x] ?? $s->to[$x] ?? PHP_INT_MAX)) {
                        $newTo[$x] = $year;
                        $newToBy[$x] = $i;
                    }
                }
                if (isset($frontFrom[$x])) {
                    $year = $frontFrom[$x] - $this->cc[$i];
                    if ($year > ($newFrom[$y] ?? $s->from[$y] ?? PHP_INT_MIN)) {
                        $newFrom[$y] = $year;
                        $newFromBy[$y] = $i;
                    }
                }
            }
            foreach ($newFrom as $var => $year) {
                $s->from[$var] = $year;
                $s->fromDepth[$var] = $depth;
                $s->fromRule[$var] = $this->cr[$newFromBy[$var]];
                $s->fromVia[$var] = $this->cx[$newFromBy[$var]];
            }
            foreach ($newTo as $var => $year) {
                $s->to[$var] = $year;
                $s->toDepth[$var] = $depth;
                $s->toRule[$var] = $this->cr[$newToBy[$var]];
                $s->toVia[$var] = $this->cy[$newToBy[$var]];
            }
            $frontFrom = $newFrom;
            $frontTo = $newTo;
        }

        return $s;
    }
}
