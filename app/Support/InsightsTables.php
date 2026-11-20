<?php

namespace App\Support;

/**
 * The figures of each chart of the insights page as plain tables (backlog item 152, step 14.262), shown folded
 * under the chart and downloaded as CSV, so a reader can take the numbers a chart is drawn from. They are read
 * from the data of the page, whole or of a scope, with no query of their own.
 *
 * A table is ['title', 'file', 'columns' => [[label, type]], 'rows' => [[value]]]; a type is `text`, `num` (a
 * count), `year` (no thousands separator), `dec` (one decimal) or `pct`.
 */
class InsightsTables
{
    private array $data;

    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /** @return array<string, array<int, array>> the tables of each chart, by the key of its note (InsightsNotes) */
    public static function for(array $data): array
    {
        $tables = new self($data);
        $all = [];
        foreach (['timeline', 'authors', 'works', 'copyGap', 'places', 'movement', 'subjects', 'features', 'scribes', 'libraries', 'dedications',
            'patrons', 'facsimiles', 'sizes', 'languageScripts', 'topWorks', 'copiesPerWork', 'centuryWorks', 'unprinted', 'collections',
            'scripts', 'languages', 'sources', 'sourceOverlap'] as $chart) {
            $all[$chart] = array_values(array_filter($tables->$chart(), fn ($t) => $t['rows']));
        }

        return $all;
    }

    /** The libraries of the first-hand coverage (FirsthandCoverage, step 14.256), which is not part of the cached data */
    public static function firsthand(array $coverage): array
    {
        return array_values(array_filter([self::table('سهم نسخه‌های سنجیده با فهرست چاپی در هر کتابخانه', 'firsthand',
            [['کتابخانه', 'text'], ['شهر', 'text'], ['نسخۀ تأییدشده', 'num'], ['نسخۀ سنجیده', 'num'], ['همۀ نسخه‌ها', 'num'], ['سهم تأییدشده', 'pct']],
            array_map(fn ($r) => [$r['name'], $r['city'], $r['complete'], $r['read'], $r['copies'], $r['share']], $coverage['libraries']))], fn ($t) => $t['rows']));
    }

    private static function table(string $title, string $file, array $columns, array $rows): array
    {
        return ['title' => $title, 'file' => $file, 'columns' => $columns, 'rows' => array_values($rows)];
    }

    private static function band(array $band, string $unit): string
    {
        return match (true) {
            $band['upto'] === null => 'بیش از ' . Fa::digits($band['above']) . ' ' . $unit,
            $band['upto'] === $band['above'] + 1 => Fa::digits($band['upto']) . ' ' . $unit,
            default => Fa::digits($band['above'] + 1) . ' تا ' . Fa::digits($band['upto']) . ' ' . $unit,
        };
    }

    /** The four layers of a chart of centuries (Insights::centuryLayers), and its decades where it has them */
    private function layers(array $layers, string $unit, string $file, bool $decades = false): array
    {
        $rows = array_filter($layers['centuries'], fn ($c) => $c['count'] > 0);
        $tables = [self::table("شمار {$unit} در هر سده", $file,
            [['سدۀ هجری', 'year'], ['سال معین', 'num'], ['سال معین، مردد', 'num'], ['تنها سده یا بخشی از آن', 'num'], ['تنها سده، مردد', 'num'], ['جمع', 'num']],
            array_map(fn ($c) => [$c['century'], $c['exact'], $c['exact_doubtful'], $c['range'], $c['range_doubtful'], $c['count']], $rows))];
        if ($decades) {
            $list = [];
            foreach ($rows as $c) {
                foreach ($c['decades'] as $i => $n) {
                    if ($n + $c['decades_doubtful'][$i] > 0) {
                        $from = ($c['century'] - 1) * 100 + $i * 10 + 1;
                        $list[] = [$c['century'], $from, $from + 9, $n, $c['decades_doubtful'][$i], $n + $c['decades_doubtful'][$i]];
                    }
                }
            }
            $tables[] = self::table("شمار {$unit} سال‌دار در هر دهه", $file . '-decades',
                [['سدۀ هجری', 'year'], ['از سال', 'year'], ['تا سال', 'year'], ['قطعی', 'num'], ['مردد', 'num'], ['جمع', 'num']], $list);
        }

        return $tables;
    }

