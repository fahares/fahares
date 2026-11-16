<?php

namespace App\Support\DateEstimates;

use App\Support\DateSpan;
use Illuminate\Support\Facades\DB;

/**
 * Fills the engine from the database (step 14.166): the documented dates as seeds, and one or two constraints for
 * every tie between a person, a work and a copy. All years are Hijri.
 *
 * | rule      | holds                                                        | from                                      |
 * | life      | birth ≤ death ≤ birth + LIFE                                 | every person                              |
 * | century   | death ≥ the century's first year, birth ≤ its last (seeds)   | century_hijri of a person with no death range (step 14.213) |
 * | author    | composition ≤ author's death; birth ≤ composition − AUTHOR_AGE | work_people «author»                    |
 * | translator| the same, for the translator                                 | work_people «translator»                  |
 * | dedicatee | composition ≤ his death; his birth ≤ composition             | work_people «dedicatee», «commissioner»   |
 * | copy      | composition ≤ the copy's date                                | manuscripts.work_id                       |
 * | autograph | the copy's date ≤ the author's death                         | is_autograph, a work with one author      |
 * | scribe    | the copy's date ≤ scribe's death; birth ≤ date − SCRIBE_AGE  | manuscript_people «scribe»                |
 * | patron    | the copy's date ≤ patron's death; his birth ≤ the date       | manuscript_people «patron»                |
 * | derived   | the base work's composition ≤ that of its commentary, gloss… | work_relations, but «related» and «part»  |
 *
 * Left out: a tie with a certainty («doubtful», «probable»), which is itself a guess; the author of a work that has a
 * translator, since the work's date is then the translation's; and a copy date that names the author's time
 * (CopyDateRange::relative), which was made from the author's death and documents nothing; and a three-digit copy
 * year read again from the work's date (CopyDateRange::reread), for the same reason.
 *
 * A composition or a copying that lasted from one year to another (DateSpan::SPAN, with both outer years) is two
 * dates, its start and its end (step 14.222): what must come before it comes before its start, and who must have lived
 * through it was alive at its end.
 *
 * | rule       | with a start and an end                                                        |
 * | author     | the END of the composition ≤ his death; his birth ≤ its START − AUTHOR_AGE     |
 * | dedicatee  | its START ≤ his death; his birth ≤ its END                                     |
 * | copy       | the START of the composition ≤ the START of the copying (its END, an autograph) |
 * | autograph  | the END of the copying ≤ the author's death                                    |
 * | scribe     | the END of the copying ≤ his death; his birth ≤ its START − SCRIBE_AGE         |
 * | patron     | the START of the copying ≤ his death; his birth ≤ its START                    |
 * | derived    | the START of the base work ≤ the END of the work that rests on it              |
 *
 * A date of any other kind, a century or a range of no known kind, is one date as before.
 *
 * The periods of a person's life a reviewer approved (person_periods, step 14.226) bound the life: the person was
 * born before the first of them began and died after the last of them ended. They are two dates of the person, the
 * start of the earliest period and the end of the latest (DateVar::periodStart, periodEnd), under the rule «period».
 * A proposed or a rejected period, and one the reviewer marked uncertain, says nothing.
 */
final class Loader
{
    public const LIFE = 100;

    public const AUTHOR_AGE = 15;

    public const SCRIBE_AGE = 10;

    public const RULE_LIFE = 'life';

    public const RULE_AUTHOR = 'author';

    public const RULE_TRANSLATOR = 'translator';

    public const RULE_DEDICATEE = 'dedicatee';

    public const RULE_COPY = 'copy';

    public const RULE_AUTOGRAPH = 'autograph';

    public const RULE_SCRIBE = 'scribe';

    public const RULE_PATRON = 'patron';

    public const RULE_DERIVED = 'derived';

    public const RULE_PERIOD = 'period';

    /** What a broken rule says, for the reports. */
    public const CONFLICT_LABELS = [
        self::RULE_LIFE => 'عمر بیش از ۱۰۰ سال، یا وفات پیش از تولد',
        self::RULE_AUTHOR => 'تألیف پس از وفات مؤلف، یا پیش از ۱۵ سالگی او',
        self::RULE_TRANSLATOR => 'ترجمه پس از وفات مترجم، یا پیش از ۱۵ سالگی او',
        self::RULE_DEDICATEE => 'تألیف پس از وفات مُهدی‌الیه، یا پیش از تولد او',
        self::RULE_COPY => 'کتابت نسخه پیش از تألیف اثر',
        self::RULE_AUTOGRAPH => 'نسخه‌ی خط مؤلف پس از وفات مؤلف',
        self::RULE_SCRIBE => 'کتابت پس از وفات کاتب، یا پیش از ۱۰ سالگی او',
        self::RULE_PATRON => 'کتابت پس از وفات بانی، یا پیش از تولد او',
        self::RULE_DERIVED => 'اثر وابسته پیش از اثر اصلی',
        self::RULE_PERIOD => 'دوره‌ای از زندگی پیش از تولد یا پس از وفات',
    ];

