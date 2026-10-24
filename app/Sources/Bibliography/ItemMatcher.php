<?php

namespace App\Sources\Bibliography;

use App\Console\Commands\MatchCatalogEditionCommand as Text;

/**
 * Tells which printed entry of a page a work is (plan docs/plans/dharia-text.md, link (a)): Fankha cites a work as
 * «الذریعة 402/3», and the page holds several entries. The work's titles are compared with each entry's heading,
 * and its author's name and death year with the opening of the entry, where al-Dhari'a names the author. A work
 * with no heading of its own on the page may still be named inside another entry there: a mention.
 */
class ItemMatcher
{
    /** How much of an entry's opening is read for its author: the name follows the subject within a few lines. */
    private const AUTHOR_ZONE = 300;

    /** Name parts too common to tell one author from another. */
    private const COMMON = ['بن', 'ابن', 'محمد', 'علی', 'حسن', 'حسین', 'احمد', 'ابو', 'ابی', 'سید', 'شیخ', 'میرزا', 'ملا', 'مولی',
        'الدین', 'دین', 'عبد', 'عبدالله', 'امیر', 'حاج', 'آقا', 'اقا', 'خان', 'بیگ', 'قاضی'];

    /**
     * The best entry for the work among the entries on the cited page, or null.
     *
     * @param  list<string>  $titles  the work's title and its other titles
     * @param  list<array{id: int, entry_number: ?int, title: string, text: string}>  $items  the entries on the page
     * @return array{id: int, role: string, method: string, confidence: int, title_score: float, author: bool, death: bool}|null
     */
    public function match(array $titles, ?string $author, ?int $death, array $items, ?int $citedNumber = null): ?array
    {
        if ($citedNumber !== null) {
            foreach ($items as $item) {
                if ($item['entry_number'] === $citedNumber) {
                    return $this->result($item, 'entry', 'cited_number', 100, 1.0, $author, $death);
                }
            }
        }
        $titles = array_values(array_filter(array_map(fn ($t) => self::norm((string) $t), $titles)));
        if (! $titles) {
            return null;
        }
        $scored = [];
        foreach ($items as $item) {
            if ($item['title'] === '') {
                continue;
            }
            $score = max(array_map(fn ($t) => self::titleScore($t, self::norm($item['title'])), $titles));
            // the heading with the words after it: Fankha's «تحفة الاخوان فی تقویة الایمان» is printed
            // «( تحفة الاخوان ) في تقوية الايمان»
            $full = max(array_map(fn ($t) => self::fullTitleScore($t, $item), $titles));
            $zone = mb_substr($item['text'], 0, self::AUTHOR_ZONE);
            $scored[] = ['item' => $item, 'score' => $score, 'threshold' => self::threshold(self::norm($item['title'])),
                'hits' => self::authorHits($author, $zone), 'full' => $full, 'author' => self::authorIn($author, $zone), 'death' => self::deathIn($death, $zone)];
        }
        $good = array_values(array_filter($scored, fn ($s) => $s['score'] >= $s['threshold']));
        // the very title, once on the page: the closer one among «البداية المنطقية», «بداية المأمول» …
        $exact = array_values(array_filter($good, fn ($s) => $s['score'] >= 0.97));
        if (count($exact) === 1 && count(array_filter($good, fn ($s) => $s['score'] >= $exact[0]['score'] - 0.1)) === 1) {
            $s = $exact[0];

            return $this->result($s['item'], 'entry', $s['author'] || $s['death'] ? 'title_author' : 'title_exact',
                $s['author'] || $s['death'] ? 95 : ($author === null ? 85 : 75), $s['score'], $author, $death);
        }
        // several entries of one title on the page («التحفة» ×6): the author tells them apart
        $byAuthor = array_values(array_filter($good, fn ($s) => $s['author'] || $s['death']));
        if (count($byAuthor) === 1 || (count($byAuthor) > 1 && ($best = self::clearlyBest($byAuthor)))) {
            $s = $best ?? $byAuthor[0];
            $confidence = $s['score'] >= 0.85 && $s['author'] && $s['death'] ? 95 : ($s['score'] >= 0.85 ? 90 : 80);

            return $this->result($s['item'], 'entry', 'title_author', $confidence, $s['score'], $author, $death);
        }
        // «شرح تبصرة المتعلمین», «الباب الحادی عشر (ترجمه)»: a commentary or translation found at the heading of the
        // book it comments on is only named there
        if (count($good) === 1 && ! $byAuthor && self::derivative($titles[0]) && ! self::derivative(self::norm($good[0]['item']['title']))) {
            return $this->result($good[0]['item'], 'mention', 'derivative_of_heading', 50, $good[0]['score'], $author, $death);
        }
        if (count($good) === 1 && ! $byAuthor) {
            return $this->result($good[0]['item'], 'entry', 'title_only', $author === null ? 70 : 55, $good[0]['score'], $author, $death);
        }
        if (count($good) > 1 && ($best = self::clearlyBest($good)) && $best['full'] >= 0.9) {
            return $this->result($best['item'], 'entry', 'full_title', $best['author'] || $best['death'] ? 90 : 75, $best['full'], $author, $death);
        }
        if (count($good) > 1) {
            // the right one is among them; the reviewer chooses
            usort($good, fn ($a, $b) => $b['score'] <=> $a['score']);

            return $this->result($good[0]['item'], 'entry', 'ambiguous', 30, $good[0]['score'], $author, $death);
        }
        // no heading of the title: the one entry whose opening names both the author and the death year is likely
        // the work under another title («حاشية فرائد الاصول» of al-Ashtiyani is his «بحر الفوائد»)
        $authorDeath = array_values(array_filter($scored, fn ($s) => $s['author'] && $s['death']));
        if (count($authorDeath) === 1) {
            return $this->result($authorDeath[0]['item'], 'entry', 'author_death', 50, $authorDeath[0]['score'], $author, $death);
        }
        // an entry that names it, best one whose opening also names its author
        $mentions = [];
        foreach ($items as $item) {
            $text = self::norm($item['text']);
            foreach ($titles as $t) {
                if (mb_strlen($t) >= 4 && str_contains(' ' . $text . ' ', ' ' . $t . ' ')) {
                    $mentions[] = ['item' => $item, 'author' => self::authorIn($author, $item['title'] . ' ' . $item['text'])];
                    break;
                }
            }
        }
        usort($mentions, fn ($a, $b) => $b['author'] <=> $a['author']);
        if ($mentions) {
            return $this->result($mentions[0]['item'], 'mention', 'title_in_text', $mentions[0]['author'] ? 60 : 40, 0.0, $author, $death);
        }

        return null;
    }