    /** A grid of rows per century (Insights::centuryGrid): the counts, with the copies of each century in the last row */
    private function grid(array $grid, string $what, string $title, string $file): array
    {
        $columns = [[$what, 'text'], ['جمع', 'num']];
        foreach ($grid['centuries'] as $c) {
            $columns[] = ['سدۀ ' . Fa::digits($c['century']), 'num'];
        }
        $rows = array_map(fn ($row) => [$row['name'], $row['total'], ...array_map(fn ($cell) => $cell['count'], array_values($row['cells']))], $grid['rows']);
        if ($rows) {
            $rows[] = ['همۀ نسخه‌های سده', $grid['counted'], ...array_column($grid['centuries'], 'count')];
        }

        return self::table($title, $file, $columns, $rows);
    }

    private function timeline(): array
    {
        return $this->layers($this->data['timeline'], 'نسخه‌ها', 'copies-by-century', decades: true);
    }

    private function authors(): array
    {
        return $this->layers($this->data['authors'], 'مؤلفان (بر پایۀ وفات)', 'authors-by-century');
    }

    private function works(): array
    {
        return $this->layers($this->data['compositions'], 'آثار (بر پایۀ تألیف)', 'works-by-century');
    }

    private function copyGap(): array
    {
        $name = fn (array $band) => match (true) {
            $band['above'] === null => 'بیش از ' . Fa::digits(-$band['upto']) . ' سال پیش از وفات',
            $band['upto'] === 0 => 'در زمان حیات مؤلف',
            $band['upto'] === null => 'بیش از ' . Fa::digits($band['above']) . ' سال پس از وفات',
            default => Fa::digits($band['above'] + 1) . ' تا ' . Fa::digits($band['upto']) . ' سال پس از وفات',
        };

        return [self::table('فاصلۀ کتابت تا وفات مؤلف', 'copy-gap', [['فاصله', 'text'], ['قطعی', 'num'], ['مردد', 'num'], ['جمع', 'num']],
            array_map(fn ($b) => [$name($b), $b['certain'], $b['doubtful'], $b['count']], $this->data['copy_gap']['bands']))];
    }

    private function places(): array
    {
        [$first, $last] = config('insights.centuries');
        $centuries = range($first, $last);
        $columns = fn (string $all) => [['شهر', 'text'], [$all, 'num'], ...array_map(fn ($c) => ['سدۀ ' . Fa::digits($c), 'num'], $centuries)];
        $rows = fn (string $count, string $per) => array_map(
            fn ($city) => [$city['name'], $city[$count], ...array_map(fn ($c) => $city[$per][$c] ?? 0, $centuries)],
            array_filter($this->data['places']['cities'], fn ($city) => $city[$count] > 0));
        $works = $rows('works', 'work_centuries');
        usort($works, fn ($a, $b) => $b[1] <=> $a[1]);

        return [
            self::table('نسخه‌ها بر پایۀ محل کتابت', 'copy-places', $columns('همۀ نسخه‌ها'), $rows('count', 'centuries')),
            self::table('آثار بر پایۀ محل تألیف', 'composition-places', $columns('همۀ آثار'), $works),
        ];
    }

    private function movement(): array
    {
        $rows = [];
        foreach ($this->data['movement']['origins'] as $origin) {
            foreach ($origin['items'] as $to) {
                $rows[] = [$origin['name'], $to['name'], $to['count']];
            }
            if ($origin['other'] > 0) {
                $rows[] = [$origin['name'], 'سایر (' . Fa::num($origin['other_kinds']) . ' شهر)', $origin['other']];
            }
        }

        return [self::table('از محل کتابت تا شهر کتابخانۀ امروز', 'movement', [['محل کتابت', 'text'], ['شهر امروز', 'text'], ['نسخه', 'num']], $rows)];
    }

    private function subjects(): array
    {
        return [$this->grid($this->data['subjects'], 'موضوع', 'نسخه‌های هر موضوع در هر سده', 'subjects-by-century')];
    }

    private function features(): array
    {
        return [$this->grid($this->data['features'], 'ویژگی', 'نسخه‌های هر ویژگی در هر سده', 'features-by-century')];
    }

    private function scribes(): array
    {
        return [self::table('کاتبان پرکار', 'scribes', [['کاتب', 'text'], ['نسخه', 'num'], ['نخستین سال', 'year'], ['واپسین سال', 'year'], ['شهر', 'text']],
            array_map(fn ($r) => [$r['name'], $r['count'], $r['from'], $r['to'], $r['city']], $this->data['scribes']['items']))];
    }

