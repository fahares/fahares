<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\Source;
use Illuminate\Support\Facades\DB;

/**
 * Links the persons Noor tags in al-Dhari'a's text to the site's people (step 13.133, plan
 * docs/plans/dharia-entity-links.md, step 2): a person named (FB), a translator (MB), a co-author (AB), one a work is
 * put down to (ZB), one in a discussion of who is meant (DB).
 *
 * A mark links a person when it holds the whole of his name as the site writes it, and no other person's: his given
 * name with his father's («محمد باقر بن محمد تقي») and every word of his nisba («المجلسي»), or one of his other names
 * (aliases) of two words or more, two of them not common ones. The words are compared without spaces, «ال» and
 * the Arabic letters' variants, so «فخر الدين بن أبي القاسم الگلپايگاني» holds «فخرالدین بن ابی القاسم گلپایگانی».
 * A name with no nisba or no father («یزدی، ملا احمد» holds both; «شیخ بهایی» neither) needs an alias.
 * Saved are only the links his death year printed after the name confirms («المتوفى 1124»; ENABLED), or one of his works
 * on the site named in the marks right after it («الشيخ محمد بن يونس الشويهي في براهين العقول», «+work», step 13.157).
 */
class LinkDhariaPeopleCommand extends LinkDhariaTitlesCommand
{
    public const KINDS = ['FB', 'MB', 'AB', 'ZB', 'DB'];

    /**
     * The kinds and methods whose links are saved (see LinkDhariaTitlesCommand::ENABLED): a name with his death year
     * after it, about 120 of which a hand check found all right, and 26 of 26 more when the year is further after it
     * (step 13.157); a name with a work of his after it, 21 of 21. A name alone was right in 47 of 50 (FB), 43 of 50
     * (DB), 44 of 50 (MB): namesakes of other centuries, a father or son of the one meant. A year near the name within
     * his lifetime was right in 48 of 50 only (an alias «حجة الإسلام», a namesake), so it is not used.
     */
    public const ENABLED = ['FB' => self::DATED, 'MB' => self::DATED, 'AB' => self::DATED, 'ZB' => self::DATED, 'DB' => self::DATED];

    private const DATED = ['name+year', 'alias+year', 'name+work', 'alias+work'];

    /** Words too common to tell persons apart (as squash() writes them). */
    private const COMMON = ['بن', 'ابن', 'ابو', 'ابی', 'ابا', 'محمد', 'علی', 'حسن', 'حسین', 'احمد', 'خان', 'بیگ', 'عبد', 'دین', 'له',
        'جعفر', 'ابراهیم', 'باقر', 'تقی', 'مهدی', 'قاسم', 'محمود', 'یوسف', 'عباس', 'رضا', 'صالح', 'هادی', 'موسی', 'کاظم', 'اسماعیل'];

    /** Honorifics a name may carry or not (as squash() writes them). */
    private const HONORIFICS = ['شیخ', 'سید', 'مولی', 'ملا', 'میرزا', 'حاج', 'امیر', 'میر', 'محقق', 'محدث', 'فاضل', 'اخوند',
        'اقا', 'سیده', 'مولانا', 'شریف'];

    /** Given names that end as a nisba does («…ی»), not taken for one. */
    private const GIVEN = ['علی', 'مهدی', 'تقی', 'نقی', 'هادی', 'موسی', 'عیسی', 'یحیی', 'مرتضی', 'مصطفی', 'رضی', 'زکی', 'ولی', 'غنی',
        'قلی', 'بیگی', 'عسکری', 'مجتبی', 'مرسی', 'صفی', 'وصی', 'خانی'];

    protected $signature = 'sources:link-dharia-people
        {--kinds= : Comma-separated Noor kinds to link (default FB,MB,AB,ZB,DB)}
        {--volume= : Only this volume}
        {--sample= : Write a random sample of the links of each kind, with their context, to this file}
        {--per-kind=50 : Links of each kind in the sample}
        {--all : Save the links of every method, not only the ENABLED ones (for measuring)}
        {--dry-run : Match and count without saving}';

    protected $description = "Link the persons tagged in al-Dhari'a's text to the site's people";

