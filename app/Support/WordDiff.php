<?php

namespace App\Support;

/**
 * A word-level comparison of two texts for the review desk (step 14.72): what a suggestion keeps, adds and drops.
 * The result is a list of [op, text] pairs, op one of '=' (kept), '-' (dropped), '+' (added) and '…' (a long kept
 * stretch left out by compact()). Whitespace travels with the text, so a list keeps its lines.
 */
final class WordDiff
{
    /** Beyond this many token pairs the middle is shown as replaced whole instead of compared word by word. */
    private const MAX_CELLS = 400000;

    /** @return list<array{0: string, 1: string}> */
    public static function ops(string $old, string $new): array
    {
        $a = self::tokens($old);
        $b = self::tokens($new);
        $na = count($a);
        $nb = count($b);

        $pre = 0;
        while ($pre < $na && $pre < $nb && $a[$pre] === $b[$pre]) {
            $pre++;
        }
        $suf = 0;
        while ($suf < $na - $pre && $suf < $nb - $pre && $a[$na - 1 - $suf] === $b[$nb - 1 - $suf]) {
            $suf++;
        }

        $ops = [];
        foreach (array_slice($a, 0, $pre) as $t) {
            $ops[] = ['=', $t];
        }
        $midA = array_slice($a, $pre, $na - $pre - $suf);
        $midB = array_slice($b, $pre, $nb - $pre - $suf);
        if (count($midA) * count($midB) > self::MAX_CELLS) {
            foreach ($midA as $t) {
                $ops[] = ['-', $t];
            }
            foreach ($midB as $t) {
                $ops[] = ['+', $t];
            }
        } else {
            array_push($ops, ...self::lcs($midA, $midB));
        }
        foreach (array_slice($a, $na - $suf) as $t) {
            $ops[] = ['=', $t];
        }

        // A change of spacing alone is not worth a mark, and a mark starts and ends on a word: spaces at its edges
        // (not line breaks, which would show twice) go to the text both sides keep
        $out = [];
        foreach (self::merge($ops) as [$op, $text]) {
            if ($op === '=' || trim($text) === '') {
                $out[] = ['=', $text];

                continue;
            }
            preg_match('/^([ \t]*)(.*?)([ \t]*)$/su', $text, $m);
            if ($m[1] !== '') {
                $out[] = ['=', $m[1]];
            }
            $out[] = [$op, $m[2]];
            if ($m[3] !== '') {
                $out[] = ['=', $m[3]];
            }
        }

        return self::merge($out);
    }

    /** Whether the two texts differ in anything but spacing. */
    public static function changed(array $ops): bool
    {
        foreach ($ops as [$op]) {
            if ($op === '+' || $op === '-') {
                return true;
            }
        }

        return false;
    }

    /** The ops one side shows: the old text with its drops, or the new text with its additions. */
    public static function side(array $ops, string $side): array
    {
        $drop = $side === 'old' ? '+' : '-';

        return self::merge(array_values(array_filter($ops, fn ($op) => $op[0] !== $drop)));
    }

    /**
     * Long kept stretches shortened to a few words on each side of a change, so a long text shows only what changed.
     * A text of at most $min words is left whole.
     */
    public static function compact(array $ops, int $context = 6, int $min = 40): array
    {
        $words = fn (string $s) => preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY);
        $total = 0;
        foreach ($ops as [, $text]) {
            $total += count($words($text));
        }
        if ($total <= $min) {
            return $ops;
        }

        $out = [];
        $last = count($ops) - 1;
        foreach ($ops as $i => [$op, $text]) {
            if ($op !== '=') {
                $out[] = [$op, $text];

                continue;
            }
            $parts = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
            $wordIdx = array_keys(array_filter($parts, fn ($p) => trim($p) !== ''));
            $keepHead = $i === 0 ? 0 : $context;
            $keepTail = $i === $last ? 0 : $context;
            if (count($wordIdx) <= $keepHead + $keepTail + 2) {
                $out[] = [$op, $text];

                continue;
            }
            if ($keepHead) {
                $end = $wordIdx[$keepHead - 1];
                $out[] = ['=', implode('', array_slice($parts, 0, $end + 1)) . ' '];
            }
            $out[] = ['…', '…'];
            if ($keepTail) {
                $start = $wordIdx[count($wordIdx) - $keepTail];
                $out[] = ['=', ' ' . implode('', array_slice($parts, $start))];
            }
        }

        return $out;
    }

    /** @return list<string> words and the whitespace between them */
    private static function tokens(string $s): array
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);

        return preg_split('/(\s+)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function lcs(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        if ($n === 0 || $m === 0) {
            return array_merge(array_map(fn ($t) => ['-', $t], $a), array_map(fn ($t) => ['+', $t], $b));
        }
        // lengths of the common subsequence of the suffixes a[i..] and b[j..]
        $len = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $len[$i][$j] = $a[$i] === $b[$j] ? $len[$i + 1][$j + 1] + 1 : max($len[$i + 1][$j], $len[$i][$j + 1]);
            }
        }
        $ops = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = ['=', $a[$i]];
                $i++;
                $j++;
            } elseif ($len[$i + 1][$j] >= $len[$i][$j + 1]) {
                $ops[] = ['-', $a[$i++]];
            } else {
                $ops[] = ['+', $b[$j++]];
            }
        }
        for (; $i < $n; $i++) {
            $ops[] = ['-', $a[$i]];
        }
        for (; $j < $m; $j++) {
            $ops[] = ['+', $b[$j]];
        }

        return $ops;
    }

    private static function merge(array $ops): array
    {
        $out = [];
        foreach ($ops as [$op, $text]) {
            if ($out && $out[count($out) - 1][0] === $op && $op !== '…') {
                $out[count($out) - 1][1] .= $text;
            } else {
                $out[] = [$op, $text];
            }
        }

        return $out;
    }
}