    private function libraries(): array
    {
        $lb = $this->data['libraries'];
        $columns = [['کتابخانه', 'text'], ['شهر', 'text'], ['نسخه', 'num'], ['نسخۀ تاریخ‌دار', 'num'], ['میانگین سال کتابت', 'year']];
        $rows = fn (array $list) => array_map(fn ($r) => [$r['name'], $r['city'], $r['count'], $r['dated'], $r['mean']], $list);

        return [
            self::table('بزرگ‌ترین گنجینه‌ها', 'libraries-largest', $columns, $rows($lb['largest'])),
            self::table('کهن‌ترین گنجینه‌ها', 'libraries-oldest', $columns, $rows($lb['oldest'])),
        ];
    }

    private function dedications(): array
    {
        $dd = $this->data['dedications'];

        return [
            self::table('کسانی که اثری به نام یا به درخواستشان نوشته شده', 'dedicatees', [['نام', 'text'], ['اثر', 'num'], ['به درخواست', 'num']],
                array_map(fn ($r) => [$r['name'], $r['count'], $r['asked']], $dd['items'])),
            self::table('آثار تقدیم‌شده یا سفارشی در هر سدۀ تألیف', 'dedications-by-century', [['سدۀ هجری', 'year'], ['اثرِ تاریخ‌دار', 'num'], ['تقدیم‌شده یا سفارشی', 'num'], ['سهم', 'pct']],
                array_map(fn ($c) => [$c['century'], $c['works'], $c['count'], $c['share']], array_filter($dd['centuries'], fn ($c) => $c['works'] > 0))),
        ];
    }

    private function patrons(): array
    {
        $pt = $this->data['patrons'];

        return [
            self::table('سفارش‌دهندگان کتابت', 'patrons', [['نام', 'text'], ['نسخه', 'num']], array_map(fn ($r) => [$r['name'], $r['count']], $pt['items'])),
            self::table('نسخه‌های دارای سفارش‌دهنده در هر سده', 'patrons-by-century', [['سدۀ هجری', 'year'], ['نسخه', 'num'], ['دارای سفارش‌دهنده', 'num'], ['سهم', 'pct']],
                array_map(fn ($c) => [$c['century'], $c['copies'], $c['count'], $c['share']], array_filter($pt['centuries'], fn ($c) => $c['copies'] > 0))),
        ];
    }

    private function facsimiles(): array
    {
        return [self::table('نسخه‌های عکسی هر کتابخانه', 'facsimiles', [['کتابخانه', 'text'], ['شهر', 'text'], ['نسخۀ عکسی', 'num'], ['همۀ نسخه‌ها', 'num'], ['سهم', 'pct']],
            array_map(fn ($r) => [$r['name'], $r['city'], $r['count'], $r['holdings'], $r['share']], $this->data['facsimiles']['libraries']))];
    }

    private function sizes(): array
    {
        $sz = $this->data['sizes'];

        return [
            self::table('نسخه‌ها بر پایۀ شمار برگ', 'folios', [['شمار برگ', 'text'], ['نسخه', 'num']],
                array_map(fn ($b) => [self::band($b, 'برگ'), $b['count']], $sz['bands'])),
            self::table('میانگین برگ و سطر نسخه‌های هر سده', 'folios-by-century',
                [['سدۀ هجری', 'year'], ['میانگین برگ', 'num'], ['نسخه با شمار برگ', 'num'], ['میانگین سطر', 'dec'], ['نسخه با شمار سطر', 'num']],
                array_map(fn ($c) => [$c['century'], $c['folios'], $c['with_folios'], $c['lines'], $c['with_lines']],
                    array_filter($sz['centuries'], fn ($c) => $c['with_folios'] + $c['with_lines'] > 0))),
        ];
    }

    private function languageScripts(): array
    {
        $ls = $this->data['language_scripts'];

        return [
            $this->grid($ls['languages'], 'زبان', 'نسخه‌های هر زبان در هر سده', 'languages-by-century'),
            $this->grid($ls['scripts'], 'خط', 'نسخه‌های هر خط در هر سده', 'scripts-by-century'),
        ];
    }

    private static function workRows(array $items): array
    {
        return array_map(fn ($w, $i) => [$i + 1, $w['title'], $w['author'], $w['count']], $items, array_keys($items));
    }

    private const WORK_COLUMNS = [['ردیف', 'year'], ['اثر', 'text'], ['پدیدآور', 'text'], ['نسخه', 'num']];

    private function topWorks(): array
    {
        return [self::table('آثار پرنسخه', 'top-works', self::WORK_COLUMNS, self::workRows($this->data['top_works']['items']))];
    }

    private function copiesPerWork(): array
    {
        return [self::table('آثار بر پایۀ شمار نسخه', 'copies-per-work', [['شمار نسخۀ هر اثر', 'text'], ['اثر', 'num'], ['نسخه', 'num']],
            array_map(fn ($b) => [$b['upto'] === 1 ? 'تک‌نسخه' : self::band($b, 'نسخه'), $b['works'], $b['copies']],
                array_filter($this->data['copies_per_work']['bands'], fn ($b) => $b['works'] > 0)))];
    }