    public function handle(): int
    {
        $source = Source::where('code', 'dharia')->firstOrFail();
        $kinds = $this->option('kinds') ? array_map('trim', explode(',', $this->option('kinds'))) : self::KINDS;
        if (array_diff($kinds, self::KINDS)) {
            $this->error('Kinds are some of ' . implode(',', self::KINDS));

            return self::FAILURE;
        }

        // each person's name parts, found by a word of them («key»): the last word of his nisba, or of his alias
        $byKey = [];
        foreach (Person::query()->get(['id', 'name', 'aliases', 'death_year_hijri']) as $person) {
            foreach (self::forms($person) as [$method, $parts]) {
                $run = end($parts);
                $byKey[end($run)][] = ['person' => $person, 'method' => $method, 'parts' => $parts];
            }
        }

        $pages = DB::table('source_pages')->join('source_volumes', 'source_volumes.id', '=', 'source_pages.source_volume_id')
            ->where('source_volumes.source_id', $source->id)
            ->when($this->option('volume'), fn ($q, $v) => $q->where('source_volumes.volume_number', $v))
            ->orderBy('source_volumes.volume_number')->orderBy('source_pages.page')
            ->select('source_pages.id', 'source_pages.page', 'source_pages.text', 'source_volumes.volume_number');

        $links = [];
        $count = [];
        // each author's works on the site, by title key, for the name with a work of his after it
        $works = [];
        foreach (DB::table('works')->whereNotNull('author_id')->get(['author_id', 'primary_title']) as $work) {
            foreach (self::keys((string) $work->primary_title) as $key) {
                $works[$work->author_id][$key] = true;
            }
        }

        $pages->chunk(500, function ($chunk) use ($kinds, $byKey, $works, &$links, &$count) {
            foreach ($chunk as $page) {
                $marks = self::marksOf($page->text);
                foreach ($marks as $n => $mark) {
                    if (! in_array($mark['kind'], $kinds, true)) {
                        continue;
                    }
                    [$person, $method] = self::person($mark['text'], $byKey, $mark['after']);
                    if ($person && in_array($method, ['name', 'alias'], true) && self::hisWork($marks, $n, $works[$person->id] ?? [])) {
                        $method .= '+work';
                    }
                    $count[$mark['kind']][$method] = ($count[$mark['kind']][$method] ?? 0) + 1;
                    if ($person) {
                        $links[] = ['page' => $page, 'mark' => $mark, 'item' => $person, 'method' => $method];
                    }
                }
            }
        });

        $rows = [];
        foreach ($count as $kind => $methods) {
            arsort($methods);
            $rows[] = [$kind, array_sum($methods), implode(', ', array_map(fn ($m, $n) => "$m $n", array_keys($methods), $methods))];
        }
        $this->table(['kind', 'marks', 'by method'], $rows);
        $this->info(count($links) . ' links');

        if ($this->option('sample')) {
            $this->samplePeople($links, (int) $this->option('per-kind'));
        }
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        if (! $this->option('all')) {
            $links = array_values(array_filter($links, fn ($l) => in_array($l['method'], static::ENABLED[$l['mark']['kind']] ?? [], true)));
        }
        $this->save($links, $kinds, Person::class);

        return self::SUCCESS;
    }

    /**
     * The ways a person's name may be held, each as the runs of words a mark must all hold (the last word of the last
     * run a key of his): «name», his given name with his father's and each word of his nisba, two of all those words not
     * common ones («محمد بن حسن استرآبادی» is too common to say whom); «alias», one of his other names as a whole, of two
     * words or more, two not common, and not his given name and nisba only («عبدالله یزدی» says no more than that).
     *
     * @return list<array{0: string, 1: list<list<string>>}>
     */
    public static function forms(Person $person): array
    {
        $forms = [];
        $name = self::plain($person->name);
        $nameWords = [];
        if (str_contains($name, '،')) {
            [$nisba, $given] = array_map('trim', explode('،', $name, 2));
            [$given, $nisba] = [self::words($given), array_values(array_filter(self::words($nisba), fn ($w) => mb_strlen($w) > 2))];
        } elseif (preg_match('/^(.+?\sبن\s.+)\s(\S{3,}ی)$/u', $name, $m) && ! in_array($m[2], self::GIVEN, true)) {
            // «فخرالدین بن ابی القاسم گلپایگانی»: the last word the nisba, when it is one («…ی», not «علی» or «مهدی»)
            [$given, $nisba] = [self::words($m[1]), self::words($m[2])];
        } else {
            [$given, $nisba] = [self::words($name), []];
        }
        $nameWords = [...$given, ...$nisba];
        if ($nisba && in_array('بن', $given, true) && count(self::strong($nameWords)) >= 2) {
            $forms[] = ['name', [$given, ...array_map(fn ($w) => [$w], $nisba)]];
        }
        foreach ($person->aliases ?? [] as $alias) {
            $words = self::words((string) $alias);
            if (count($words) >= 2 && count(self::strong($words)) >= 2
                && (in_array('بن', $words, true) || array_diff($words, $nameWords))) {
                $forms[] = ['alias', [$words]];
            }
        }

        return $forms;
    }

