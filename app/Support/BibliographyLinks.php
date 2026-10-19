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

        preg_match_all('/([0-9۰-۹]+)(?:\s*\/\s*([0-9۰-۹]+))?/u', $entry, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $m) {
            [$whole, $offset] = $m[0];
            $gap = substr($entry, $cursor, $offset - $cursor);
            if (isset($m[2])) {
                $source = self::sourceAfter($gap, $source);
                $link = $source ? self::link($source, self::int($m[1][0]), self::int($m[2][0])) : null;
            } else {
                // «130/24 و 131»: a bare page joined by «و» to the citation before it is in that citation's volume
                $link = $volume && preg_match('/^\s*و\s*$/u', $gap) && ! preg_match('/^\s*:/u', substr($entry, $offset + strlen($whole)))
                    ? self::pageLink($source, $volume, self::int($whole))
                    : null;
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
        $src = self::sources()[$key];
        if ($second >= 1 && $second <= $src['volumes']) {
            [$volume, $page] = [$second, $first];
        } elseif ($first >= 1 && $first <= $src['volumes']) {
            [$volume, $page] = [$first, $second];
        } else {
            return null;
        }

        return self::pageLink($key, $volume, $page);
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
