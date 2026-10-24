<?php

namespace App\Sources\Bibliography;

use App\Console\Commands\MatchCatalogEditionCommand;

/**
 * Splits the page texts of one volume of a numbered bibliography into its printed entries (plan
 * docs/plans/dharia-text.md). The texts are ablibrary's: printed lines, with a blank line between the reader's
 * paragraphs. An entry begins at a line «( 1442 : التحفة ) …»; an unnumbered one («( ديوان گلشن يزدي ) راجع …»)
 * where a paragraph begins or the line before ended a sentence, so an incipit or a reference that happens to open a
 * line («( الحمد لله … )» after «أوله») is not taken for one. A paragraph «( 1 ) …» closing a page is a footnote of
 * the entry it stands under. Text before the volume's first entry (the preface, a letter's heading) is its own item.
 */
class NumberedEntrySegmenter
{
    // a title may carry a footnote mark: «( 20 : الواجب في الاحكام اللواذب ( 1 ) )»
    private const NUMBERED = '/^\(\s*(\d{1,5})\s*:\s*([^\d\s()](?:[^()]|\(\s*\d{1,2}\s*\))*?)\s*\)\s*/u';

    private const UNNUMBERED = '/^\(\s*([^\d\s()](?:[^()]|\(\s*\d{1,2}\s*\)){0,150}?)\s*\)\s*/u';

    private const FOOTNOTE = '/^\(\s*[1-9]\s*\)\s|^[1-9]\s*-\s/u';

    /** A section heading of the book, not an entry: «( باب الباء الموحدة )». */
    private const SECTION = '/^(باب|حرف|الباب)\b/u';

    /** Words the author opens a remark with in parentheses: «( أقول ) …», «( منهم ) …», «( أحدهما ) …». */
    private const REMARKS = ['أقول', 'اقول', 'منهم', 'منها', 'أحدهما', 'احدهما', 'ثانيهما', 'الأول', 'الثاني', 'أولها', 'فمنها', 'فائدة', 'تنبيه'];

