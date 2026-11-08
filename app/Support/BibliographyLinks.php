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
    /** inProse()'s regex of every source's name, per citing source, until a citation form changes. */
    private static array $proseRegex = [];

    /** The source whose text is read and where in it: Fankha's bibliography strings, or a source's page text in inProse(). */
    private static array $citing = ['fankha', 'bibliography'];

    /**
     * The entry as escaped HTML with Persian digits and the linked citations as anchors. A citation whose
     * page image is on the site opens the page viewer; the anchor keeps the online edition as its href. A source the
     * site has as text only opens its page's text on the site, in the same tab.
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
            $attrs = 'href="' . e($seg['url']) . '"' . ($seg['url'] === '#' || isset($seg['internal']) ? '' : ' target="_blank" rel="noopener"') . ' title="' . e(Fa::digits($seg['title'])) . '"';
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
     * The entries with every citation of one viewer page ($old: catalog, volume, page) changed to another volume
     * and page, written in the form the citation had: «101/25» (page/volume), «25/101», «ج 25، ص 101», «1-93»
     * or «1: 127» (volume first), or a bare page when only the page changes. Null when no citation of that page
     * is found or the new text would not link to the new page, so the reviewer edits the entry by hand.
     *
     * @param  list<string>  $entries
     * @return list<string>|null
     */
    public static function rewriteCitation(array $entries, array $old, int $volume, int $page): ?array
    {
        $changed = false;
        foreach ($entries as $i => $entry) {
            $out = '';
            foreach (self::segments((string) $entry) as $seg) {
                $v = $seg['viewer'] ?? null;
                if ($v && $v['catalog'] === $old['catalog'] && $v['volume'] === $old['volume'] && $v['page'] === $old['page']) {
                    $text = self::renumber($seg['text'], $old['volume'], $old['page'], $volume, $page);
                    if ($text === null) {
                        return null;
                    }
                    $out .= $text;
                    $changed = true;
                } else {
                    $out .= $seg['text'];
                }
            }
            $entries[$i] = $out;
        }
        $target = ['catalog' => $old['catalog'], 'volume' => $volume, 'page' => $page];
        $found = collect(self::viewerPages($entries))->contains(fn ($v) => array_intersect_key($v, $target) == $target);

        return $changed && $found ? $entries : null;
    }

    /** One citation's numbers changed: which number is the volume follows the citation's own form. */
    private static function renumber(string $text, int $oldVolume, int $oldPage, int $volume, int $page): ?string
    {
        preg_match_all('/[0-9۰-۹]+/u', $text, $m);
        $numbers = array_map(fn ($n) => self::int($n), $m[0]);
        if (count($numbers) === 1) {
            return $volume === $oldVolume ? preg_replace('/[0-9۰-۹]+/u', (string) $page, $text, 1) : null;
        }
        if (count($numbers) !== 2) {
            return null;
        }
        // «ج 25، ص 101», «1-93» and «1: 127» name the volume first; Fankha's «page/volume» the page
        $volumeFirst = match (true) {
            $numbers === [$oldVolume, $oldPage] && $numbers !== [$oldPage, $oldVolume] => true,
            $numbers === [$oldPage, $oldVolume] && $numbers !== [$oldVolume, $oldPage] => false,
            default => (bool) preg_match('/ج|-|:/u', $text),
        };
        $new = $volumeFirst ? [$volume, $page] : [$page, $volume];
        $k = 0;

        return preg_replace_callback('/[0-9۰-۹]+/u', function () use ($new, &$k) {
            return (string) $new[$k++];
        }, $text);
    }

    /**
     * The citations of an entry that name a source of config('bibliography.sources'), in order: its key, volume and
     * page (or column).
     *
     * @return list<array{source: string, volume: int, page: int}>
     */
    public static function citations(string $entry): array
    {
        return array_values(array_filter(array_column(self::segments($entry), 'cite')));
    }

    /**
     * Whether the entry is only names of sources and citations linked to them: «الذریعة 326/15 و 297/23» is, «دنا 1102/1
     * (8 نسخه)», a number not linked or a note is not.
     */
    public static function onlyCitations(string $entry): bool
    {
        $segments = self::segments($entry);
        foreach ($segments as $seg) {
            if (isset($seg['cite'])) {
                continue;
            }
            $name = self::normalize(preg_replace(['/[؛;،,:.\/\-–()]+/u', '/(^|\s)(?:و|ج|ص|ستون)(?=\s|$)/u'], ' ', $seg['text']));
            if ($name !== '' && ! collect(self::sources())->contains(fn ($src) => preg_match('/^' . $src['pattern'] . '$/u', $name))) {
                return false;
            }
        }

        return collect($segments)->contains(fn ($seg) => isset($seg['cite']));
    }

    /**
     * What a line of citations says besides them, a note for the places it cites: «فهرستواره منزوی 663/6 با انتساب به
     * قوام الدین یوسف» says «با انتساب به قوام الدین یوسف»; a line of citations only says ''. Null when the line has no
     * citation, or its other words are not such a note: a number (another place, «دنا 1102/1 (8 نسخه)»), or a printing
     * («الذریعة 403/3 (چاپ نجف)»), which may not be the one the citation was read in.
     */
    public static function note(string $entry): ?string
    {
        $segments = self::segments($entry);
        if (! collect($segments)->contains(fn ($seg) => isset($seg['cite']))) {
            return null;
        }
        $clean = fn (string $s) => self::normalize(preg_replace(['/[؛;،,:.\/\-–()«»]+/u', '/(^|\s)(?:و|ج|ص|ستون)(?=\s|$)/u'], ' ', $s));
        $isSource = fn (string $name) => collect(self::sources())->contains(fn ($src) => preg_match('/^' . $src['pattern'] . '$/u', $name));
        $words = [];
        foreach ($segments as $seg) {
            if (isset($seg['cite']) || ($name = $clean($seg['text'])) === '' || $isSource($name)) {
                continue;
            }
            // «… با انتساب به قوام الدین، فهرستواره منزوی»: the note, without the name of the next citation's source
            foreach (preg_split('/[؛;،,]+/u', $seg['text']) as $piece) {
                if (($name = $clean($piece)) !== '' && ! $isSource($name)) {
                    // not trim(), which works on bytes and would cut the Arabic letters «،» shares one with
                    $words[] = preg_replace('/^[\s:.\-–]+|[\s:.\-–]+$/u', '', $piece);
                }
            }
        }
        $note = implode('، ', $words);
        if (preg_match('/[0-9۰-۹]|(^|[\s(])(چاپ|چ|ط|طبع|الطبعة|افست)(\s|$|\))/u', $note)) {
            return null;
        }

        return $note;
    }

    /**
     * @return list<array{text: string, url?: string, title?: string, viewer?: array, internal?: bool, cite?: array}>
     */
    public static function segments(string $entry): array
    {
        $segments = [];
        $source = null; // the source the following citations belong to
        $cursor = 0;
        $volume = null; // the volume of the citation just linked, while a bare «و page» may still continue it

        // «ج 1، ص 185» / «ج 2 ستون 1511» / «ج1، صص 1235»; «1-93» (volume-column); «page/volume» or «volume: column»; a bare number
        preg_match_all(
            '/ج\s*(?<jv>[0-9۰-۹]+)\s*[،,:]?\s*(?:صص|ص|ستون|-)\s*(?:ستون\s*)?(?<jp>[0-9۰-۹]+)'
            . '|(?<da>[0-9۰-۹]+)\s*-\s*(?<db>[0-9۰-۹]+)(?!\s*[\/:0-9۰-۹])'
            . '|(?<a>[0-9۰-۹]+)(?:\s*(?<sep>[\/:])\s*(?<b>[0-9۰-۹]+))?/u',
            $entry, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL
        );

        foreach ($matches as $m) {
            [$whole, $offset] = $m[0];
            $gap = substr($entry, $cursor, $offset - $cursor);
            $named = self::namedRightBefore($gap); // the source whose name the number directly follows
            $joined = $source && preg_match('/^\s*و\s*$/u', $gap); // «و» right after a linked citation
            // «الذریعه 18: 311 و 6: 60»، «… 3-432، 10-368»: a pair right after a linked one of a pairs_by_fit source
            $chained = $volume && $source && ! empty(self::sources()[$source]['pairs_by_fit']) && preg_match('/^\s*[و،,]\s*$/u', $gap);
            if (isset($m['jp'][0])) {
                $source = self::sourceAfter($gap, $source);
                $volume = $source ? self::volume($source, self::int($m['jv'][0])) : null;
                $link = $volume ? self::pageLink($source, $volume, self::int($m['jp'][0])) : null;
            } elseif (isset($m['db'][0])) {
                // «کشف الظنون 1-93»: in the two-volume column books (and pairs_after_name ones) a dash after the name
                // joins volume and column
                $key = $named ?? (($joined || $chained) && $volume ? $source : null);
                if ($key && ! empty(self::sources()[$key]['pairs_by_fit'])) {
                    $link = self::fittingLink($key, self::int($m['da'][0]), self::int($m['db'][0]));
                } else {
                    $volume = $key && self::pairsAfterName($key) ? self::volume($key, self::int($m['da'][0])) : null;
                    $link = $volume ? self::pageLink($key, $volume, self::int($m['db'][0])) : null;
                }
                // «مشار عربی 624-625», «معجم المطبوعات 1536-1537»: a range of columns, which tell their volume
                if (! $link && $named && self::int($m['da'][0]) < self::int($m['db'][0])
                    && ($volume = self::volumeOfColumn($named, self::int($m['da'][0])))) {
                    $key = $named;
                    $link = self::pageLink($key, $volume, self::int($m['da'][0]));
                }
                $source = $link ? $key : $source;
            } elseif (isset($m['b'][0]) && $m['sep'][0] === ':') {
                // «کشف الظنون 1: 127»: a colon joins volume and column only right after the name of a column book
                // (or of a pairs_after_name one)
                $key = $named ?? ($chained ? $source : null);
                $link = match (true) {
                    ! $key || ! self::pairsAfterName($key) => null,
                    ! empty(self::sources()[$key]['pairs_by_fit']) => self::fittingLink($key, self::int($m['a'][0]), self::int($m['b'][0])),
                    default => self::link($key, self::int($m['a'][0]), self::int($m['b'][0])),
                };
                $source = $link ? $key : $source;
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
        $pieces = preg_split('/([0-9۰-۹؛;،,:.\/\-–]+)/u', preg_replace('/\([^)]*\)/u', ' ', $gap), -1, PREG_SPLIT_DELIM_CAPTURE);
        $clean = fn (string $piece) => trim(preg_replace(['/[^\p{L}\p{M}\x{200C}\s]+/u', '/(^|\s)(?:و|ج|ص|ش|رقم)(?=\s|$)/u'], ' ', $piece));
        $name = $before = '';
        for ($i = count($pieces) - 1; $i >= 0; $i -= 2) {
            if (($name = $clean($pieces[$i])) !== '') {
                // the name before it when only a comma parts them: «مشار، فارسی», «سرکیس، معجم»
                if ($i >= 2 && preg_match('/^\s*[،,]\s*$/u', $pieces[$i - 1])) {
                    $before = $clean($pieces[$i - 2]);
                }
                break;
            }
        }
        if ($name === '') {
            return $current;
        }

        foreach (array_filter([self::normalize($name), $before === '' ? '' : self::normalize("$before $name")]) as $name) {
            foreach (self::sources() as $key => $src) {
                if (preg_match('/^' . $src['pattern'] . '$/u', $name)) {
                    // «کحاله (چاپ جدید) 286/1» cites another edition, whose pages do not match
                    return isset($src['other_edition']) && preg_match('/' . $src['other_edition'] . '[\s)]*$/u', self::normalize($gap))
                        ? null : $key;
                }
            }
        }

        return null;
    }

    /** Whether a colon or a dash right after the source's name joins volume and page (column books, and pairs_after_name). */
    private static function pairsAfterName(string $key): bool
    {
        $src = self::sources()[$key];

        return isset($src['unit']) || ! empty($src['pairs_after_name']) || ! empty($src['pairs_by_fit']);
    }

    /**
     * A pair after the name of a source cited in either order («الذریعه 15: 276» volume first, «الذریعه 387: 4» page
     * first; step 14.101): the one order whose volume and page the source has, or none when both orders fit («11: 9»).
     */
    private static function fittingLink(string $key, int $first, int $second): ?array
    {
        $fits = array_filter([[$first, $second], [$second, $first]], fn ($vp) => self::place($key, $vp[0], $vp[1], true) !== null);
        if (count($fits) !== 1 && ! ($first === $second && $fits)) {
            return null;
        }
        [$volume, $page] = reset($fits);

        return self::pageLink($key, $volume, $page);
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
     * The volume and page two numbers of a citation of the source name, «page:volume» as the source writes them or,
     * when only that fits, «volume:page» (al-Dhari'a's «(7:182)»); null when neither is a page the source has (its
     * viewer's or text's first and last pages, where the config gives them). $ordered: the first is the volume, as in
     * «ج 7 ص 161». Shared by the links in a source's own page texts (step 13.155, App\Support\PageLinks\References).
     *
     * @return ?array{0: int, 1: int}  [volume, page]
     */
    public static function place(string $key, int $first, int $second, bool $ordered = false): ?array
    {
        $src = self::sources()[$key] ?? null;
        if (! $src) {
            return null;
        }
        $bounds = $src['viewer'] ?? $src['text'] ?? [];
        $holds = fn (int $v, int $p) => $v >= 1 && $v <= $src['volumes'] && $p >= ($bounds['first_pages'][$v] ?? 1)
            && (! isset($bounds['last_pages']) || $p <= ($bounds['last_pages'][$v] ?? 0));
        foreach ($ordered ? [[$first, $second]] : [[$second, $first], [$first, $second]] as [$volume, $page]) {
            if ($holds($volume, $page)) {
                return [$volume, $page];
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

    /**
     * The volume holding a column, for a source whose column count runs on across volumes (first_pages); for a text
     * source, the one volume whose pages hold it («فهرستواره منزوی 3259» is in volume 5, «… 125» in any of five).
     */
    private static function volumeOfColumn(string $key, int $column): ?int
    {
        if ($text = self::sources()[$key]['text'] ?? null) {
            // «مشار 591»: a source whose bare columns Fankha took from another printing
            if (($text['bare'] ?? true) === false) {
                return null;
            }
            $holding = array_keys(array_filter($text['last_pages'], fn ($last, $v) => $column >= ($text['first_pages'][$v] ?? 1) && $column <= $last, ARRAY_FILTER_USE_BOTH));

            return count($holding) === 1 ? $holding[0] : null;
        }
        // a source the site shows nothing of: one volume holds every page, or one volume's pages hold it
        if ($plain = self::sources()[$key]['plain'] ?? null) {
            if (self::sources()[$key]['volumes'] === 1) {
                return $column <= ($plain['last_pages'][1] ?? PHP_INT_MAX) ? 1 : null;
            }
            $holding = array_keys(array_filter($plain['last_pages'], fn ($last, $v) => $column >= ($plain['first_pages'][$v] ?? 1) && $column <= $last, ARRAY_FILTER_USE_BOTH));

            return ! empty($plain['first_pages']) && count($holding) === 1 ? $holding[0] : null;
        }
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
        // a comma stays, for a name it parts («مشار، فارسی»); sourceAfter reads the last name
        $last = preg_split('/[0-9۰-۹؛;:.\/\-–()]+/u', $gap);

        return preg_match('/\p{L}$/u', $gap) ? self::sourceAfter(end($last), null) : null;
    }

    private static function pageLink(string $key, int $volume, int $page): ?array
    {
        $src = self::sources()[$key];
        if ($page < 1) {
            return null;
        }

        $unit = $src['unit'] ?? 'ص';
        $link = isset($src['url']) && $volume <= ($src['url_volumes'] ?? $src['volumes']) ? [
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

        // a source the site has as text only: the citation opens the printed page's text
        if (! $link && isset($src['text']) && ($volume = self::textVolume($src['text'], $volume, $page))) {
            $link = [
                'url' => route('source-pages.show', [$src['text']['catalog'], $volume, $page]),
                'title' => "متن {$src['label']}، ج {$volume}، {$unit} {$page}",
                'internal' => true,
            ];
        }

        // a source the site shows nothing of (step 14.57): the citation is read, within the pages its volume holds, and not linked
        if (! $link && isset($src['plain']) && $page >= ($src['plain']['first_pages'][$volume] ?? 1)
            && (! isset($src['plain']['last_pages'][$volume]) || $page <= $src['plain']['last_pages'][$volume])) {
            $link = ['title' => "{$src['label']}، ج {$volume}، {$unit} {$page}"];
        }

        return $link !== null ? $link + ['volume' => $volume, 'cite' => ['source' => $key, 'volume' => $volume, 'page' => $page]] : null;
    }

    /**
     * The volume of a text source that holds the page: the cited one, or another of its run when the cited one does
     * not («فهرستواره منزوی 3257/4» is in volume 5, whose pages go on from volume 4's). Null for a source with no text
     * in config/bibliography.php, or a page none of its volumes holds.
     */
    public static function textVolume(array|string $text, int $volume, int $page): ?int
    {
        if (is_string($text) && ! ($text = self::sources()[$text]['text'] ?? null)) {
            return null;
        }
        $holds = fn (int $v) => $page >= ($text['first_pages'][$v] ?? 1) && $page <= ($text['last_pages'][$v] ?? 0);
        if ($holds($volume)) {
            return $volume;
        }
        foreach ($text['runs'] ?? [] as $run) {
            if (in_array($volume, $run, true)) {
                foreach ($run as $v) {
                    if ($holds($v)) {
                        return $v;
                    }
                }
            }
        }

        return null;
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

    /**
     * The citations inside running text, a page of a catalogue or bibliography («… [الذريعة 69/13]»، «(ذریعه 1004/9)»),
     * as segments() gives them: there a source's name is the whole text before a number, here it is the words right
     * before it, so each name a source's pattern matches, followed by its numbers, is read on its own. Monzavi's
     * «همان‌جا 453/3» (ibid) cites the book of the citation right before it: it is linked when that one was, and a
     * citation of a book the site lacks («مشترک 46/7») in between leaves it plain.
     *
     * @return list<array{text: string, url?: string, title?: string, viewer?: array, internal?: bool, cite?: array}>
     */
    public static function inProse(string $text, ?string $citing = null): array
    {
        // as the source of the page cites the others, the forms of any source included
        $was = self::$citing;
        self::$citing = [$citing ?? 'fankha', 'page_text'];
        try {
            return self::readProse($text);
        } finally {
            self::$citing = $was;
        }
    }

    private static function readProse(string $text): array
    {
        $number = '[0-9۰-۹]+(?:\s*[\/:]\s*[0-9۰-۹]+)?';
        // Monzavi's «ذریعه (3) 791/9»: the part of a volume printed in parts, whose pages run on through them
        $part = '(?:\(\s*[0-9۰-۹]{1,2}\s*\)\s*)?';
        $regex = self::$proseRegex[implode('|', self::$citing)] ??= '/(?<![\p{L}\p{M}])(?<name>' . implode('|', array_map(fn ($s) => '(?:' . $s['pattern'] . ')', self::sources()))
            . ')(?![\p{L}\p{M}])[\s:،,]*' . $part . '(?:ج\s*)?' . $number
            . '|(?<![\p{L}\p{M}])(?<ibid>همان ?جا)[\s:،,]*' . $part . '(?<at>' . $number . ')'
            . '|(?<other>[0-9۰-۹]+\s*\/\s*[0-9۰-۹]+)/u';

        // the text as the patterns read it, a character for a character, and where each stands in the text
        $chars = mb_str_split($text);
        $plain = '';
        $at = [];
        foreach ($chars as $i => $c) {
            $c = strtr($c, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', "\u{200C}" => ' ']);
            if ($c === 'ـ' || preg_match('/^[\x{064B}-\x{0652}]$/u', $c)) {
                continue;
            }
            $plain .= $c;
            $at[] = $i;
        }
        $at[] = count($chars);
        $original = fn (int $byte, string $match) => [$at[mb_strlen(substr($plain, 0, $byte))], $at[mb_strlen(substr($plain, 0, $byte)) + mb_strlen($match)]];
        $slice = fn (int $from, int $to) => implode('', array_slice($chars, $from, $to - $from));

        $out = [];
        $from = 0;
        // the name of the last book cited, as written, while it is one of the site's
        $last = null;
        preg_match_all($regex, $plain, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        foreach ($found as $m) {
            [$start, $end] = $original($m[0][1], $m[0][0]);
            if (isset($m['name'][0])) {
                $segments = self::segments($slice($start, $end));
                if (! array_filter($segments, fn ($seg) => isset($seg['cite']))) {
                    $last = null;
                    continue;
                }
                [$nameStart, $nameEnd] = $original($m['name'][1], $m['name'][0]);
                $last = $slice($nameStart, $nameEnd);
            } elseif (isset($m['ibid'][0])) {
                if ($last === null) {
                    continue;
                }
                // ibid: the numbers read as if the last book's name came before them
                [$numberStart] = $original($m['at'][1], $m['at'][0]);
                $cited = array_values(array_filter(self::segments($last . ' ' . $slice($numberStart, $end)), fn ($seg) => isset($seg['cite'])));
                if (! $cited) {
                    continue;
                }
                $segments = [['text' => $slice($start, $numberStart)], ['text' => $slice($numberStart, $end)] + $cited[0]];
            } else {
                // a citation of a book the site lacks: an ibid after it is that book's
                $last = null;
                continue;
            }
            if ($start > $from) {
                $out[] = ['text' => $slice($from, $start)];
            }
            array_push($out, ...$segments);
            $from = $end;
        }
        if ($from < count($chars)) {
            $out[] = ['text' => implode('', array_slice($chars, $from))];
        }

        return $out;
    }

    private static function normalize(string $s): string
    {
        $s = strtr($s, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', "\u{200C}" => ' ', 'ـ' => '']);
        $s = preg_replace('/[\x{064B}-\x{0652}]/u', '', $s);

        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** The sources and how Fankha cites them: source_citation_forms, with config/bibliography.php's display details (step 14.57). */
    private static function sources(): array
    {
        return \App\Sources\CitationForms::sources(...self::$citing);
    }

    /** Drops what was built from the citation forms, after one of them changed. */
    public static function forget(): void
    {
        self::$proseRegex = [];
    }
}
