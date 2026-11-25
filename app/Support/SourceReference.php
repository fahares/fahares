<?php

namespace App\Support;

use App\Models\Source;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use Carbon\CarbonInterface;

/**
 * The citation of a place in a printed source (step 14.389, backlog item 132): the whole book, one volume's page, or one
 * entry. Each form the citation page offers is built from the same parts: who made it, the title, the printing, the
 * place, the publisher, the year, the volume and the page or entry. A part the site has not recorded is left out, never
 * written as «-», and missing() names it, so the page can say so.
 *
 * A journal (Source::isPeriodical(), step 14.394) is cited as journal articles are: the article that holds the page
 * (its volume's `metadata.articles`, each from its first page to the next one's), its author, the journal, the issue
 * («السنة ۲، العدد ۲ و ۳ (۷ و ۸)») and the year; place and publisher are not asked for.
 */
final class SourceReference
{
    /** The parts a printed book's citation should have, by name, for missing(). */
    public const PARTS = ['authors' => 'پدیدآور', 'place' => 'محل نشر', 'publisher' => 'ناشر', 'year' => 'سال چاپ'];

    public function __construct(
        public readonly Source $source,
        public readonly ?SourceVolume $volume = null,
        public readonly ?int $page = null,
        public readonly ?SourceItem $item = null,
    ) {}

    public static function forPage(PrintedPage $printed): self
    {
        return new self($printed->source, $printed->volume, $printed->page);
    }

    public static function forItem(SourceItem $item): self
    {
        return new self($item->source, $item->sourceVolume, $item->page_start, $item);
    }

    /** Who made the book: its compiler, or else those who catalogued the volume (a library's catalogue), «زیر نظر» last. */
    public function authors(): ?string
    {
        if ($this->source->isPeriodical()) {
            $article = $this->article();

            return $article && empty($article['role']) && filled($article['author'] ?? null) ? trim($article['author']) : null;
        }
        if (filled($this->source->compiler)) {
            return trim($this->source->compiler);
        }
        if (! $this->volume) {
            return null;
        }
        $people = $this->volume->catalogers->sortBy(fn ($c) => $c->pivot->role === 'supervisor' ? 1 : 0)->values();
        $makers = $people->filter(fn ($c) => $c->pivot->role !== 'supervisor')->pluck('name')->filter()->unique()->values();
        $supervisors = $people->filter(fn ($c) => $c->pivot->role === 'supervisor')->pluck('name')->filter()->unique()->values();
        if ($makers->isEmpty() && $supervisors->isEmpty()) {
            return null;
        }
        $made = $makers->count() > 1 ? $makers->slice(0, -1)->implode('، ') . ' و ' . $makers->last() : $makers->first();

        return trim(implode('، ', array_filter([$made, $supervisors->isNotEmpty() ? 'زیر نظر ' . $supervisors->implode(' و ') : null])));
    }

    public function title(): string
    {
        return trim($this->source->title);
    }

    /** The name the in-text citation uses: «فهرستواره منزوی». */
    public function shortTitle(): string
    {
        return trim($this->source->short_name ?: $this->source->title);
    }

    public function edition(): ?string
    {
        return $this->filled($this->volume?->edition_label) ?? $this->filled($this->source->edition_label);
    }

    public function place(): ?string
    {
        return $this->filled($this->volume?->publication_place) ?? $this->filled($this->source->publication_place);
    }

    public function publisher(): ?string
    {
        return $this->filled($this->volume?->publisher) ?? $this->filled($this->source->publisher);
    }

    /** The year of the printing; a volume never printed («مخطوط») has none. */
    public function year(): ?string
    {
        $year = $this->filled($this->volume?->publication_year) ?? $this->filled($this->source->publication_year);

        return $year === Source::UNPUBLISHED ? null : $year;
    }

    /** A volume the registry marks never printed («مخطوط»): its citation says so instead of a year. */
    public function unpublished(): bool
    {
        return ($this->filled($this->volume?->publication_year) ?? $this->filled($this->source->publication_year)) === Source::UNPUBLISHED;
    }