    private function centuryWorks(): array
    {
        $rows = [];
        foreach ($this->data['century_works']['centuries'] as $col) {
            foreach ($col['items'] as $i => $w) {
                $rows[] = [$col['century'], $i + 1, $w['title'], $w['author'], $w['count'], $col['count']];
            }
        }

        return [self::table('پرنسخه‌ترین آثار هر سده', 'works-of-each-century',
            [['سدۀ هجری', 'year'], ['ردیف', 'year'], ['اثر', 'text'], ['پدیدآور', 'text'], ['نسخه در آن سده', 'num'], ['همۀ نسخه‌های سده', 'num']], $rows)];
    }

    private function unprinted(): array
    {
        return [self::table('آثار پرنسخۀ بی نشانی چاپ', 'no-note-of-printing', self::WORK_COLUMNS, self::workRows($this->data['unprinted']['items']))];
    }

    private function collections(): array
    {
        $col = $this->data['collections'];

        return [
            self::table('مجموعه‌ها بر پایۀ شمار رساله', 'bound-volumes', [['شمار رساله', 'text'], ['مجموعه', 'num'], ['رساله', 'num']],
                array_map(fn ($b) => [self::band($b, 'رساله'), $b['volumes'], $b['copies']], array_filter($col['bands'], fn ($b) => $b['volumes'] > 0))),
            self::table('آثاری که با هم در یک مجلد آمده‌اند', 'bound-together', [['اثر یکم', 'text'], ['پدیدآور', 'text'], ['اثر دوم', 'text'], ['پدیدآور', 'text'], ['مجموعه', 'num']],
                array_map(fn ($p) => [$p['works'][0]['title'], $p['works'][0]['author'], $p['works'][1]['title'], $p['works'][1]['author'], $p['count']], $col['pairs'])),
        ];
    }

    private function scripts(): array
    {
        $s = $this->data['scripts'];
        $rows = array_map(fn ($r) => [$r['name'], $r['count']], $s['items']);
        if ($s['other'] > 0) {
            $rows[] = ['سایر (' . Fa::num($s['other_kinds']) . ' گونه)', $s['other']];
        }

        return [self::table('نسخه‌ها بر پایۀ خط اصلی', 'scripts', [['خط', 'text'], ['نسخه', 'num']], $rows)];
    }

    private function languages(): array
    {
        $l = $this->data['languages'];
        $rows = array_map(fn ($r) => [$r['name'], $r['works'], $r['manuscripts']], $l['items']);
        if ($l['other']['works'] > 0) {
            $rows[] = ['سایر (' . Fa::num($l['other']['kinds']) . ' زبان)', $l['other']['works'], $l['other']['manuscripts']];
        }

        return [self::table('آثار و نسخه‌های هر زبان', 'languages', [['زبان', 'text'], ['اثر', 'num'], ['نسخه', 'num']], $rows)];
    }

    private function sources(): array
    {
        $src = $this->data['sources'];
        $rows = array_map(fn ($r) => [$r['title'], $r['volume'], $r['library'], $r['count']], $src['items']);
        if ($src['other'] > 0) {
            $rows[] = ['سایر (' . Fa::num($src['other_kinds']) . ' جلد فهرست)', null, null, $src['other']];
        }

        return [self::table('نسخه‌های هر جلد فهرست', 'catalogue-volumes', [['فهرست', 'text'], ['جلد', 'text'], ['کتابخانه', 'text'], ['نسخه', 'num']], $rows)];
    }

    private function sourceOverlap(): array
    {
        $ov = $this->data['source_overlap'];
        $names = ['تنها در فنخا', 'یک فهرست', 'دو فهرست', 'سه فهرست و بیشتر'];

        return [
            self::table('نسخه‌ها بر پایۀ شمار فهرست‌های کتابخانه', 'catalogues-per-copy', [['شمار فهرست', 'text'], ['نسخه', 'num']],
                array_map(fn ($k) => [$names[$k], $ov['counts'][$k]], array_keys($names))),
            self::table('فهرست‌هایی که نسخۀ مشترک دارند', 'catalogue-pairs', [['فهرست یکم', 'text'], ['فهرست دوم', 'text'], ['نسخۀ مشترک', 'num']],
                array_map(fn ($p) => [$p['sources'][0]['title'], $p['sources'][1]['title'], $p['count']], $ov['pairs'])),
        ];
    }
}
