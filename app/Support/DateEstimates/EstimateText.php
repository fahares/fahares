<?php

namespace App\Support\DateEstimates;

use Illuminate\Support\Facades\DB;

/**
 * An estimate of date_estimates as a reader sees it (step 14.170): the range in words, and for each bound the reason
 * with a link to the record it rests on.
 *
 *   - both bounds, at most MAX_SPAN years apart: «میان ۱۱۸۵ و ۱۲۴۰ ق»;
 *   - both bounds, wider: only the bound nearer the documents (the lower depth; the later bound on a tie), since a
 *     range of two centuries says little;
 *   - one bound: «پس از ۱۱۸۵ ق» or «پیش از ۱۲۴۰ ق».
 *
 * «پس از» and «پیش از» include the year itself: a work may be composed in the year of its oldest copy.
 */
final class EstimateText
{
    public const MAX_SPAN = 150;

    /**
     * The year a work is ordered by when the reader asks for estimates too (step 14.197): its year of composition,
     * else the middle of its estimate. The same number is composition_sort_year in the search index.
     */
    public const COMPOSITION_SORT_SQL = "COALESCE(works.composition_year_hijri, (SELECT FLOOR((year_from + year_to) / 2) FROM date_estimates"
        . " WHERE subject_type = 'work' AND subject_id = works.id AND field = 'composition' AND year_from IS NOT NULL AND year_to IS NOT NULL"
        . ' AND year_to - year_from <= ' . self::MAX_SPAN . '))';

    /** Works that get no estimated date of composition. */
    private const UNDATED_TITLES = ['القرآن الکریم', 'قرآن کریم'];

    private const ROUTES = ['person' => 'people.show', 'work' => 'works.show', 'manuscript' => 'manuscripts.show'];

    /** The person's part in the work, by the rule that tied them. */
    private const PEOPLE = [Loader::RULE_AUTHOR => 'مؤلف', Loader::RULE_TRANSLATOR => 'مترجم', Loader::RULE_DEDICATEE => 'مُهدی‌الیه'];

    /**
     * @return array{text: string, reasons: list<array{bound: string, text: string, title: ?string, url: ?string}>}|null
     */
    /**
     * The estimates of composition that place a work in time: both bounds, at most MAX_SPAN years apart, the ones the
     * work's page shows as «میان … و …». A list filters and orders by these alone: «پیش از ۱۲۰۰» names no century.
     */
    public static function placedCompositions(\Illuminate\Database\Query\Builder $query): \Illuminate\Database\Query\Builder
    {
        return $query->from('date_estimates')->where('date_estimates.subject_type', 'work')->where('date_estimates.field', 'composition')
            ->whereNotNull('date_estimates.year_from')->whereNotNull('date_estimates.year_to')
            ->whereRaw('date_estimates.year_to - date_estimates.year_from <= ' . self::MAX_SPAN);
    }

    public static function for(string $type, int $id, string $field): ?array
    {
        // the Quran's date is not a question the copies answer (the user, step 14.176)
        if ($type === 'work' && in_array(trim(strtr((string) DB::table('works')->where('id', $id)->value('primary_title'), ['ي' => 'ی', 'ك' => 'ک'])), self::UNDATED_TITLES, true)) {
            return null;
        }
        $row = DB::table('date_estimates')->where(['subject_type' => $type, 'subject_id' => $id, 'field' => $field])->first();
        if (! $row || ($row->year_from === null && $row->year_to === null)) {
            return null;
        }
        $sides = ['from', 'to'];
        if ($row->year_from === null || $row->year_to === null) {
            $sides = [$row->year_from === null ? 'to' : 'from'];
        } elseif ($row->year_to - $row->year_from > self::MAX_SPAN) {
            $sides = [($row->from_depth ?? 0) < ($row->to_depth ?? 0) ? 'from' : 'to'];
        }
        $text = count($sides) === 2
            ? ($row->year_from === $row->year_to ? "{$row->year_from} ق" : "میان {$row->year_from} و {$row->year_to} ق")
            : ($sides[0] === 'from' ? "پس از {$row->year_from} ق" : "پیش از {$row->year_to} ق");

        $reasons = [];
        foreach ($sides as $side) {
            $evidence = self::evidence($row->{"{$side}_evidence_type"}, $row->{"{$side}_evidence_id"});
            $reasons[] = [
                'bound' => ($side === 'from' ? 'پس از ' : 'پیش از ') . $row->{"year_{$side}"},
                'text' => self::reason($side, $row->{"{$side}_rule"}, (int) $row->{"{$side}_depth"}),
                'title' => $evidence['title'] ?? null,
                'url' => $evidence ? route(self::ROUTES[$row->{"{$side}_evidence_type"}], $row->{"{$side}_evidence_id"}) : null,
            ];
        }

        return ['text' => $text, 'reasons' => $reasons];
    }