    private function result(array $item, string $role, string $method, int $confidence, float $score, ?string $author, ?int $death): array
    {
        $zone = mb_substr($item['text'], 0, self::AUTHOR_ZONE);

        return ['id' => $item['id'], 'role' => $role, 'method' => $method, 'confidence' => $confidence, 'title_score' => round($score, 2),
            'author' => self::authorIn($author, $zone), 'death' => self::deathIn($death, $zone)];
    }

    /** One entry that is plainly the best: the highest title score, or the only one naming both author and year. */
    private static function clearlyBest(array $scored): ?array
    {
        $both = array_values(array_filter($scored, fn ($s) => $s['author'] && $s['death']));
        if (count($both) === 1) {
            return $both[0];
        }
        // the one whose heading and the words after it make the whole title
        usort($scored, fn ($a, $b) => $b['full'] <=> $a['full']);
        if ($scored[0]['full'] >= 0.9 && $scored[0]['full'] - $scored[1]['full'] >= 0.1) {
            return $scored[0];
        }
        // the one naming more of the author's name («حکیم مؤمن … محمد زمان … تنکابنی» against a bare «مؤمن»)
        usort($scored, fn ($a, $b) => [$b['hits'], $b['score']] <=> [$a['hits'], $a['score']]);
        if ($scored[0]['hits'] > $scored[1]['hits']) {
            return $scored[0];
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $scored[0]['score'] - $scored[1]['score'] >= 0.1 ? $scored[0] : null;
    }

    /** Text::norm, with the hamza that ablibrary writes after a final heh («تحفهء شاهي») and its seats unified. */
    public static function norm(string $text): string
    {
        return Text::norm(str_replace(['ء', 'ئ', 'ؤ'], ['', 'ی', 'و'], $text));
    }

    /** Whether a title names a work made on another: a commentary, gloss, translation, summary or selection. */
    public static function derivative(string $title): bool
    {
        return (bool) preg_match('/^(شرح|حاشیه|حاشیة|ترجمه|ترجمة|تعلیقه|تعلیقة|تکمله|تکملة|مختصر|منتخب|تلخیص)\s|\s(ترجمه|ترجمة|شرح|منتخب|مختصر|خلاصه|تلخیص)(\s|$)/u', $title);
    }

    /** The title score an entry heading needs: short titles («برهان» against «بستان») need to be closer. */
    private static function threshold(string $title): float
    {
        return mb_strlen($title) <= 10 ? 0.88 : 0.8;
    }

    /** The work's title against the entry's heading followed by as many of the entry's next words as it lacks. */
    private static function fullTitleScore(string $title, array $item): float
    {
        $heading = self::norm($item['title']);
        $lack = count(explode(' ', $title)) - count(explode(' ', $heading));
        if ($lack <= 0) {
            return 0.0;
        }
        $after = preg_replace('/^\(.*?\)\s*/u', '', ltrim($item['text']), 1);
        $words = array_slice(preg_split('/\s+/u', self::norm(mb_substr($after, 0, 200))), 0, $lack);

        return Text::similarity($title, trim($heading . ' ' . implode(' ', $words)));
    }

    /** Title likeness: difflib's ratio, or 0.9 when one is the other's beginning (Fankha adds the subtitle). */
    public static function titleScore(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        $score = Text::similarity($a, $b);
        [$short, $long] = mb_strlen($a) <= mb_strlen($b) ? [$a, $b] : [$b, $a];
        if (mb_strlen($short) >= 4 && str_starts_with($long . ' ', $short . ' ')) {
            $score = max($score, 0.9);
        }

        return $score;
    }

    /** Whether a distinctive part of the author's name («شفتی بیدآبادی، محمد باقر …») is in the text. */
    public static function authorIn(?string $author, string $text): bool
    {
        return self::authorHits($author, $text) > 0;
    }

    /** How many distinctive parts of the author's name are in the text. */
    public static function authorHits(?string $author, string $text): int
    {
        if (blank($author)) {
            return 0;
        }
        $hits = 0;
        $text = ' ' . self::norm($text) . ' ';
        // «عبد الجليل» in the text, «عبدالجلیل» in Fankha
        $flat = str_replace(' ', '', $text);
        foreach (preg_split('/\s+/u', self::norm(str_replace('،', ' ', $author))) as $part) {
            if (mb_strlen($part) > 2 && ! in_array($part, self::COMMON, true)
                && (str_contains($text, ' ' . $part . ' ') || (mb_strlen($part) >= 5 && str_contains($flat, $part)))) {
                $hits++;
            }
        }

        return $hits;
    }

    /** Whether the author's death year (±1) is printed in the text. */
    public static function deathIn(?int $death, string $text): bool
    {
        if (! $death) {
            return false;
        }
        preg_match_all('/\d{3,4}/u', Text::digits($text), $m);
        foreach ($m[0] as $year) {
            if (abs((int) $year - $death) <= 1) {
                return true;
            }
        }

        return false;
    }
}