    public static function load(): Engine
    {
        $engine = new Engine;
        $int = fn ($v) => $v === null ? null : (int) $v;

        foreach (DB::table('people')->select(['id', 'birth_year_hijri', 'death_from', 'death_to', 'century_hijri'])->orderBy('id')->lazyById(20000) as $p) {
            $birth = DateVar::of(DateVar::BIRTH, $p->id);
            $death = DateVar::of(DateVar::DEATH, $p->id);
            $engine->seed($birth, $int($p->birth_year_hijri), $int($p->birth_year_hijri));
            $engine->seed($death, $int($p->death_from), $int($p->death_to));
            // The century of the life (step 14.213): the death is no earlier than its first year, the birth no later than its last
            if ($p->death_from === null && $p->death_to === null && $p->century_hijri) {
                $engine->seed($death, ((int) $p->century_hijri - 1) * 100 + 1, null);
                if ($p->birth_year_hijri === null) {
                    $engine->seed($birth, null, (int) $p->century_hijri * 100);
                }
            }
            $engine->constrain($birth, $death, 0, self::RULE_LIFE);
            $engine->constrain($death, $birth, self::LIFE, self::RULE_LIFE);
        }

        self::periods($engine);

        // the end of each composition that lasted: work id => its number; any other work's end is its one date
        $workEnds = [];
        foreach (DB::table('works')->select(['id', 'composition_date_from', 'composition_date_to', 'composition_date_kind', 'composition_date_start_to', 'composition_date_end_from'])
            ->where(fn ($q) => $q->whereNotNull('composition_date_from')->orWhereNotNull('composition_date_to'))->orderBy('id')->lazyById(20000) as $w) {
            $var = DateVar::of(DateVar::COMPOSITION, $w->id);
            if (self::seedSpan($engine, $var, DateSpan::fromColumns($w, 'composition_date'))) {
                $workEnds[$w->id] = DateVar::end($var);
            }
        }
        $workEnd = fn (int $workId) => $workEnds[$workId] ?? DateVar::of(DateVar::COMPOSITION, $workId);

        $ties = DB::table('work_people')->whereNotNull('person_id')->whereNull('certainty')
            ->whereIn('role', ['author', 'translator', 'dedicatee', 'commissioner'])->orderBy('id')->get(['work_id', 'person_id', 'role']);
        $translated = [];
        foreach ($ties as $t) {
            if ($t->role === 'translator') {
                $translated[$t->work_id] = true;
            }
        }
        $authors = [];
        foreach ($ties as $t) {
            if ($t->role === 'author' && isset($translated[$t->work_id])) {
                continue;
            }
            $work = DateVar::of(DateVar::COMPOSITION, $t->work_id);
            $birth = DateVar::of(DateVar::BIRTH, $t->person_id);
            $death = DateVar::of(DateVar::DEATH, $t->person_id);
            if ($t->role === 'author') {
                $authors[$t->work_id][] = (int) $t->person_id;
            }
            [$rule, $age] = match ($t->role) {
                'author' => [self::RULE_AUTHOR, self::AUTHOR_AGE],
                'translator' => [self::RULE_TRANSLATOR, self::AUTHOR_AGE],
                default => [self::RULE_DEDICATEE, 0],
            };
            if ($rule === self::RULE_DEDICATEE) {
                $engine->constrain($work, $death, 0, $rule);
                $engine->constrain($birth, $workEnd((int) $t->work_id), 0, $rule);
            } else {
                $engine->constrain($workEnd((int) $t->work_id), $death, 0, $rule);
                $engine->constrain($birth, $work, -$age, $rule);
            }
        }
        unset($ties, $translated);

        $copies = DB::table('manuscripts')->select(['id', 'work_id', 'copy_date_from', 'copy_date_to', 'copy_date_kind', 'copy_date_start_to', 'copy_date_end_from',
            'copy_date_hedge_raw', 'is_autograph'])->orderBy('id')->lazyById(20000);
        // the end of each copying that lasted: copy id => its number
        $copyEnds = [];
        foreach ($copies as $m) {
            $copy = DateVar::of(DateVar::COPY, $m->id);
            if (! self::namesAuthorsTime($m->copy_date_hedge_raw) && \App\Support\CopyDateRange::rereadYear($m->copy_date_hedge_raw) === null
                && self::seedSpan($engine, $copy, DateSpan::fromColumns($m, 'copy_date'))) {
                $copyEnds[$m->id] = DateVar::end($copy);
            }
            if ($m->work_id === null) {
                continue;
            }
            $copyEnd = $copyEnds[$m->id] ?? $copy;
            // an autograph is written while the work is: the work's start is before its end, no more
            $engine->constrain(DateVar::of(DateVar::COMPOSITION, $m->work_id), $m->is_autograph ? $copyEnd : $copy, 0, self::RULE_COPY);
            if ($m->is_autograph && count($authors[$m->work_id] ?? []) === 1) {
                $engine->constrain($copyEnd, DateVar::of(DateVar::DEATH, $authors[$m->work_id][0]), 0, self::RULE_AUTOGRAPH);
            }
        }
        unset($authors);

        foreach (DB::table('manuscript_people')->whereNotNull('person_id')->whereIn('role', ['scribe', 'patron'])
            ->select(['id', 'manuscript_id', 'person_id', 'role'])->orderBy('id')->lazyById(20000) as $t) {
            $copy = DateVar::of(DateVar::COPY, $t->manuscript_id);
            [$rule, $age] = $t->role === 'scribe' ? [self::RULE_SCRIBE, self::SCRIBE_AGE] : [self::RULE_PATRON, 0];
            // the scribe was alive when the copying ended; the patron, for all we know, when it began
            $engine->constrain($t->role === 'scribe' ? ($copyEnds[$t->manuscript_id] ?? $copy) : $copy, DateVar::of(DateVar::DEATH, $t->person_id), 0, $rule);
            $engine->constrain(DateVar::of(DateVar::BIRTH, $t->person_id), $copy, -$age, $rule);
        }

        foreach (DB::table('work_relations')->whereNotIn('type', ['related', 'part'])->orderBy('id')->get(['work_id', 'related_work_id']) as $r) {
            $engine->constrain(DateVar::of(DateVar::COMPOSITION, $r->related_work_id), $workEnd((int) $r->work_id), 0, self::RULE_DERIVED);
        }

        return $engine;
    }