    /**
     * «زنده در ۱۲۰۰ ق» for a person with no death date (step 14.172): the latest year a documented date shows them
     * alive, one rule away, so it rests on no other estimate. The length of a life gives no such year, and the range
     * of the death («… to that year + 100») is not shown: it says no more.
     *
     * When the evidence is a range (a copy dated by its century), the person was alive at some time in it, so the
     * text is «وفات پس از <its first year>». When the range is a duration with exact ends (DateSpan::SPAN, a copy
     * written from 1052 to 1057; step 14.194), a scribe, an author or a translator was alive at its end, so the text
     * is «زنده در 1057», and a patron at its start, «زنده در 1052»; of a dedicatee the range says no more than before.
     * Nothing is said of a scribe whose copies lie more than
     * PersonReviewQueue::MIXED_SPAN years apart: that record holds several namesakes, and the year is only the last
     * one's.
     *
     * @return array{text: string, reasons: list<array{bound: string, text: string, title: ?string, url: ?string}>}|null
     */
    /**
     * The year aliveIn() names, as an expression over a row of `people` (step 14.198): the earliest the person's death
     * can be, one rule from a documented date, by a rule of ALIVE, and not the last copy of a record of several
     * namesake scribes. The lists filter and order by it; the same number is in the people's search documents.
     */
    public const ALIVE_YEAR_SQL = "(SELECT e.year_from FROM date_estimates e WHERE e.subject_type = 'person' AND e.subject_id = people.id AND e.field = 'death'"
        . " AND e.year_from IS NOT NULL AND e.from_depth = 1 AND e.from_rule IN ('author', 'translator', 'dedicatee', 'scribe', 'autograph', 'patron')"
        . " AND NOT (e.from_rule = 'scribe' AND people.scribed_from IS NOT NULL AND people.scribed_to - people.scribed_from > " . \App\Services\PersonReviewQueue::MIXED_SPAN . '))';

    /** The year a person with no death year was last seen alive, or null (ALIVE_YEAR_SQL for one person). */
    public static function aliveYear(int $personId): ?int
    {
        $year = DB::table('people')->where('id', $personId)->whereNull('death_year_hijri')->selectRaw(self::ALIVE_YEAR_SQL . ' AS alive_year')->value('alive_year');

        return $year === null ? null : (int) $year;
    }

    public static function aliveIn(int $personId): ?array
    {
        $row = DB::table('date_estimates')->where(['subject_type' => 'person', 'subject_id' => $personId, 'field' => 'death'])->first();
        if (! $row || $row->year_from === null || (int) $row->from_depth !== 1 || ! isset(self::ALIVE[$row->from_rule])) {
            return null;
        }
        if ($row->from_rule === Loader::RULE_SCRIBE) {
            $span = DB::table('people')->where('id', $personId)->first(['scribed_from', 'scribed_to']);
            if ($span && $span->scribed_from && $span->scribed_to - $span->scribed_from > \App\Services\PersonReviewQueue::MIXED_SPAN) {
                return null;
            }
        }
        $evidence = self::evidence($row->from_evidence_type, $row->from_evidence_id);
        [$table, $prefix] = $row->from_evidence_type === 'work' ? ['works', 'composition_date'] : ['manuscripts', 'copy_date'];
        $record = DB::table($table)->where('id', $row->from_evidence_id)
            ->first(["{$prefix}_from", "{$prefix}_to", "{$prefix}_kind", "{$prefix}_start_to", "{$prefix}_end_from"]);
        $date = $record ? \App\Support\DateSpan::fromColumns($record, $prefix) : null;
        $exact = $date && $date->from !== null && $date->from === $date->to;
        $year = $row->year_from;
        $lasted = false;
        // a duration with exact ends: alive at its end, or for a patron at its start
        // (the engine gives the end itself since step 14.222; an estimate made before it gives the start)
        if ($date && $date->isSpan() && $date->startTo === null && $date->endFrom === null && $date->from !== null && $date->to !== null
            && $row->from_rule !== Loader::RULE_DEDICATEE && in_array((int) $row->year_from, [$date->from, $date->to], true)) {
            $year = $row->from_rule === Loader::RULE_PATRON ? $date->from : $date->to;
            $lasted = true;
        }
        $known = $exact || $lasted;

        return [
            'text' => ($known ? 'زنده در ' : 'وفات پس از ') . $year . ' ق',
            'reasons' => [[
                'bound' => ($known ? 'زنده در ' : 'پس از ') . $year,
                'text' => $lasted ? self::ALIVE_SPAN[$row->from_rule] : self::ALIVE[$row->from_rule][$exact ? 0 : 1],
                'title' => $evidence['title'] ?? null,
                'url' => $evidence ? route(self::ROUTES[$row->from_evidence_type], $row->from_evidence_id) : null,
            ]],
        ];
    }

