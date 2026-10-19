<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Splits a Fankha bibliography entry such as «دنا 2/207؛ الذریعة 90/25 و 107/8» into text and link
 * segments, linking each «page/volume» citation of a source in config('bibliography.sources')
 * to that page of its online edition.
 */
class BibliographyLinks
{
    /**
     * The entry as escaped HTML with Persian digits and the linked citations as anchors. A citation whose
     * page image is on the site opens the page viewer; the anchor keeps the online edition as its href.
     */
    public static function html(string $entry): HtmlString
    {
        $html = '';
        foreach (self::segments($entry) as $seg) {
            $text = e(Fa::digits($seg['text']));
            if (! isset($seg['url'])) {
                $html .= $text;
                continue;
            }
            $attrs = 'href="' . e($seg['url']) . '"' . ($seg['url'] === '#' ? '' : ' target="_blank" rel="noopener"') . ' title="' . e(Fa::digits($seg['title'])) . '"';
            if (isset($seg['viewer'])) {
                $attrs .= ' onclick="event.preventDefault(); window.dispatchEvent(new CustomEvent(\'open-catalog-viewer\', { detail: ' . e(json_encode($seg['viewer'], JSON_UNESCAPED_UNICODE)) . ' }))"';
            }
            $html .= '<a ' . $attrs . ' class="text-cinnabar border-b border-cinnabar/40 hover:border-cinnabar transition">' . $text . '</a>';
        }

        return new HtmlString($html);
    }

    /**
     * The distinct pages the entries cite that the page viewer can show, in order of first mention.
     *
     * @param  iterable<string>  $entries
     * @return list<array{catalog: string, catalogName: string, volume: int, page: int}>
     */
    public static function viewerPages(iterable $entries): array
    {
        $pages = [];
        foreach ($entries as $entry) {
            foreach (self::segments((string) $entry) as $seg) {
                if (isset($seg['viewer'])) {
                    $v = $seg['viewer'];
                    $pages["{$v['catalog']}/{$v['volume']}/{$v['page']}"] ??= $v;
                }
            }
        }

        return array_values($pages);
    }

    /**
     * @return list<array{text: string, url?: string, title?: string, viewer?: array}>
     */
    public static function segments(string $entry): array
    {
        $segments = [];
        $source = null; // the source the following citations belong to
        $cursor = 0;
        $volume = null; // the volume of the citation just linked, while a bare «و page» may still continue it

        // «ج 1، ص 185» / «ج 2 ستون 1511»; «1-93» (volume-column); «page/volume» or «volume: column»; a bare number
        preg_match_all(
            '/ج\s*(?<jv>[0-9۰-۹]+)\s*[،,:]?\s*(?:ص|ستون|-)\s*(?:ستون\s*)?(?<jp>[0-9۰-۹]+)'
            . '|(?<da>[0-9۰-۹]+)\s*-\s*(?<db>[0-9۰-۹]+)(?!\s*[\/:0-9۰-۹])'
            . '|(?<a>[0-9۰-۹]+)(?:\s*(?<sep>[\/:])\s*(?<b>[0-9۰-۹]+))?/u',
            $entry, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL
        );

        foreach ($matches as $m) {
            [$whole, $offset] = $m[0];
            $gap = substr($entry, $cursor, $offset - $cursor);
            $named = self::namedRightBefore($gap); // the source whose name the number directly follows
            $joined = $source && preg_match('/^\s*و\s*$/u', $gap); // «و» right after a linked citation
            if (isset($m['jp'][0])) {
                $source = self::sourceAfter($gap, $source);
                $volume = $source ? self::volume($source, self::int($m['jv'][0])) : null;
                $link = $volume ? self::pageLink($source, $volume, self::int($m['jp'][0])) : null;
            } elseif (isset($m['db'][0])) {
                // «کشف الظنون 1-93»: in the two-volume column books a dash after the name joins volume and column
                $key = $named ?? ($joined && $volume ? $source : null);
                $volume = $key && isset(self::sources()[$key]['unit']) ? self::volume($key, self::int($m['da'][0])) : null;
                $link = $volume ? self::pageLink($key, $volume, self::int($m['db'][0])) : null;
                $source = $link ? $key : $source;
            } elseif (isset($m['b'][0]) && $m['sep'][0] === ':') {
                // «کشف الظنون 1: 127»: a colon joins volume and column only right after the name of a column book
                $link = $named && isset(self::sources()[$named]['unit']) ? self::link($named, self::int($m['a'][0]), self::int($m['b'][0])) : null;
                $source = $link ? $named : $source;
            } elseif (isset($m['b'][0])) {
                $source = self::sourceAfter($gap, $source);
                $link = $source ? self::link($source, self::int($m['a'][0]), self::int($m['b'][0])) : null;
            } elseif ($volume && $joined) {
                // «130/24 و 131»: a bare page joined by «و» to the citation before it is in that citation's volume;
                // not «و 117: نشانی 7», nor «1: 94 و 2», where a column book's bare volume number follows
                $page = self::int($whole);
                $link = ! preg_match('/^\s*:/u', substr($entry, $offset + strlen($whole)))
                    && ! (isset(self::sources()[$source]['unit']) && $page <= self::sources()[$source]['volumes'])
                    ? self::pageLink($source, $volume, $page) : null;
            } else {
                // «کشف الظنون 1266»: a column counted across the volumes tells its volume by itself
                $volume = $named ? self::volumeOfColumn($named, self::int($whole)) : null;
                $link = $volume ? self::pageLink($named, $volume, self::int($whole)) : null;
                $source = $link ? $named : $source;
            }
            if (! $link) {
                $volume = null;
                continue;
            }

            self::pushText($segments, $gap);
            $segments[] = ['text' => $whole] + $link;
            $cursor = $offset + strlen($whole);
            $volume = $link['volume'];
        }

        self::pushText($segments, substr($entry, $cursor));

        return array_map(function ($seg) {
            unset($seg['volume']);

            return $seg;
        }, $segments);
    }