    /**
     * The approved periods of each person as two dates: the latest year the earliest period may have begun in, which
     * the birth comes before, and the earliest year the latest period may have ended in, which the death comes after.
     * A period of one year, or of some time between two years, begins and ends somewhere in its years.
     */
    private static function periods(Engine $engine): void
    {
        $bounds = [];
        $rows = DB::table('person_periods')->where('status', \App\Models\PersonPeriod::APPROVED)->whereNull('period_certainty')
            ->orderBy('id')->get(['person_id', 'period_from', 'period_to', 'period_kind', 'period_start_to', 'period_end_from']);
        foreach ($rows as $row) {
            $date = DateSpan::fromColumns($row, 'period');
            if ($date->isEmpty()) {
                continue;
            }
            // of a duration the start's own latest year and the end's own earliest; of anything else the outer years
            $startBy = $date->isSpan() ? ($date->start()[1] ?? $date->to) : ($date->to ?? $date->from);
            $endFrom = $date->isSpan() ? ($date->end()[0] ?? $date->from) : ($date->from ?? $date->to);
            $bound = &$bounds[(int) $row->person_id];
            $bound['start'] = min($bound['start'] ?? PHP_INT_MAX, $startBy);
            $bound['end'] = max($bound['end'] ?? PHP_INT_MIN, $endFrom);
            unset($bound);
        }
        foreach ($bounds as $personId => $bound) {
            $start = DateVar::periodStart($personId);
            $end = DateVar::periodEnd($personId);
            $engine->seed($start, $bound['start'], $bound['start']);
            $engine->seed($end, $bound['end'], $bound['end']);
            $engine->constrain(DateVar::of(DateVar::BIRTH, $personId), $start, 0, self::RULE_PERIOD);
            $engine->constrain($end, DateVar::of(DateVar::DEATH, $personId), 0, self::RULE_PERIOD);
        }
    }

    /**
     * Seeds a date. A duration with both outer years is seeded as two dates, its start under $var and its end under
     * DateVar::end($var), each with the years it may lie in (DateSpan::start, DateSpan::end); the answer says so. Any
     * other date is one, with its outer years.
     */
    private static function seedSpan(Engine $engine, int $var, DateSpan $date): bool
    {
        if (! $date->isSpan() || $date->from === null || $date->to === null) {
            $engine->seed($var, $date->from, $date->to);

            return false;
        }
        $engine->seed($var, ...$date->start());
        $engine->seed(DateVar::end($var), ...$date->end());

        return true;
    }

    /** A copy date made from the author's death («در عصر مؤلف», «در حیات مؤلف», «در عصر تألیف»). */
    public static function namesAuthorsTime(?string $hedge): bool
    {
        return $hedge !== null && preg_match('/مؤلف|ت[أا]لیف/u', $hedge) === 1;
    }
}