    /**
     * The journal article that holds the cited page: `title`, `author`, `role` («تحقيق» for an edited text, whose
     * editor follows the title), `from` and `to` (its pages).
     *
     * @return array{title: string, author: ?string, role: ?string, from: int, to: ?int}|null
     */
    public function article(): ?array
    {
        if (! $this->source->isPeriodical() || ! $this->volume || ! $this->page) {
            return null;
        }
        $articles = collect($this->volume->metadata['articles'] ?? [])->sortBy('page')->values();
        $i = $articles->search(fn ($a, $k) => $a['page'] <= $this->page && ($articles[$k + 1]['page'] ?? PHP_INT_MAX) > $this->page);
        if ($i === false) {
            return null;
        }
        $next = $articles[$i + 1]['page'] ?? null;

        return ['title' => trim($articles[$i]['title']), 'author' => $articles[$i]['author'] ?? null, 'role' => $articles[$i]['role'] ?? null,
            'from' => (int) $articles[$i]['page'], 'to' => $next ? $next - 1 : ($this->volume->page_last ?: null)];
    }

    /** «تحقيق فارس حسّون كريم», for an edited text in a journal. */
    private function articleEditor(): ?string
    {
        $article = $this->article();

        return $article && filled($article['role'] ?? null) && filled($article['author'] ?? null) ? $article['role'] . ' ' . trim($article['author']) : null;
    }

    /** «السنة 2، العدد 2 و 3 (7 و 8)»: the issue, from what its volume's title says after « - ». */
    public function issueLabel(): ?string
    {
        if (! $this->volume) {
            return null;
        }
        $title = (string) $this->volume->title;
        $label = str_contains($title, ' - ') ? trim(substr($title, strrpos($title, ' - ') + 3)) : null;

        return $label ?: ($this->volume->volume_number ? 'العدد ' . $this->volume->volume_number : null);
    }

    /** «ج ۵», when a volume is cited; a journal's issue. */
    public function volumeLabel(): ?string
    {
        if ($this->source->isPeriodical()) {
            return $this->issueLabel();
        }

        return $this->volume?->volume_number ? 'ج ' . $this->volume->volume_number : null;
    }

    /** «ص ۳۳۶۶», «ستون ۱۲», or an entry's «ص ۴۰۲–۴۰۳، ش ۱۴۴۲»; the entry without a number by its heading. */
    public function locator(): ?string
    {
        $unit = $this->source->unit === 'ستون' ? 'ستون' : 'ص';
        if ($this->item) {
            $item = $this->item;
            $pages = $item->page_start ? $unit . ' ' . $item->page_start . ($item->page_end > $item->page_start ? '–' . $item->page_end : '') : null;

            return implode('، ', array_filter([
                $pages,
                $item->entry_number ? 'ش ' . $item->entry_number : (filled($item->title) ? 'ذیل «' . trim($item->title) . '»' : null),
            ])) ?: null;
        }

        return $this->page ? $unit . ' ' . $this->page : null;
    }

    /**
     * The page on the site this citation stands for: the entry's, the printed page's text, or the source's (a page the
     * site has as an image only has no address of its own).
     */
    public function url(): string
    {
        return $this->url ??= match (true) {
            $this->item !== null => $this->item->url(),
            $this->volume !== null && $this->page !== null && PrintedPage::exists($this->volume, $this->page)
                => route('source-pages.show', [$this->source->code, $this->volume->volume_number, $this->page]),
            default => route('sources.show', $this->source->code),
        };
    }

    private ?string $url = null;

    /** What is cited, for headings: «فهرستواره کتاب‌های فارسی، ج ۵، ص ۳۳۶۶». */
    public function label(): string
    {
        return Fa::digits(implode('، ', array_filter([$this->title(), $this->volumeLabel(), $this->locator()])));
    }