    /**
     * The one person whose name the mark holds and how, or null and why not. A year printed right after the name that
     * is past his death («( 1272 - 1354 )» for one who died in 1250) says it is another.
     *
     * @return array{0: ?Person, 1: string}
     */
    public static function person(string $marked, array $byKey, string $after = ''): array
    {
        // the mark's words, each without «ال» and honorifics, between bars: «|فخر|دین|بن|ابی|قاسم|گلپایگانی|»
        $words = self::words($marked);
        if (! $words) {
            return [null, 'empty'];
        }
        $text = '|' . implode('|', $words) . '|';
        $found = [];
        $keys = array_unique([...$words, ...array_map(fn ($w) => preg_replace('/^[لبو]/u', '', $w), $words)]);
        foreach ($keys as $word) {
            foreach ($byKey[$word] ?? [] as $form) {
                if (collect($form['parts'])->every(fn ($run, $i) => preg_match(self::pattern($run, $i === 0), $text))) {
                    $found[$form['person']->id] = $form;
                }
            }
        }
        if (! $found) {
            return [null, 'none'];
        }
        if (count($found) > 1) {
            return [null, 'several'];
        }
        $form = reset($found);
        $death = $form['person']->death_year_hijri;
        preg_match_all('/(?<!\d)(\d{3,4})(?!\d)/', mb_substr(MatchCatalogEditionCommand::digits($after), 0, 40), $years);
        $years = array_map('intval', $years[1]);
        if ($death && collect($years)->contains(fn ($y) => $y > $death + 2 && $y < 1500)) {
            return [null, 'year-differs'];
        }
        // his death year after the name («المتوفى 935», «( 1272 - 1354 )», «… و المتوفى سنة 1309»): a second anchor
        preg_match_all('/(?<!\d)(\d{3,4})(?!\d)/', MatchCatalogEditionCommand::digits($after), $further);
        $dated = $death && collect($further[1])->contains(fn ($y) => abs((int) $y - $death) <= 1);

        return [$form['person'], $form['method'] . ($dated ? '+year' : '')];
    }

    /** Whether a title in the next three marks is one of $works (title keys of his works). */
    public static function hisWork(array $marks, int $n, array $works): bool
    {
        if (! $works) {
            return false;
        }
        foreach (array_slice($marks, $n + 1, 3) as $other) {
            if (in_array($other['kind'], ['WB', 'TB', 'VB', 'EB'], true)) {
                $title = preg_replace('/^[\s()\[\]«»"\'،,.:؛]+|[\s()\[\]«»"\'،,.:؛]+$/u', '', $other['text']);
                $keys = self::keys($title) ?: (preg_match('/^[بلك]\x{200D}?\s*(\p{L}.{2,})$/u', $title, $m) ? self::keys($m[1]) : []);
                if (array_intersect_key($works, array_flip($keys))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A run of a name's words as whole words of the mark, the words' joins free («فخرالدین» is «فخر|دین»), the first
     * maybe with the Arabic «ل»/«ب»/«و» in front; the given name (the first run) not after «بن», so that «حمزه بن
     * محمد» is not found in «حمزة بن حمزة بن محمد», where he is the father.
     */
    private static function pattern(array $run, bool $first): string
    {
        static $patterns = [];
        $joined = implode('', $run);

        return $patterns[$joined . ($first ? '1' : '0')] ??= '/' . ($first ? '(?<!\|بن)' : '') . '\|[لبو]?'
            . implode('\|?', array_map(fn ($c) => preg_quote($c, '/'), mb_str_split($joined))) . '(?=\|)/u';
    }

    /** A name's words, each without «ال» (squash()), honorifics («الشيخ», «السيد», «المولى» …) left out. */
    public static function words(string $name): array
    {
        return array_values(array_filter(array_map(fn ($w) => self::squash($w), explode(' ', str_replace('،', ' ', self::plain($name)))),
            fn ($w) => $w !== '' && ! in_array($w, self::HONORIFICS, true)));
    }

    /** The words that are not common ones. */
    private static function strong(array $words): array
    {
        return array_filter($words, fn ($w) => mb_strlen($w) > 2 && ! in_array($w, self::COMMON, true));
    }

    /** Arabic and Persian letters made one, diacritics, ZWNJ and kashida dropped, punctuation a space. */
    public static function plain(string $s): string
    {
        $s = strtr($s, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ی',
            "\u{200C}" => '', "\u{200D}" => '', 'ـ' => '', ',' => '،']);
        $s = preg_replace('/\p{M}/u', '', $s);
        $s = preg_replace('/[^\p{L}\s،]/u', ' ', $s);

        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** The words without spaces and every «ال», for comparing «فخر الدين» with «فخرالدین» and «الگلپايگاني» with «گلپایگانی». */
    public static function squash(string $s): string
    {
        return str_replace([' ', '،', 'ال'], '', self::plain($s));
    }

    private function samplePeople(array $links, int $perKind): void
    {
        $out = [];
        foreach (collect($links)->groupBy(fn ($l) => $l['mark']['kind'] . ' ' . $l['method']) as $group => $items) {
            $out[] = "===== {$group} ({$items->count()} links) =====";
            $picked = $items->count() <= $perKind ? $items : $items->shuffle(13133)->take($perKind);
            foreach ($picked->values() as $n => $l) {
                $m = $l['mark'];
                $p = $l['item'];
                $out[] = sprintf("%s-%d ج %d ص %d: …%s [[%s]] %s…\n    → %s%s%s",
                    str_replace(' ', ':', $group), $n + 1, $l['page']->volume_number, $l['page']->page,
                    trim(preg_replace('/\s+/u', ' ', $m['before'])), $m['text'], trim(preg_replace('/\s+/u', ' ', $m['after'])),
                    $p->name, $p->death_year_hijri ? " (م {$p->death_year_hijri})" : '', $p->aliases ? ' — ' . implode('؛ ', $p->aliases) : '');
            }
        }
        file_put_contents($this->option('sample'), implode("\n", $out) . "\n");
        $this->info('Sample written to ' . $this->option('sample'));
    }
}
