<?php

namespace Tests\Unit;

use App\Support\DateEstimates\DateVar;
use App\Support\DateEstimates\Engine;
use PHPUnit\Framework\TestCase;

/**
 * Ranges for unknown dates from the documented ones and the rules between them (step 14.166)
 */
class DateEstimateEngineTest extends TestCase
{
    /** An author's birth and death tied to a work, as Loader ties them. */
    private function author(Engine $e, int $person, int $work): void
    {
        $birth = DateVar::of(DateVar::BIRTH, $person);
        $death = DateVar::of(DateVar::DEATH, $person);
        $e->constrain($birth, $death, 0, 'life');
        $e->constrain($death, $birth, 100, 'life');
        $e->constrain(DateVar::of(DateVar::COMPOSITION, $work), $death, 0, 'author');
        $e->constrain($birth, DateVar::of(DateVar::COMPOSITION, $work), -15, 'author');
    }

    public function test_a_dated_work_bounds_its_authors_birth_and_death_apart()
    {
        $e = new Engine;
        $this->author($e, 1, 1);
        $work = DateVar::of(DateVar::COMPOSITION, 1);
        $e->seed($work, 1200, 1200);
        $s = $e->solve();

        $birth = DateVar::of(DateVar::BIRTH, 1);
        $death = DateVar::of(DateVar::DEATH, 1);
        // alive in 1200: one rule from the documented date
        $this->assertSame(1200, $s->from[$death]);
        $this->assertSame([1, 'author', $work], [$s->fromDepth[$death], $s->fromRule[$death], $s->fromVia[$death]]);
        $this->assertSame(1185, $s->to[$birth]);
        // the other ends come through the length of a life, a rule further
        $this->assertSame(1285, $s->to[$death]);
        $this->assertSame([2, 'life', $birth], [$s->toDepth[$death], $s->toRule[$death], $s->toVia[$death]]);
        $this->assertSame(1100, $s->from[$birth]);
        $this->assertArrayNotHasKey($work, $s->fromDepth);
    }

    public function test_the_tightest_bound_wins_and_a_second_work_narrows_the_death()
    {
        $e = new Engine;
        $this->author($e, 1, 1);
        $this->author($e, 1, 2);
        $e->seed(DateVar::of(DateVar::COMPOSITION, 1), 1200, 1200);
        $e->seed(DateVar::of(DateVar::COMPOSITION, 2), 1240, 1250);
        $s = $e->solve();

        $death = DateVar::of(DateVar::DEATH, 1);
        $this->assertSame(1240, $s->from[$death]);
        $this->assertSame(DateVar::of(DateVar::COMPOSITION, 2), $s->fromVia[$death]);
        $this->assertSame(1285, $s->to[$death]);
    }

    public function test_an_undated_work_lies_between_its_authors_youth_and_its_oldest_copy()
    {
        $e = new Engine;
        $this->author($e, 1, 1);
        $work = DateVar::of(DateVar::COMPOSITION, 1);
        $e->seed(DateVar::of(DateVar::BIRTH, 1), 1000, 1000);
        foreach ([10 => 1090, 11 => 1060, 12 => null] as $copy => $year) {
            $e->constrain($work, DateVar::of(DateVar::COPY, $copy), 0, 'copy');
            $e->seed(DateVar::of(DateVar::COPY, $copy), $year, $year);
        }
        $s = $e->solve();

        $this->assertSame([1015, 1060], [$s->from[$work], $s->to[$work]]);
        $this->assertSame(DateVar::of(DateVar::COPY, 11), $s->toVia[$work]);
        // the undated copy is after the work, and has no latest year
        $this->assertSame(1015, $s->from[DateVar::of(DateVar::COPY, 12)]);
        $this->assertSame(2, $s->fromDepth[DateVar::of(DateVar::COPY, 12)]);
        $this->assertArrayNotHasKey(DateVar::of(DateVar::COPY, 12), $s->to);
    }

    public function test_bounds_stop_three_rules_from_a_documented_date()
    {
        $e = new Engine;
        // a chain of commentaries: each no earlier than the one before
        for ($i = 1; $i <= 5; $i++) {
            $e->constrain(DateVar::of(DateVar::COMPOSITION, $i), DateVar::of(DateVar::COMPOSITION, $i + 1), 0, 'derived');
        }
        $e->seed(DateVar::of(DateVar::COMPOSITION, 1), 800, 800);
        $s = $e->solve();

        $this->assertSame(800, $s->from[DateVar::of(DateVar::COMPOSITION, 4)]);
        $this->assertSame(3, $s->fromDepth[DateVar::of(DateVar::COMPOSITION, 4)]);
        $this->assertArrayNotHasKey(DateVar::of(DateVar::COMPOSITION, 5), $s->from);
    }

    public function test_conflicting_documented_dates_are_listed_and_give_no_bound()
    {
        $e = new Engine;
        $this->author($e, 1, 1);
        $this->author($e, 1, 2);
        $death = DateVar::of(DateVar::DEATH, 1);
        $late = DateVar::of(DateVar::COMPOSITION, 1);
        $e->seed($death, 1100, 1100);
        $e->seed($late, 1150, 1150);
        $s = $e->solve();

        $this->assertSame([['rule' => 'author', 'x' => $late, 'from' => 1150, 'y' => $death, 'to' => 1100, 'gap' => 50]], $e->conflicts());
        $this->assertSame([$late => true, $death => true], $s->quarantined);
        // neither the death nor the work's date reaches the author's other work or his birth
        $this->assertSame([], $s->bounded());
    }

    public function test_bounds_that_leave_no_year_are_an_empty_range()
    {
        $e = new Engine;
        $this->author($e, 1, 1);
        $work = DateVar::of(DateVar::COMPOSITION, 1);
        $copy = DateVar::of(DateVar::COPY, 10);
        $e->constrain($work, $copy, 0, 'copy');
        // born in 1000, so writing from 1015; yet a copy of 1005. No two documented dates share a rule.
        $e->seed(DateVar::of(DateVar::BIRTH, 1), 1000, 1000);
        $e->seed($copy, 1005, 1005);
        $s = $e->solve();

        $this->assertSame([], $e->conflicts());
        $this->assertTrue($s->isEmpty($work));
        $this->assertSame([DateVar::of(DateVar::BIRTH, 1), $copy], [$s->fromVia[$work], $s->toVia[$work]]);
    }

    public function test_a_hidden_date_is_estimated_from_the_rest()
    {
        $e = new Engine;
        $this->author($e, 1, 1);
        $death = DateVar::of(DateVar::DEATH, 1);
        $e->seed($death, 1210, 1210);
        $e->seed(DateVar::of(DateVar::COMPOSITION, 1), 1200, 1200);

        $this->assertSame([1200, 1285], [$e->solve([$death => true])->from[$death], $e->solve([$death => true])->to[$death]]);
        $this->assertSame(1210, $e->solve()->from[$death]);
    }

    public function test_a_date_is_one_number()
    {
        $var = DateVar::of(DateVar::COPY, 323249);
        $this->assertSame(['manuscript', 323249, 'copy'], [DateVar::type($var), DateVar::id($var), DateVar::field($var)]);
        $this->assertSame(['person', 7, 'death'], [DateVar::type(DateVar::of(DateVar::DEATH, 7)), DateVar::id(DateVar::of(DateVar::DEATH, 7)), DateVar::field(DateVar::of(DateVar::DEATH, 7))]);
    }
}