    /** @return list<string> the parts of the printed book the site has not recorded */
    public function missing(): array
    {
        if ($this->source->isPeriodical()) {
            return $this->year() === null ? [self::PARTS['year']] : [];
        }

        return array_values(array_filter(self::PARTS, fn ($label, $part) => $this->{$part}() === null
            && ! ($this->unpublished() && in_array($part, ['place', 'year'], true)), ARRAY_FILTER_USE_BOTH));
    }

    /** The printed book: «احمد منزوی، فهرستوارۀ کتاب‌های فارسی، ج ۵، تهران: مرکز دائرة‌المعارف بزرگ اسلامی، ۱۳۸۲، ص ۳۳۶۶.» */
    public function printed(): string
    {
        if ($this->source->isPeriodical()) {
            return $this->sentence([$this->authors(), $this->articleTitle(), $this->articleEditor(), $this->title(), $this->volumeLabel(), $this->year(), $this->locator()]);
        }

        return $this->sentence([
            $this->authors(),
            $this->title(),
            $this->edition() ? 'چاپ ' . $this->edition() : null,
            $this->volumeLabel(),
            $this->imprint(),
            $this->yearUnlessInEdition(),
            $this->locator(),
        ]);
    }

    /** The page on Fahares: «…، ج ۵، ص ۳۳۶۶، فهارس، https://fahares.net/…، دیده‌شده در ۱۴۰۵/۰۹/۰۴.» */
    public function fahares(?CarbonInterface $seen = null): string
    {
        $head = Fa::digits(implode('، ', array_filter([$this->authors(), $this->articleTitle(), $this->title(), $this->volumeLabel(), $this->locator(), 'فهارس'])));

        return $head . '، ' . $this->url() . '، دیده‌شده در ' . Jalali::format($seen ?? now()) . '.';
    }

    /** In the text: «(فهرستواره منزوی، ج ۵، ص ۳۳۶۶)». */
    public function inText(): string
    {
        if ($this->source->isPeriodical() && $this->authors()) {
            return '(' . Fa::digits(implode('، ', array_filter([$this->authors(), $this->year(), $this->locator()]))) . ')';
        }

        return '(' . Fa::digits(implode('، ', array_filter([$this->shortTitle(), $this->volumeLabel(), $this->locator()]))) . ')';
    }

    /** APA's order, as Persian journals write it: «احمد منزوی. (۱۳۸۲). فهرستوارۀ کتاب‌های فارسی (ج ۵، ص ۳۳۶۶). تهران: …» */
    public function apa(): string
    {
        if ($this->source->isPeriodical()) {
            $article = $this->article();

            return Fa::digits(implode(' ', array_filter([
                $this->authors() ? $this->authors() . '.' : null,
                '(' . ($this->year() ?? 'بی‌تا') . ').',
                $article ? $article['title'] . ($this->articleEditor() ? '، ' . $this->articleEditor() : '') . '.' : null,
                implode('، ', array_filter([$this->title(), $this->volumeLabel(), $this->articlePages() ?? $this->locator()])) . '.',
            ])));
        }
        $where = implode('، ', array_filter([$this->volumeLabel(), $this->locator()]));

        return Fa::digits(implode(' ', array_filter([
            $this->authors() ? $this->authors() . '.' : null,
            '(' . ($this->year() ?? ($this->unpublished() ? 'چاپ‌نشده' : 'بی‌تا')) . ').',
            $this->title() . ($where !== '' ? ' (' . $where . ')' : '') . '.',
            $this->imprint() ? $this->imprint() . '.' : null,
        ])));
    }

