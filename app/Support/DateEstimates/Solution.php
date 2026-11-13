<?php

namespace App\Support\DateEstimates;

/**
 * What Engine::solve found: for each date, its earliest and latest year where one is known, and for a bound the
 * engine derived, how many rules it is from a documented date (depth), the rule that gave it and the neighbouring
 * date it came from (via). A bound with no depth is the documented one.
 */
final class Solution
{
    /** @var array<int, int> */
    public array $from = [];

    /** @var array<int, int> */
    public array $to = [];

    public array $fromDepth = [];

    public array $fromRule = [];

    public array $fromVia = [];

    public array $toDepth = [];

    public array $toRule = [];

    public array $toVia = [];

    /** @var array<int, true> dates in a direct conflict, whose seeds were left out */
    public array $quarantined = [];

    /** The bounds leave no year: an indirect conflict between what gave the two bounds. */
    public function isEmpty(int $var): bool
    {
        return isset($this->from[$var], $this->to[$var]) && $this->from[$var] > $this->to[$var];
    }

    /** @return int[] */
    public function bounded(): array
    {
        return array_keys($this->from + $this->to);
    }
}