    /** What shows the person alive when the evidence lasted from one year to another. */
    private const ALIVE_SPAN = [
        Loader::RULE_AUTHOR => 'اثرش را تا این سال تألیف می‌کرده است:',
        Loader::RULE_TRANSLATOR => 'ترجمه‌اش را تا این سال می‌نوشته است:',
        Loader::RULE_SCRIBE => 'کتابت نسخه‌ای را در این سال به پایان برده است:',
        Loader::RULE_AUTOGRAPH => 'نسخه‌ای به خط خودش در این سال به پایان رسیده است:',
        Loader::RULE_PATRON => 'کتابت نسخه‌ای برای او در این سال آغاز شده است:',
    ];

    /** What shows the person alive, by rule: when the evidence is one year, and when it is a range. */
    private const ALIVE = [
        Loader::RULE_AUTHOR => ['اثرش را در این سال تألیف کرده است:', 'اثرش را زودتر از این سال تألیف نکرده است:'],
        Loader::RULE_TRANSLATOR => ['ترجمه‌اش را در این سال نوشته است:', 'ترجمه‌اش را زودتر از این سال ننوشته است:'],
        Loader::RULE_DEDICATEE => ['اثری در این سال به او اهدا شده است:', 'اثری که به او اهدا شده زودتر از این سال تألیف نشده است:'],
        Loader::RULE_SCRIBE => ['نسخه‌ای را در این سال کتابت کرده است:', 'نسخه‌ای را زودتر از این سال کتابت نکرده است:'],
        Loader::RULE_AUTOGRAPH => ['نسخه‌ای به خط خودش در این سال کتابت شده است:', 'نسخه‌ای به خط خودش زودتر از این سال کتابت نشده است:'],
        Loader::RULE_PATRON => ['نسخه‌ای در این سال برای او کتابت شده است:', 'نسخه‌ای که برای او کتابت شده زودتر از این سال نیست:'],
    ];

    /** Why the bound holds; the record it names follows the text. A depth over one rests on another estimate. */
    private static function reason(string $side, ?string $rule, int $depth): string
    {
        $sure = $depth <= 1;
        if ($rule === null) {
            return 'تاریخ ثبت‌شده در فهرست';
        }
        if ($rule === Loader::RULE_COPY) {
            return $sure ? 'کهن‌ترین نسخۀ تاریخ‌دار اثر در این سال کتابت شده است:' : 'نسخه‌ای از اثر به تخمین تا این سال کتابت شده است:';
        }
        if ($rule === Loader::RULE_DERIVED) {
            return match (true) {
                $side === 'from' && $sure => 'اثری که این اثر بر پایۀ آن است در این سال تألیف شده است:',
                $side === 'from' => 'اثری که این اثر بر پایۀ آن است به تخمین پس از این سال تألیف شده است:',
                $sure => 'اثری که بر پایۀ این اثر است در این سال تألیف شده است:',
                default => 'اثری که بر پایۀ این اثر است به تخمین پیش از این سال تألیف شده است:',
            };
        }
        $who = self::PEOPLE[$rule] ?? 'شخص';
        if ($side === 'to') {
            return $sure ? "{$who} در این سال درگذشته است:" : "{$who} به تخمین تا این سال زنده بوده است:";
        }
        if ($rule === Loader::RULE_DEDICATEE) {
            return $sure ? "{$who} در این سال به دنیا آمده است:" : "{$who} به تخمین پیش از این سال به دنیا نیامده است:";
        }
        $age = Loader::AUTHOR_AGE;

        return $sure ? "{$who} در این سال {$age} ساله بوده است:" : "{$who} به تخمین پیش از این سال {$age} ساله نبوده است:";
    }

    private static function evidence(?string $type, ?int $id): ?array
    {
        if (! $type || ! $id) {
            return null;
        }
        if ($type === 'person') {
            $name = DB::table('people')->where('id', $id)->value('name');

            return $name ? ['title' => $name] : null;
        }
        if ($type === 'work') {
            $title = DB::table('works')->where('id', $id)->value('primary_title');

            return $title ? ['title' => $title] : null;
        }
        $copy = DB::table('manuscripts')->where('id', $id)->first(['city', 'library', 'shelfmark', 'copy_date_raw']);

        return $copy ? ['title' => implode('، ', array_filter([$copy->city, $copy->library, $copy->shelfmark ? 'ش ' . $copy->shelfmark : null, $copy->copy_date_raw]))] : null;
    }
}
