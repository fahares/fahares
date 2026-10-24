<?php

namespace App\Services;

use App\Helpers\TextNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Finds person records that are probably the same person written differently: spacing
 * («عبد المجید»/«عبدالمجید»), the article («الطریحی»/«طریحی»), «ابن»/«بن», Arabic letter
 * forms, and the inverted catalog form («نیریزی، احمد» for «احمد نیریزی»). Read-only.
 *
 * Every group gets a tier from the dates the records carry (death year, century, and the copy
 * years of the manuscripts each one wrote):
 *   likely   — distinctive name and the dates agree, or there are none to compare;
 *   review   — a generic name («محمد بن حسین») or an author and a scribe with no dates to check;
 *   conflict — the dates rule it out (e.g. a copy dated centuries after an author's death).
 */
class DuplicatePeopleFinder
{
    /** Given names and titles that alone do not identify anyone */
    protected const COMMON = [
        'محمد', 'علی', 'حسن', 'حسین', 'احمد', 'محمود', 'ابراهیم', 'اسماعیل', 'اسمعیل', 'جعفر', 'باقر', 'صادق', 'کاظم', 'رضا', 'تقی', 'نقی',
        'مهدی', 'هادی', 'قاسم', 'ابوالقاسم', 'ابوالحسن', 'عبدالله', 'عبدالرحمن', 'عبدالرحیم', 'عبدالکریم', 'عبدالعلی', 'عبدالحسین',
        'عبدالوهاب', 'عبدالمجید', 'عبدالباقی', 'یوسف', 'یعقوب', 'موسی', 'عیسی', 'یحیی', 'داود', 'سلیمان', 'عباس', 'جلال', 'نصرالله',
        'فتح', 'الله', 'اکبر', 'اصغر', 'امین', 'شریف', 'شفیع', 'رحیم', 'کریم', 'مومن', 'صالح', 'طاهر', 'هاشم', 'زمان', 'جان', 'قلی',
        'سید', 'میرزا', 'حاجی', 'حاج', 'شیخ', 'ملا', 'آقا', 'اقا', 'میر', 'مولانا', 'مولی', 'خان', 'بیگ', 'بیک', 'درویش', 'کاتب', 'بن', 'بنت',
        'الدین', 'دین', 'زین', 'العابدین', 'عابدین', 'نور', 'شمس', 'کمال', 'جمال', 'نظام', 'غلام', 'حسینی', 'حسنی', 'موسوی', 'طباطبایی', 'طباطبائی',
    ];

    public static function key(string $name): string
    {
        $n = TextNormalizer::normalize($name);
        $n = preg_replace('/\([^)]*\)|«[^»]*»/u', ' ', $n);
        if (preg_match('/^([^،,]+)[،,]\s*(.+)$/u', $n, $m)) {
            $n = $m[2] . ' ' . $m[1];
        }
        $n = preg_replace('/[،,.:؛\-–؟?!\[\]]/u', ' ', $n);

        return implode('', self::tokens($n));
    }

    /** @return string[] */
    protected static function tokens(string $normalized): array
    {
        $tokens = array_values(array_filter(preg_split('/\s+/u', $normalized)));

        return array_map(fn($w) => $w === 'ابن' ? 'بن' : (mb_strlen($w) > 3 && str_starts_with($w, 'ال') ? mb_substr($w, 2) : $w), $tokens);
    }

    /** Tokens that say something about who the person is, beyond common given names and titles */
    protected static function distinctive(string $name): int
    {
        $n = preg_replace('/[،,.:؛\-–؟?!\[\]()«»]/u', ' ', TextNormalizer::normalize($name));
        $common = array_map(fn($w) => TextNormalizer::normalize($w), self::COMMON);

        return count(array_filter(self::tokens($n), fn($w) => ! in_array($w, $common, true) && mb_strlen($w) > 2));
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function tier(array $members): array
    {
        // A record whose own copies span generations already mixes namesakes; merging would add to it
        foreach ($members as $m) {
            if ($m['copies'] && $m['copies'][1] - $m['copies'][0] > 70) {
                return ['review', "record #{$m['id']} already mixes namesakes (copies {$m['copies'][0]}–{$m['copies'][1]})"];
            }
        }

        // Different details in parentheses («محمد مهدی (ابن محمد حسین)» / «محمد مهدی (ابن محمد باقر)») may tell two people apart
        $details = array_values(array_unique(array_filter(array_map(fn($m) => self::parenthetical($m['name']), $members))));
        if (count($details) > 1) {
            return ['review', 'different details in parentheses: ' . implode(' / ', $details)];
        }

        // A death year at the middle of a century (1050, 1250 …) was derived from «ق 11», «ق 13»: only the century is known
        $deaths = array_values(array_unique(array_filter(array_map(fn($m) => self::exactDeath($m), $members))));
        if (count($deaths) > 1 && max($deaths) - min($deaths) > 2) {
            return ['conflict', 'different death years: ' . implode(', ', $deaths)];
        }
        $centuries = array_values(array_unique(array_filter(array_column($members, 'century'))));
        if (count($centuries) > 1 && max($centuries) - min($centuries) > 1) {
            return ['conflict', 'centuries apart: ' . implode(', ', $centuries)];
        }

        $death = $deaths[0] ?? null;
        $century = $centuries[0] ?? null;
        $spans = array_values(array_filter(array_column($members, 'copies')));
        $lo = $spans ? min(array_column($spans, 0)) : null;
        $hi = $spans ? max(array_column($spans, 1)) : null;

        // Recent deaths are often given in the solar calendar, about 40 years behind the lunar copy dates
        $grace = $death && $death >= 1300 ? 50 : 5;
        if ($death && $spans && ($hi > $death + $grace || $lo < $death - 90)) {
            return ['conflict', "copies {$lo}–{$hi} against death {$death}"];
        }
        if (! $death && $century && $spans && (intdiv($hi, 100) + 1 > $century + 1 || intdiv($lo, 100) + 1 < $century - 1)) {
            return ['conflict', "copies {$lo}–{$hi} against century {$century}"];
        }
        if ($spans && $hi - $lo > 70) {
            return ['conflict', "copies span {$lo}–{$hi}"];
        }

        $evidence = (($death || $century) && $spans) || count($spans) > 1 || count(array_filter(array_map(fn($m) => self::exactDeath($m), $members))) > 1;
        $distinct = min(array_map(fn($m) => self::distinctive($m['name']), $members));

        if ($distinct === 0) {
            return ['review', 'generic name'];
        }
        $authorsOnly = array_filter($members, fn($m) => $m['works'] > 0 && $m['manuscripts'] === 0);
        $scribesOnly = array_filter($members, fn($m) => $m['works'] === 0 && $m['manuscripts'] > 0);
        if ($authorsOnly && $scribesOnly && ! $evidence) {
            return ['review', 'an author and a scribe with no dates to compare'];
        }

        return ['likely', $evidence ? 'dates agree' : 'same distinctive name, no dates'];
    }

    /** What a name says in parentheses, leaving out the catalog's doubt marks («؟», «کذا», «ظاهراً») */
    protected static function parenthetical(string $name): string
    {
        preg_match_all('/\(([^)]*)\)/u', TextNormalizer::normalize($name), $m);
        $text = trim(preg_replace('/[؟?]|کذا|ظاهرا|محتملا|احتمالا|\s+/u', ' ', implode(' ', $m[1])));

        return str_replace(' ', '', $text);
    }

    protected static function exactDeath(array $member): ?int
    {
        $death = $member['death'];

        return $death && ! ($death % 100 === 50 && $member['century'] && intdiv($death, 100) + 1 === $member['century']) ? $death : null;
    }

    /** The record to keep: the one with dates, then the most links, then the oldest */
    public static function target(array $members): int
    {
        usort($members, fn($a, $b) => [
            $b['death'] ? 1 : 0, $b['works'] + $b['manuscripts'], -$b['id'],
        ] <=> [
            $a['death'] ? 1 : 0, $a['works'] + $a['manuscripts'], -$a['id'],
        ]);

        return $members[0]['id'];
    }

    /**
     * Every group of two or more records sharing a key, with its tier, reason and suggested target.
     *
     * @return array{stats: array<string, int>, groups: array<int, array>}
     */
    public static function groups(): array
    {
        $copyYears = DB::table('manuscript_scribe as s')
            ->join('manuscripts as m', 'm.id', '=', 's.manuscript_id')
            ->whereNotNull('s.person_id')->whereNotNull('m.copy_date_hijri_year')
            ->groupBy('s.person_id')
            ->selectRaw('s.person_id, MIN(m.copy_date_hijri_year) lo, MAX(m.copy_date_hijri_year) hi')
            ->get()->keyBy('person_id');

        $groups = [];
        $people = DB::table('people')->select('id', 'name', 'aliases', 'death_year_hijri', 'century_hijri', 'works_count', 'manuscripts_count')->cursor();
        foreach ($people as $p) {
            $k = self::key($p->name);
            if (mb_strlen($k) < 4) {
                continue;
            }
            $years = $copyYears[$p->id] ?? null;
            $groups[$k][] = [
                'id' => (int) $p->id,
                'name' => $p->name,
                'death' => $p->death_year_hijri ? (int) $p->death_year_hijri : null,
                'century' => $p->century_hijri ? (int) $p->century_hijri : null,
                'copies' => $years ? [(int) $years->lo, (int) $years->hi] : null,
                'works' => (int) $p->works_count,
                'manuscripts' => (int) $p->manuscripts_count,
            ];
        }

        $report = [];
        $stats = ['likely' => 0, 'review' => 0, 'conflict' => 0];
        foreach ($groups as $k => $members) {
            if (count($members) < 2) {
                continue;
            }
            [$tier, $reason] = self::tier($members);
            $stats[$tier]++;
            $report[] = [
                'key' => $k,
                'tier' => $tier,
                'reason' => $reason,
                'target_id' => self::target($members),
                'members' => $members,
            ];
        }

        usort($report, fn($a, $b) => [$a['tier'], -count($a['members'])] <=> [$b['tier'], -count($b['members'])]);

        return ['stats' => $stats, 'groups' => $report];
    }
}
