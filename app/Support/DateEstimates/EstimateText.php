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

    private const ROUTES = ['person' => 'people.show', 'work' => 'works.show', 'manuscript' => 'manuscripts.show'];

    /** The person's part in the work, by the rule that tied them. */
    private const PEOPLE = [Loader::RULE_AUTHOR => 'مؤلف', Loader::RULE_TRANSLATOR => 'مترجم', Loader::RULE_DEDICATEE => 'مُهدی‌الیه'];

    /**
     * @return array{text: string, reasons: list<array{bound: string, text: string, title: ?string, url: ?string}>}|null
     */
    public static function for(string $type, int $id, string $field): ?array
    {
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

    /** Why the bound holds; the record it names follows the text. A depth over one rests on another estimate. */
    private static function reason(string $side, ?string $rule, int $depth): string
    {
        $sure = $depth <= 1;
        if ($rule === null) {
            return 'تاریخ ثبت‌شده در فهرست';
        }
        if ($rule === Loader::RULE_COPY) {
            return $sure ? 'کهن‌ترین نسخه‌ی تاریخ‌دار اثر در این سال کتابت شده است:' : 'نسخه‌ای از اثر به تخمین تا این سال کتابت شده است:';
        }
        if ($rule === Loader::RULE_DERIVED) {
            return match (true) {
                $side === 'from' && $sure => 'اثری که این اثر بر پایه‌ی آن است در این سال تألیف شده است:',
                $side === 'from' => 'اثری که این اثر بر پایه‌ی آن است به تخمین پس از این سال تألیف شده است:',
                $sure => 'اثری که بر پایه‌ی این اثر است در این سال تألیف شده است:',
                default => 'اثری که بر پایه‌ی این اثر است به تخمین پیش از این سال تألیف شده است:',
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
