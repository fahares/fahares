<?php

namespace App\Services;

use App\Helpers\TextNormalizer;
use App\Models\ManuscriptPerson;
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
        $copyYears = self::copyYears();

        $groups = [];
        foreach (self::people($copyYears) as $member) {
            $k = self::key($member['name']);
            if (mb_strlen($k) < 4) {
                continue;
            }
            $groups[$k][] = $member;
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

    /** The span of copy years of each scribe record */
    protected static function copyYears(): \Illuminate\Support\Collection
    {
        return DB::table('manuscript_people as s')->where('s.role', ManuscriptPerson::SCRIBE)
            ->join('manuscripts as m', 'm.id', '=', 's.manuscript_id')
            ->whereNotNull('s.person_id')->whereNotNull('m.copy_date_hijri_year')
            ->groupBy('s.person_id')
            ->selectRaw('s.person_id, MIN(m.copy_date_hijri_year) lo, MAX(m.copy_date_hijri_year) hi')
            ->get()->keyBy('person_id');
    }

    /** Every person record as a group member, with its other names */
    protected static function people($copyYears): \Generator
    {
        $people = DB::table('people')->select('id', 'name', 'aliases', 'death_year_hijri', 'century_hijri', 'works_count', 'manuscripts_count')->cursor();
        foreach ($people as $p) {
            $years = $copyYears[$p->id] ?? null;
            yield [
                'id' => (int) $p->id,
                'name' => $p->name,
                'death' => $p->death_year_hijri ? (int) $p->death_year_hijri : null,
                'century' => $p->century_hijri ? (int) $p->century_hijri : null,
                'copies' => $years ? [(int) $years->lo, (int) $years->hi] : null,
                'works' => (int) $p->works_count,
                'manuscripts' => (int) $p->manuscripts_count,
                'aliases' => array_values(array_filter((array) json_decode((string) $p->aliases, true), 'is_string')),
            ];
        }
    }

    /** Words of address and rank a catalogue writes before a name or before the father's name («حاج», «ملا»). */
    public const TITLES = ['ایه الله', 'ایت الله', 'ایه الله العظمی', 'ایت الله العظمی', 'علامه', 'حاج', 'حاجی', 'اقا', 'میرزا', 'شیخ', 'ملا', 'اخوند',
        'مولی', 'مولانا', 'جناب', 'سرکار', 'مرحوم', 'استاد', 'حضرت'];

    /**
     * The key without the words of address (step 14.121): «حاج علی بن ملا محمد قاسم خوانساری» and «خوانساری، علی بن
     * محمد قاسم» share it. «سید» and «میر» stay, as they tell a sayyid apart.
     */
    public static function looseKey(string $name): string
    {
        $n = TextNormalizer::normalize($name);
        $n = preg_replace('/\([^)]*\)|«[^»]*»/u', ' ', $n);
        if (preg_match('/^([^،,]+)[،,]\s*(.+)$/u', $n, $m)) {
            $n = $m[2] . ' ' . $m[1];
        }
        $n = ' ' . preg_replace('/[،,.:؛\-–؟?!\[\]]/u', ' ', $n) . ' ';
        foreach (array_map(fn ($t) => TextNormalizer::normalize($t), self::TITLES) as $title) {
            $n = str_replace(" {$title} ", ' ', preg_replace('/\s+/u', ' ', $n));
            $n = str_replace(" {$title} ", ' ', $n);
        }

        return implode('', self::tokens(trim($n)));
    }

    /**
     * Groups the exact key leaves apart that are clearly one person (step 14.121): records whose names are the same
     * but for the words of address, or a record named as another record's other name. Only groups of the «likely»
     * tier whose names are not generic (two distinctive words, or a «X بن Y» name with one) and whose copies lie
     * within 40 years; a record in two such groups is left out of both.
     *
     * @return array{stats: array<string, int>, groups: array<int, array>}
     */
    public static function looseGroups(): array
    {
        $members = [];
        $byKey = [];
        $byAlias = [];
        foreach (self::people(self::copyYears()) as $member) {
            $members[$member['id']] = $member;
            $k = self::looseKey($member['name']);
            if (mb_strlen($k) >= 6) {
                $byKey[$k][] = $member['id'];
            }
            foreach ($member['aliases'] as $alias) {
                $ak = self::looseKey($alias);
                if (mb_strlen($ak) >= 6) {
                    $byAlias[$ak][$member['id']] = $alias;
                }
            }
        }

        $candidates = [];
        foreach ($byKey as $k => $ids) {
            if (count($ids) > 1) {
                $candidates[] = [$k, $ids, 'same name but for titles', []];
            }
        }
        foreach ($byAlias as $k => $owners) {
            // The other name of one record only, written as the name of others
            $others = array_diff($byKey[$k] ?? [], array_keys($owners));
            if (count($owners) === 1 && $others) {
                $candidates[] = [$k, array_merge(array_keys($owners), $others), 'another record\'s other name', $owners];
            }
        }

        $stats = ['clear' => 0, 'unclear' => 0, 'overlap' => 0];
        $groups = [];
        foreach ($candidates as [$k, $ids, $how, $named]) {
            sort($ids);
            $set = implode(',', $ids);
            $group = array_map(fn ($id) => array_diff_key($members[$id], ['aliases' => true]), $ids);
            // The exact key already reports it
            if (count(array_unique(array_map(fn ($m) => self::key($m['name']), $group))) < 2 || isset($groups[$set])) {
                continue;
            }
            [$tier, $reason] = self::tier($group);
            // A record found by its other name is judged by that name
            $asNamed = array_map(fn ($m) => isset($named[$m['id']]) ? ['name' => $named[$m['id']]] + $m : $m, $group);
            if ($tier !== 'likely' || ! self::clear($asNamed)) {
                $stats['unclear']++;

                continue;
            }
            $groups[$set] = ['key' => $k, 'tier' => 'clear', 'reason' => "{$how}; {$reason}", 'target_id' => self::target($group), 'members' => $group];
        }

        $in = [];
        foreach ($groups as $set => $group) {
            foreach ($group['members'] as $m) {
                $in[$m['id']][] = $set;
            }
        }
        foreach (array_filter($in, fn ($sets) => count($sets) > 1) as $sets) {
            foreach ($sets as $set) {
                if (isset($groups[$set])) {
                    unset($groups[$set]);
                    $stats['overlap']++;
                }
            }
        }
        $stats['clear'] = count($groups);

        return ['stats' => $stats, 'groups' => array_values($groups)];
    }

    /** Not a generic name, and copies within 40 years */
    protected static function clear(array $group): bool
    {
        $distinct = min(array_map(fn ($m) => self::distinctive($m['name']), $group));
        $withFather = min(array_map(fn ($m) => preg_match('/(^|\s)(بن|ابن)\s/u', TextNormalizer::normalize($m['name'])) ? 1 : 0, $group));
        $words = min(array_map(fn ($m) => count(array_filter(preg_split('/[\s،]+/u', self::looseWords($m['name'])), fn ($w) => $w !== '' && $w !== 'بن')), $group));
        $spans = array_values(array_filter(array_column($group, 'copies')));
        $spread = $spans ? max(array_column($spans, 1)) - min(array_column($spans, 0)) : 0;

        return ($distinct >= 2 || ($withFather && $distinct >= 1 && $words >= 4)) && $spread <= 40;
    }

    /** The name's words without the words of address, separated */
    protected static function looseWords(string $name): string
    {
        $n = ' ' . preg_replace(['/\([^)]*\)|«[^»]*»/u', '/[،,.:؛\-–؟?!\[\]]/u'], ' ', TextNormalizer::normalize($name)) . ' ';
        foreach (array_map(fn ($t) => TextNormalizer::normalize($t), self::TITLES) as $title) {
            $n = str_replace(" {$title} ", ' ', preg_replace('/\s+/u', ' ', $n));
            $n = str_replace(" {$title} ", ' ', $n);
        }

        return trim(preg_replace(['/(^|\s)ابن(\s|$)/u', '/\s+/u'], [' بن ', ' '], $n));
    }
}