    /**
     * The source in effect after the text between two citations: a new source name switches to it,
     * any other name ends the current one, and bare numbers, «و»، «ج»، «ص»، «ش»، «رقم» or notes in parentheses keep it.
     */
    private static function sourceAfter(string $gap, ?string $current): ?string
    {
        $gap = preg_replace('/\([^)]*\)/u', ' ', $gap);
        $pieces = preg_split('/[0-9۰-۹؛;،,:.\/\-–]+/u', $gap);
        $name = '';
        foreach (array_reverse($pieces) as $piece) {
            $piece = trim(preg_replace(['/[^\p{L}\p{M}\x{200C}\s]+/u', '/(^|\s)(?:و|ج|ص|ش|رقم)(?=\s|$)/u'], ' ', $piece));
            if ($piece !== '') {
                $name = $piece;
                break;
            }
        }
        if ($name === '') {
            return $current;
        }

        $name = self::normalize($name);
        foreach (self::sources() as $key => $src) {
            if (preg_match('/^' . $src['pattern'] . '$/u', $name)) {
                return $key;
            }
        }

        return null;
    }

    /** Fankha writes «page/volume»; the reverse order is accepted only when it is the one that fits. */
    private static function link(string $key, int $first, int $second): ?array
    {
        // the book's own volume numbers first: «هدیة العارفین 2/6» is column 6 of volume 2, not column 2 of «6»
        foreach ([false, true] as $series) {
            if ($volume = self::volume($key, $second, $series)) {
                return self::pageLink($key, $volume, $first);
            }
            if ($volume = self::volume($key, $first, $series)) {
                return self::pageLink($key, $volume, $second);
            }
        }

        return null;
    }

    /**
     * The volume a cited volume number stands for: one of the source's own, or one of its series_volumes
     * (the Beirut reprint numbers the Istanbul set on, so Idah's volumes are 3-4 there and Hadiyya's 5-6).
     */
    private static function volume(string $key, int $n, bool $series = true): ?int
    {
        $src = self::sources()[$key];
        if ($n >= 1 && $n <= $src['volumes']) {
            return $n;
        }

        return $series ? $src['series_volumes'][$n] ?? null : null;
    }

    /** The volume holding a column, for a source whose column count runs on across volumes (first_pages). */
    private static function volumeOfColumn(string $key, int $column): ?int
    {
        $viewer = self::sources()[$key]['viewer'] ?? [];
        if (empty($viewer['first_pages'])) {
            return null;
        }
        foreach ($viewer['last_pages'] as $volume => $last) {
            if ($column >= ($viewer['first_pages'][$volume] ?? 1) && $column <= $last) {
                return $volume;
            }
        }

        return null;
    }

    /** The source whose name ends the text before a number, with at most «:»، «،» or «(چاپ استانبول)» between. */
    private static function namedRightBefore(string $gap): ?string
    {
        $gap = preg_replace(['/\(\s*چاپ استانبول\s*\)/u', '/[\s:،,]+$/u'], ['', ''], $gap);
        $last = preg_split('/[0-9۰-۹؛;،,:.\/\-–()]+/u', $gap);

        return preg_match('/\p{L}$/u', $gap) ? self::sourceAfter(end($last), null) : null;
    }

    private static function pageLink(string $key, int $volume, int $page): ?array
    {
        $src = self::sources()[$key];
        if ($page < 1) {
            return null;
        }

        $unit = $src['unit'] ?? 'ص';
        $link = isset($src['url']) ? [
            'url' => strtr($src['url'], ['{volume}' => $volume, '{page}' => $page]),
            'title' => "{$src['label']}، ج {$volume}، {$unit} {$page} — {$src['site']}",
        ] : null;
        $viewer = $src['viewer'] ?? null;
        if ($viewer && $page >= ($viewer['first_pages'][$volume] ?? 1) && $page <= ($viewer['last_pages'][$volume] ?? 0)
            && ! in_array($page, $viewer['missing'][$volume] ?? [], true)) {
            $link = [
                'url' => $link['url'] ?? '#',
                'title' => "دیدن برگه‌ی {$src['label']}، ج {$volume}، {$unit} {$page}",
                'viewer' => ['catalog' => $viewer['catalog'], 'catalogName' => $viewer['name'], 'volume' => $volume, 'page' => $page],
            ];
        }

        return $link ? $link + ['volume' => $volume] : null;
    }

    private static function pushText(array &$segments, string $text): void
    {
        if ($text !== '') {
            $segments[] = ['text' => $text];
        }
    }

    private static function int(string $digits): int
    {
        return (int) strtr($digits, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }

    private static function normalize(string $s): string
    {
        $s = strtr($s, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', "\u{200C}" => ' ', 'ـ' => '']);
        $s = preg_replace('/[\x{064B}-\x{0652}]/u', '', $s);

        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    private static function sources(): array
    {
        return config('bibliography.sources', []);
    }
}