    /**
     * @param  array<int, string>  $pages  printed page number => text
     * @return list<array{key: string, kind: string, entry_number: ?int, title: string, title_normalized: string,
     *     page_start: int, page_end: int, text: string, footnotes: ?string, page_breaks: list<array{0: int, 1: int}>,
     *     parent_key: ?string, flags: array}>
     */
    public function segment(array $pages): array
    {
        ksort($pages);
        $items = [];
        $current = null;
        $lastNumbered = null; // key of the last numbered entry, parent of the unnumbered ones after it
        $subCount = 0;
        $seen = []; // entry number => times met
        $prefaceCount = 0;

        $start = function (array $fields) use (&$items, &$current) {
            if ($current !== null) {
                $items[] = $current;
            }
            $current = $fields + ['lines' => [], 'footnotes' => [], 'page_breaks' => [], 'flags' => []];
        };

        foreach ($pages as $page => $text) {
            $paragraphs = preg_split('/\n\s*\n/u', trim(str_replace("\r", '', (string) $text)));
            // footnotes: the paragraphs closing the page that open with «( 1 )» or «1 -», never the page's first
            $footStart = count($paragraphs);
            for ($i = count($paragraphs) - 1; $i > 0; $i--) {
                if (! preg_match(self::FOOTNOTE, ltrim($paragraphs[$i]))) {
                    break;
                }
                $footStart = $i;
            }
            $pageOpened = false;
            foreach ($paragraphs as $pi => $paragraph) {
                if ($pi >= $footStart) {
                    if ($current !== null) {
                        $current['footnotes'][] = ['page' => $page, 'text' => trim($paragraph)];
                    }

                    continue;
                }
                $lines = array_values(array_filter(array_map('trim', explode("\n", $paragraph)), fn ($l) => $l !== ''));
                foreach ($lines as $li => $line) {
                    $prev = $li > 0 ? $lines[$li - 1] : null;
                    $opens = $prev === null || preg_match('/[.:؟!]\s*$/u', $prev);
                    if (preg_match(self::NUMBERED, $line, $m)) {
                        $number = (int) $m[1];
                        $seen[$number] = ($seen[$number] ?? 0) + 1;
                        $key = $seen[$number] > 1 ? "{$number}#{$seen[$number]}" : (string) $number;
                        $start(['key' => $key, 'kind' => 'entry', 'entry_number' => $number, 'title' => self::title($m[2]),
                            'page_start' => $page, 'page_end' => $page, 'parent_key' => null]);
                        $lastNumbered = $key;
                        $subCount = 0;
                    } elseif ($opens && $current !== null && preg_match(self::UNNUMBERED, $line, $m) && self::isHeading($m[1], $line, $m[0])) {
                        $subCount++;
                        $parent = $lastNumbered;
                        $key = ($parent ?? 'p' . $current['page_start']) . '/' . $subCount;
                        $start(['key' => $key, 'kind' => 'sub_entry', 'entry_number' => null, 'title' => self::title($m[1]),
                            'page_start' => $page, 'page_end' => $page, 'parent_key' => $parent]);
                    } elseif ($current === null) {
                        $prefaceCount++;
                        $start(['key' => "p{$page}/0", 'kind' => 'preface', 'entry_number' => null, 'title' => '',
                            'page_start' => $page, 'page_end' => $page, 'parent_key' => null]);
                    } elseif (! $pageOpened && $current['page_end'] !== $page) {
                        // the entry runs on onto this page: note where, in characters of its text
                        $current['page_breaks'][] = [mb_strlen(implode("\n", $current['lines'])), $page];
                        $current['page_end'] = $page;
                    }
                    $pageOpened = true;
                    $current['lines'][] = $line;
                }
            }
        }
        if ($current !== null) {
            $items[] = $current;
        }

        return $this->finish($items);
    }

    /**
     * Whether a parenthesised opening is an unnumbered entry's title: not a section heading, a remark, a verse
     * («… * …») or a long quotation, and followed by the entry's text on the line.
     */
    private static function isHeading(string $title, string $line, string $matched): bool
    {
        $title = self::title($title);

        return ! preg_match(self::SECTION, $title) && ! in_array($title, self::REMARKS, true) && ! str_contains($title, '*')
            && count(preg_split('/\s+/u', $title)) <= 8 && trim(mb_substr($line, mb_strlen($matched)), " .") !== '';
    }

    /** The title as printed, without a footnote mark. */
    private static function title(string $title): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/\(\s*\d{1,2}\s*\)/u', '', $title)));
    }

    private function finish(array $items): array
    {
        $out = [];
        $previous = null;
        foreach ($items as $item) {
            $flags = [];
            if ($item['entry_number'] !== null) {
                if ($previous !== null && $item['entry_number'] !== $previous + 1) {
                    $flags['sequence'] = $item['entry_number'] === $previous ? 'repeated' : ($item['entry_number'] < $previous ? 'backward' : 'gap');
                    $flags['previous_number'] = $previous;
                }
                $previous = $item['entry_number'];
            }
            $footnotes = $item['footnotes'] ? implode("\n", array_map(fn ($f) => $f['text'], $item['footnotes'])) : null;
            $out[] = [
                'key' => $item['key'],
                'kind' => $item['kind'],
                'entry_number' => $item['entry_number'],
                'title' => $item['title'],
                'title_normalized' => $item['title'] === '' ? '' : MatchCatalogEditionCommand::norm($item['title']),
                'page_start' => $item['page_start'],
                'page_end' => $item['page_end'],
                'text' => implode("\n", $item['lines']),
                'footnotes' => $footnotes,
                'page_breaks' => $item['page_breaks'],
                'parent_key' => $item['parent_key'],
                'flags' => $flags,
            ];
        }

        return $out;
    }
}
