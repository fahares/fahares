<?php

namespace App\Support\DateEstimates;

use Illuminate\Support\Facades\DB;

/**
 * Fills the engine from the database (step 14.166): the documented dates as seeds, and one or two constraints for
 * every tie between a person, a work and a copy. All years are Hijri.
 *
 * | rule      | holds                                                        | from                                      |
 * | life      | birth ≤ death ≤ birth + LIFE                                 | every person                              |
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
 * (CopyDateRange::relative), which was made from the author's death and documents nothing.
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
    ];

    public static function load(): Engine
    {
        $engine = new Engine;
        $int = fn ($v) => $v === null ? null : (int) $v;

        foreach (DB::table('people')->select(['id', 'birth_year_hijri', 'death_from', 'death_to'])->orderBy('id')->lazyById(20000) as $p) {
            $birth = DateVar::of(DateVar::BIRTH, $p->id);
            $death = DateVar::of(DateVar::DEATH, $p->id);
            $engine->seed($birth, $int($p->birth_year_hijri), $int($p->birth_year_hijri));
            $engine->seed($death, $int($p->death_from), $int($p->death_to));
            $engine->constrain($birth, $death, 0, self::RULE_LIFE);
            $engine->constrain($death, $birth, self::LIFE, self::RULE_LIFE);
        }

        foreach (DB::table('works')->select(['id', 'composition_date_from', 'composition_date_to'])
            ->where(fn ($q) => $q->whereNotNull('composition_date_from')->orWhereNotNull('composition_date_to'))->orderBy('id')->lazyById(20000) as $w) {
            $engine->seed(DateVar::of(DateVar::COMPOSITION, $w->id), $int($w->composition_date_from), $int($w->composition_date_to));
        }

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
            $engine->constrain($work, $death, 0, $rule);
            $engine->constrain($birth, $work, -$age, $rule);
        }
        unset($ties, $translated);

        $copies = DB::table('manuscripts')->select(['id', 'work_id', 'copy_date_from', 'copy_date_to', 'copy_date_hedge_raw', 'is_autograph'])
            ->orderBy('id')->lazyById(20000);
        foreach ($copies as $m) {
            $copy = DateVar::of(DateVar::COPY, $m->id);
            if (! self::namesAuthorsTime($m->copy_date_hedge_raw)) {
                $engine->seed($copy, $int($m->copy_date_from), $int($m->copy_date_to));
            }
            if ($m->work_id === null) {
                continue;
            }
            $engine->constrain(DateVar::of(DateVar::COMPOSITION, $m->work_id), $copy, 0, self::RULE_COPY);
            if ($m->is_autograph && count($authors[$m->work_id] ?? []) === 1) {
                $engine->constrain($copy, DateVar::of(DateVar::DEATH, $authors[$m->work_id][0]), 0, self::RULE_AUTOGRAPH);
            }
        }
        unset($authors);

        foreach (DB::table('manuscript_people')->whereNotNull('person_id')->whereIn('role', ['scribe', 'patron'])
            ->select(['id', 'manuscript_id', 'person_id', 'role'])->orderBy('id')->lazyById(20000) as $t) {
            $copy = DateVar::of(DateVar::COPY, $t->manuscript_id);
            [$rule, $age] = $t->role === 'scribe' ? [self::RULE_SCRIBE, self::SCRIBE_AGE] : [self::RULE_PATRON, 0];
            $engine->constrain($copy, DateVar::of(DateVar::DEATH, $t->person_id), 0, $rule);
            $engine->constrain(DateVar::of(DateVar::BIRTH, $t->person_id), $copy, -$age, $rule);
        }

        foreach (DB::table('work_relations')->whereNotIn('type', ['related', 'part'])->orderBy('id')->get(['work_id', 'related_work_id']) as $r) {
            $engine->constrain(DateVar::of(DateVar::COMPOSITION, $r->related_work_id), DateVar::of(DateVar::COMPOSITION, $r->work_id), 0, self::RULE_DERIVED);
        }

        return $engine;
    }

    /** A copy date made from the author's death («در عصر مؤلف», «در حیات مؤلف», «در عصر تألیف»). */
    public static function namesAuthorsTime(?string $hedge): bool
    {
        return $hedge !== null && preg_match('/مؤلف|ت[أا]لیف/u', $hedge) === 1;
    }
}