    /** Chicago's order for a book: «احمد منزوی. فهرستوارۀ کتاب‌های فارسی. ج ۵. تهران: …، ۱۳۸۲. ص ۳۳۶۶.» */
    public function chicago(): string
    {
        if ($this->source->isPeriodical()) {
            $issue = trim(implode(' ', array_filter([$this->title(), $this->volumeLabel(), $this->year() ? '(' . $this->year() . ')' : null])));
            $pages = $this->articlePages() ?? $this->locator();

            return Fa::digits(implode(' ', array_filter([
                $this->authors() ? $this->authors() . '.' : null,
                $this->articleTitle() ? $this->articleTitle() . ($this->articleEditor() ? '، ' . $this->articleEditor() : '') . '.' : null,
                $issue . ($pages ? ': ' . $pages : '') . '.',
            ])));
        }
        $imprint = implode('، ', array_filter([$this->imprint(), $this->yearUnlessInEdition()]));

        return Fa::digits(implode(' ', array_filter([
            $this->authors() ? $this->authors() . '.' : null,
            $this->title() . '.',
            $this->edition() ? 'چاپ ' . $this->edition() . '.' : null,
            $this->volumeLabel() ? $this->volumeLabel() . '.' : null,
            $imprint !== '' ? $imprint . '.' : null,
            $this->locator() ? $this->locator() . '.' : null,
        ])));
    }

    /** For Zotero, JabRef and the like; a journal's page as @article (or @periodical, outside any article). */
    public function bibtex(?CarbonInterface $seen = null): string
    {
        if ($this->source->isPeriodical()) {
            [$year, $number] = $this->issueParts();
            $article = $this->article();
            $fields = array_filter([
                'author' => $this->authors(),
                'editor' => $article && filled($article['role'] ?? null) ? $article['author'] : null,
                'title' => $article['title'] ?? $this->title(),
                'journal' => $article ? $this->title() : null,
                'volume' => $year,
                'number' => $number,
                'year' => $this->year(),
                'pages' => $article ? $article['from'] . ($article['to'] ? '--' . $article['to'] : '') : $this->pages(),
                'url' => $this->url(),
                'urldate' => ($seen ?? now())->toDateString(),
                'note' => 'فهارس',
            ], fn ($v) => $v !== null && $v !== '');

            return $this->bib($article ? 'article' : 'periodical', $fields);
        }
        $fields = array_filter([
            'author' => $this->authors(),
            'title' => $this->title(),
            'edition' => $this->edition(),
            'volume' => $this->volume?->volume_number,
            'pages' => $this->pages(),
            'number' => $this->item?->entry_number,
            'address' => $this->place(),
            'publisher' => $this->publisher(),
            'year' => $this->year(),
            'url' => $this->url(),
            'urldate' => ($seen ?? now())->toDateString(),
            'note' => 'فهارس',
        ], fn ($v) => $v !== null && $v !== '');
        return $this->bib('book', $fields);
    }

    private function bib(string $type, array $fields): string
    {
        $lines = collect($fields)->map(fn ($v, $k) => '  ' . $k . ' = {' . str_replace(['{', '}'], ['\\{', '\\}'], self::latin((string) $v)) . '}');

        return '@' . $type . '{' . $this->key() . ",\n" . $lines->implode(",\n") . "\n}\n";
    }

    /** For EndNote, Mendeley and Zotero. */
    public function ris(?CarbonInterface $seen = null): string
    {
        [$from, $to] = $this->pageRange();
        $tags = [['TY', 'BOOK']];
        foreach (preg_split('/\s+و\s+|،\s*/u', (string) $this->authors(), -1, PREG_SPLIT_NO_EMPTY) as $author) {
            $tags[] = ['AU', $author];
        }
        if ($this->source->isPeriodical()) {
            [$year, $number] = $this->issueParts();
            $article = $this->article();
            $tags[0] = ['TY', 'JOUR'];
            if ($article && filled($article['role'] ?? null)) {
                $tags[] = ['ED', $article['author']];
            }
            [$from, $to] = $article ? [$article['from'], $article['to']] : [$from, $to];
            $tags = array_merge($tags, [
                ['TI', $article['title'] ?? $this->title()], ['T2', $this->title()], ['VL', $year], ['IS', $number], ['SP', $from], ['EP', $to],
                ['PY', $this->year()], ['UR', $this->url()], ['Y2', ($seen ?? now())->toDateString()], ['DB', 'فهارس'], ['ER', ''],
            ]);

            return collect($tags)->filter(fn ($t) => $t[0] === 'ER' || ($t[1] !== null && $t[1] !== ''))
                ->map(fn ($t) => $t[0] . '  - ' . self::latin((string) $t[1]))->implode("\r\n") . "\r\n";
        }
        $tags = array_merge($tags, [
            ['TI', $this->title()], ['ET', $this->edition()], ['VL', $this->volume?->volume_number], ['SP', $from], ['EP', $to],
            ['M1', $this->item?->entry_number], ['CY', $this->place()], ['PB', $this->publisher()], ['PY', $this->year()],
            ['UR', $this->url()], ['Y2', ($seen ?? now())->toDateString()], ['DB', 'فهارس'], ['ER', ''],
        ]);

        return collect($tags)->filter(fn ($t) => $t[0] === 'ER' || ($t[1] !== null && $t[1] !== ''))
            ->map(fn ($t) => $t[0] . '  - ' . self::latin((string) $t[1]))->implode("\r\n") . "\r\n";
    }

    /** The file name of the downloads: «fahares-monzavi-5-3366». */
    public function key(): string
    {
        return implode('-', array_filter(['fahares', $this->source->code, $this->volume?->volume_number,
            $this->item ? $this->item->urlKey() : $this->page]));
    }

    /** «ما تبقى من مخطوطات نهج البلاغة» in quotation marks, when a journal's page is cited. */
    private function articleTitle(): ?string
    {
        $article = $this->article();

        return $article ? '«' . $article['title'] . '»' : null;
    }

    /** «ص ۱۵۰–۱۶۳»: the pages of the article. */
    private function articlePages(): ?string
    {
        $article = $this->article();

        return $article ? 'ص ' . $article['from'] . ($article['to'] && $article['to'] > $article['from'] ? '–' . $article['to'] : '') : null;
    }

    /** @return array{0: ?string, 1: ?string} the journal's year of issue («السنة 2») and number («2 و 3»), for BibTeX and RIS */
    private function issueParts(): array
    {
        $label = (string) $this->issueLabel();
        preg_match('/السنة\s*([0-9۰-۹٠-٩]+)/u', $label, $year);
        preg_match('/العدد\s*([^()،]+?)\s*(?:\(|$|،)/u', $label, $number);

        return [$year[1] ?? null, isset($number[1]) ? trim($number[1]) : ($this->volume?->volume_number ? (string) $this->volume->volume_number : null)];
    }

    /** «تهران: مرکز دائرة‌المعارف بزرگ اسلامی», or the one of the two the site has. */
    private function imprint(): ?string
    {
        return implode(': ', array_filter([$this->place(), $this->publisher()])) ?: null;
    }

    /** The year, unless the printing's label already gives it: «۱۳۸۸، به کوشش رضا استادی». */
    private function yearUnlessInEdition(): ?string
    {
        if ($this->unpublished()) {
            return 'چاپ‌نشده';
        }
        $year = $this->year();

        return $year && ! ($this->edition() && str_contains(Fa::digits($this->edition()), Fa::digits($year))) ? $year : null;
    }

    private function pages(): ?string
    {
        [$from, $to] = $this->pageRange();

        return $from ? $from . ($to ? '--' . $to : '') : null;
    }

    /** @return array{0: ?int, 1: ?int} */
    private function pageRange(): array
    {
        if ($this->item) {
            return [$this->item->page_start, $this->item->page_end > $this->item->page_start ? $this->item->page_end : null];
        }

        return [$this->page, null];
    }

    /** The parts joined with «، », Persian digits, and a full stop. */
    private function sentence(array $parts): string
    {
        return Fa::digits(implode('، ', array_filter($parts))) . '.';
    }

    private function filled(?string $value): ?string
    {
        return filled($value) ? trim($value) : null;
    }

    /** Western digits, as the reference managers read years and pages. */
    private static function latin(string $text): string
    {
        return strtr($text, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }
}
