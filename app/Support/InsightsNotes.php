<?php

namespace App\Support;

/**
 * The note under each chart of the insights page (step 14.234): how the chart is read and what it does not say
 * («reading»), and a few examples taken from the chart's own figures («examples»), so a reader sees one bar or one
 * cell put into words. The examples are built from the data of the page, whole or of a scope, so they stay true
 * when the data changes; an example whose figures are missing is left out.
 */
class InsightsNotes
{
    private array $data;

    private int $minCopies;

    private function __construct(array $data)
    {
        $this->data = $data;
        $this->minCopies = (int) config('insights.grid_min_copies');
    }

    /** @return array<string, array{reading: string[], examples: string[]}> by the id of the chart's section */
    public static function for(array $data): array
    {
        $notes = new self($data);
        $all = [];
        foreach (['timeline', 'authors', 'works', 'copyGap', 'places', 'movement', 'subjects', 'features', 'scribes', 'libraries', 'dedications',
            'patrons', 'facsimiles', 'sizes', 'languageScripts', 'topWorks', 'copiesPerWork', 'centuryWorks', 'unprinted', 'collections',
            'scripts', 'languages', 'sources', 'sourceOverlap'] as $chart) {
            [$reading, $examples] = $notes->$chart();
            $all[$chart] = ['reading' => $reading, 'examples' => array_values(array_filter($examples))];
        }

        return $all;
    }

    private static function n(int|float|null $n): string
    {
        return Fa::num($n);
    }

    private static function pct(int|float $value): string
    {
        return Fa::digits(str_replace('.', '٫', (string) $value)) . '٪';
    }

    private static function share(int|float $part, int|float $whole): string
    {
        return self::pct($whole > 0 ? round($part * 100 / $whole, 1) : 0);
    }

    private static function century(int $c): string
    {
        return 'سدۀ ' . Fa::digits($c);
    }

    /** «about three times», for a ratio worth saying */
    private static function times(int|float $larger, int|float $smaller): ?string
    {
        if ($smaller <= 0 || $larger / $smaller < 1.5) {
            return null;
        }
        $ratio = $larger / $smaller;

        return $ratio >= 10 ? Fa::num((int) round($ratio)) . ' برابر' : Fa::digits(str_replace('.', '٫', (string) round($ratio, 1))) . ' برابر';
    }

    /** The row of the largest count */
    private static function top(array $rows, string $key = 'count'): ?array
    {
        $rows = array_filter($rows, fn ($r) => ($r[$key] ?? 0) > 0);
        if (! $rows) {
            return null;
        }
        usort($rows, fn ($a, $b) => $b[$key] <=> $a[$key]);

        return $rows[0];
    }

    private function timeline(): array
    {
        $t = $this->data['timeline'];
        $peak = self::top($t['centuries']);
        $examples = [];
        if ($peak) {
            $examples[] = 'بلندترین ستون ' . self::century($peak['century']) . ' است، با ' . self::n($peak['count']) . ' نسخه، یعنی ' . self::share($peak['count'], $t['shown'])
                . ' همۀ نسخه‌های تاریخ‌دار. از این شمار، ' . self::n($peak['exact'] + $peak['exact_doubtful']) . ' نسخه سال معین دارند و '
                . self::n($peak['range'] + $peak['range_doubtful']) . ' نسخه تنها با سده یا بخشی از آن آمده‌اند.';
            $earlier = collect($t['centuries'])->firstWhere('century', $peak['century'] - 3);
            if ($earlier && ($times = self::times($peak['count'], $earlier['count']))) {
                $examples[] = 'شمار نسخه‌ها در ' . self::century($earlier['century']) . ' برابر ' . self::n($earlier['count']) . ' است؛ پس نسخه‌های ' . self::century($peak['century']) . ' نزدیک به ' . $times . ' آن است.';
            }
            $decades = array_map(fn ($a, $b) => $a + $b, $peak['decades'], $peak['decades_doubtful']);
            if (max($decades) > 0) {
                $i = array_search(max($decades), $decades, true);
                $from = ($peak['century'] - 1) * 100 + $i * 10 + 1;
                $examples[] = 'در نمودار دهه‌ها، پرنسخه‌ترین دهۀ ' . self::century($peak['century']) . ' سال‌های ' . Fa::digits($from) . ' تا ' . Fa::digits($from + 9)
                    . ' است، با ' . self::n(max($decades)) . ' نسخۀ سال‌دار.';
            }
        }

        return [[
            'هر ستون یک سدۀ هجری است و بلندی‌اش شمار نسخه‌هایی که در آن سده کتابت شده‌اند. بخش پررنگ ستون نسخه‌هایی است که سال کتابتشان معلوم است، و بخش طلایی نسخه‌هایی که فهرست‌نگار تنها سده‌شان را گفته است. رنگ کم‌رنگ‌تر هر بخش، تاریخ‌هایی است که فهرست با تردید آورده است.',
            'این نمودار شمار نسخه‌های بازمانده و فهرست‌شده را نشان می‌دهد، نه شمار همۀ نسخه‌هایی که در آن سده نوشته شده‌اند. نسخۀ کهن‌تر بیشتر از میان رفته است، پس کوتاهی ستون سده‌های نخستین تنها از کمی کتابت نیست. در سدۀ چهاردهم هم با فراگیر شدن چاپ، استنساخ کم شد.',
        ], $examples];
    }

    private function authors(): array
    {
        $a = $this->data['authors'];
        $peak = self::top($a['centuries']);
        $examples = [];
        if ($peak) {
            $examples[] = 'پرشمارترین ستون ' . self::century($peak['century']) . ' است: ' . self::n($peak['count']) . ' مؤلف در این سده درگذشته‌اند، یعنی '
                . self::share($peak['count'], $a['shown']) . ' مؤلفانی که در نمودار آمده‌اند.';
            if ($peak['range'] + $peak['range_doubtful'] > 0) {
                $examples[] = 'از همین ستون، وفات ' . self::n($peak['range'] + $peak['range_doubtful']) . ' مؤلف تنها با سده ثبت شده است (بخش طلایی) و سال دقیقش دانسته نیست.';
            }
        }
        $examples[] = 'از ' . self::n($a['total']) . ' مؤلف این آمار، تاریخ وفات ' . self::n($a['counted']) . ' نفر (' . self::pct($a['coverage']) . ') ثبت است؛ بقیه در هیچ ستونی نیستند.';

        return [[
            'هر مؤلف در سده‌ای شمرده می‌شود که در آن درگذشته است، نه سده‌ای که در آن زیسته یا نوشته است. پس کسی که بیشتر عمرش در سدۀ دوازدهم گذشته و در سال ۱۲۰۵ درگذشته، در ستون سدۀ سیزدهم است.',
            '«مؤلف» در اینجا هر کسی است که دست‌کم یک اثر از او در فهارس آمده باشد. مؤلفی که آثار بسیار دارد و مؤلفی که یک رساله دارد، هر کدام یک بار شمرده می‌شوند.',
        ], $examples];
    }

    private function works(): array
    {
        $w = $this->data['compositions'];
        $peak = self::top($w['centuries']);
        $examples = [];
        if ($peak) {
            $examples[] = 'بیشترین آثارِ تاریخ‌دار از ' . self::century($peak['century']) . ' است: ' . self::n($peak['count']) . ' اثر، یعنی '
                . self::share($peak['count'], $w['shown']) . ' آثاری که در نمودار آمده‌اند.';
        }
        $examples[] = 'تنها ' . self::pct($w['coverage']) . ' آثار (' . self::n($w['counted']) . ' اثر) تاریخ تألیف دارند، پس این نمودار نمونه‌ای از آثار است، نه همۀ آن‌ها.';

        return [[
            'هر اثر در سده‌ای شمرده می‌شود که تألیف شده است، هر جا فهرست تاریخ تألیف را آورده باشد. این نمودار را با نمودار وفات مؤلفان یکی نگیرید: آن یکی اشخاص را می‌شمارد و این یکی آثار را، و پوشش دو نمودار بسیار فرق دارد.',
            'فهرست‌نگاران تاریخ تألیف آثار متأخر را بیشتر ثبت کرده‌اند، چون مؤلفان متأخر خود بیشتر در پایان اثر تاریخ گذاشته‌اند. پس بلندی ستون‌های متأخر تا اندازه‌ای از همین است.',
        ], $examples];
    }

    private function copyGap(): array
    {
        $g = $this->data['copy_gap'];
        $examples = [];
        $name = fn (array $band) => match (true) {
            $band['above'] === null => 'بیش از ' . Fa::digits(-$band['upto']) . ' سال پیش از وفات',
            $band['upto'] === 0 => 'در زمان حیات مؤلف',
            $band['upto'] === null => 'بیش از ' . Fa::digits($band['above']) . ' سال پس از وفات',
            default => Fa::digits($band['above'] + 1) . ' تا ' . Fa::digits($band['upto']) . ' سال پس از وفات',
        };
        if ($g['placed'] > 0) {
            $peak = self::top($g['bands']);
            $examples[] = 'پرشمارترین ردیف «' . $name($peak) . '» است: ' . self::n($peak['count']) . ' نسخه، یعنی ' . self::share($peak['count'], $g['placed']) . ' نسخه‌های این نمودار.';
            $lifetime = collect($g['bands'])->firstWhere('upto', 0);
            $century = array_sum(array_column(array_filter($g['bands'], fn ($b) => $b['above'] !== null && $b['upto'] !== null && $b['upto'] <= 100), 'count'));
            if ($lifetime) {
                $examples[] = self::n($lifetime['count']) . ' نسخه (' . self::share($lifetime['count'], $g['placed']) . ') در زمان حیات مؤلف کتابت شده‌اند، و روی هم '
                    . self::share($century, $g['placed']) . ' نسخه‌ها تا صد سال پس از وفات او.';
            }
        }
        $examples[] = 'نمونۀ شمارش: نسخه‌ای با تاریخ کتابت ۱۰۸۰ از اثرِ مؤلفی که در ۱۰۳۰ درگذشته است، ۵۰ سال فاصله دارد و در ردیف «۱ تا ۵۰ سال پس از وفات» است.';

        return [[
            'هر ردیف یک بازۀ زمانی است و نوارش شمار نسخه‌هایی که در آن فاصله از وفات مؤلف کتابت شده‌اند. ردیف «در زمان حیات مؤلف» نسخه‌هایی است که تاریخ کتابتشان پیش از وفات مؤلف یا در همان سال است.',
            'هر چه نسخه‌های یک اثر به زمان مؤلف نزدیک‌تر باشند، برای تصحیح متن ارزش بیشتری دارند. ردیف‌های دور نشان می‌دهند که متن تا چند سده پس از مؤلف هنوز خوانده و استنساخ می‌شده است.',
        ], $examples];
    }

    private function places(): array
    {
        $p = $this->data['places'];
        $cities = $p['cities'];
        $examples = [];
        $first = self::top($cities);
        if ($first) {
            $text = 'بزرگ‌ترین دایره ' . $first['name'] . ' است، با ' . self::n($first['count']) . ' نسخه، یعنی ' . self::share($first['count'], $p['mapped']) . ' نسخه‌های روی نقشه.';
            if ($first['centuries']) {
                $c = array_search(max($first['centuries']), $first['centuries'], true);
                $text .= ' اوج کتابت در این شهر ' . self::century((int) $c) . ' است، با ' . self::n($first['centuries'][$c]) . ' نسخه.';
            }
            $examples[] = $text;
        }
        // The first city of the earliest and of the latest century with enough copies, when they differ
        $leaders = [];
        foreach ($cities as $city) {
            foreach ($city['centuries'] as $c => $n) {
                if (! isset($leaders[$c]) || $n > $leaders[$c][1]) {
                    $leaders[$c] = [$city['name'], $n];
                }
            }
        }
        $leaders = array_filter($leaders, fn ($l) => $l[1] >= (int) config('insights.min_sample'));
        ksort($leaders);
        if (count($leaders) > 1) {
            $early = array_key_first($leaders);
            $late = array_key_last($leaders);
            $examples[] = 'با دکمه‌های سده، جابه‌جایی کانون‌ها دیده می‌شود: در ' . self::century((int) $early) . ' بیشترین نسخۀ دارای محل کتابت از ' . $leaders[$early][0] . ' است ('
                . self::n($leaders[$early][1]) . ' نسخه) و در ' . self::century((int) $late) . ' از ' . $leaders[$late][0] . ' (' . self::n($leaders[$late][1]) . ' نسخه).';
        }
        $composed = self::top($cities, 'works');
        if ($composed) {
            $examples[] = 'در لایۀ «محل تألیف»، نخستین شهر ' . $composed['name'] . ' است، با ' . self::n($composed['works']) . ' اثر. این لایه تنها بر '
                . self::n($p['composition']['mapped']) . ' اثر تکیه دارد.';
        }

        return [[
            'هر دایره یک شهر است و مساحتش به شمار نسخه‌هایی بستگی دارد که کاتب در انجامه نوشته آن‌ها را در آن شهر کتابت کرده است. با «لایه» میان محل کتابت نسخه‌ها و محل تألیف آثار جابه‌جا می‌شوید، و با «سده» تنها نسخه‌های یک سده را می‌بینید. فهرست کنار نقشه همان شهرها را به ترتیب شمار می‌آورد.',
            'بیشتر نسخه‌ها محل کتابت ندارند، پس نقشه تصویر همان بخشی است که کاتبش نام شهر را نوشته است. شهری که دایرۀ بزرگ دارد، شاید تا اندازه‌ای از آن رو بزرگ است که کاتبانش بیشتر نام شهر را می‌نوشته‌اند، یا فهرست‌نگاران کتابخانه‌هایش این آگاهی را دقیق‌تر ثبت کرده‌اند.',
        ], $examples];
    }

    private function movement(): array
    {
        $mv = $this->data['movement'];
        $examples = [];
        if ($origin = $mv['origins'][0] ?? null) {
            $away = self::top(array_filter($origin['items'], fn ($r) => $r['name'] !== $origin['name']));
            $examples[] = 'از ' . self::n($origin['total']) . ' نسخه‌ای که در ' . $origin['name'] . ' کتابت شده‌اند، ' . self::n($origin['stayed']) . ' نسخه ('
                . self::share($origin['stayed'], $origin['total']) . ') هنوز در کتابخانه‌های همان شهرند'
                . ($away ? '، و ' . self::n($away['count']) . ' نسخه (' . self::share($away['count'], $origin['total']) . ') اکنون در ' . $away['name'] . ' هستند.' : '.');
        }
        if ($destination = $mv['destinations'][0] ?? null) {
            $from = self::top(array_filter($destination['items'], fn ($r) => $r['name'] !== $destination['name']));
            if ($from) {
                $examples[] = 'در جهت دیگر: از ' . self::n($destination['total']) . ' نسخۀ دارای محل کتابت در کتابخانه‌های ' . $destination['name'] . '، ' . self::n($from['count'])
                    . ' نسخه در ' . $from['name'] . ' کتابت شده‌اند و ' . self::n($destination['stayed']) . ' نسخه در خود ' . $destination['name'] . '.';
            }
        }
        if ($mv['mapped'] > 0) {
            $examples[] = 'روی هم ' . self::pct($mv['stayed_share']) . ' نسخه‌ها در همان شهری مانده‌اند که کتابت شده‌اند، و بقیه جابه‌جا شده‌اند.';
        }

        return [[
            'این نمودار دو شهر هر نسخه را کنار هم می‌گذارد: شهری که در آن کتابت شده و شهر کتابخانه‌ای که امروز آن را دارد. در حالت «از محل کتابت» یک شهرِ کتابت را برمی‌گزینید و می‌بینید نسخه‌هایش امروز کجایند. در حالت «به شهر امروز» شهر یک کتابخانه را برمی‌گزینید و می‌بینید نسخه‌هایش از کجا آمده‌اند.',
            'نمودار از مسیر و زمان جابه‌جایی چیزی نمی‌گوید؛ تنها آغاز و پایان را دارد. نسخه‌ای که در هرات نوشته شده و امروز در مشهد است، شاید چند بار دست‌به‌دست شده باشد. کتابخانه‌های بیرون از ایران هم کمتر در فهارس‌اند، پس نسخه‌هایی که از ایران بیرون رفته‌اند در این آمار کم‌شمارند.',
        ], $examples];
    }

    /**
     * Two examples of a grid of rows per century: the largest row with its best century, and the row whose share
     * changes most between two centuries with enough copies.
     */
    private function gridExamples(array $grid, string $unit): array
    {
        $enough = array_column(array_filter($grid['centuries'], fn ($c) => $c['count'] >= $this->minCopies), 'century');
        if (! $grid['rows'] || count($enough) < 2) {
            return [];
        }
        $examples = [];
        $row = $grid['rows'][0];
        $cells = array_intersect_key($row['cells'], array_flip($enough));
        $best = array_search(max(array_column($cells, 'share')), array_column($cells, 'share', null), true);
        $c = array_keys($cells)[$best];
        $examples[] = 'پرشمارترین ردیف «' . $row['name'] . '» است، با ' . self::n($row['total']) . ' نسخه. بیشترین سهمش در ' . self::century($c) . ' است: '
            . self::n($cells[$c]['count']) . ' نسخه، یعنی ' . self::pct($cells[$c]['share']) . ' نسخه‌های آن سده.';

        $swing = null;
        foreach ($grid['rows'] as $row) {
            $cells = array_intersect_key($row['cells'], array_flip($enough));
            $shares = array_map(fn ($cell) => $cell['share'], $cells);
            $gap = max($shares) - min($shares);
            if ($swing === null || $gap > $swing[0]) {
                $swing = [$gap, $row['name'], array_search(min($shares), $shares, true), min($shares), array_search(max($shares), $shares, true), max($shares)];
            }
        }
        if ($swing && $swing[0] >= 1) {
            [, $name, $lowAt, $low, $highAt, $high] = $swing;
            // The earlier century first, as the reader follows the columns
            [$firstAt, $first, $lastAt, $last] = $lowAt < $highAt ? [$lowAt, $low, $highAt, $high] : [$highAt, $high, $lowAt, $low];
            $examples[] = 'بیشترین دگرگونی در «' . $name . '» است: سهمش از ' . $unit . ' در ' . self::century($firstAt) . ' برابر ' . self::pct($first) . ' است و در '
                . self::century($lastAt) . ' برابر ' . self::pct($last) . ($highAt > $lowAt ? '؛ یعنی در گذر سده‌ها بالا رفته است.' : '؛ یعنی در گذر سده‌ها پایین آمده است.');
        }

        return $examples;
    }

    private function subjects(): array
    {
        return [[
            'هر ردیف یک موضوع و هر ستون یک سده است. خانۀ برخورد آن دو، نسخه‌های آن موضوع را در آن سده نشان می‌دهد. در حالت «شمار» عدد نسخه‌ها را می‌بینید و در حالت «سهم» درصدشان را از همۀ نسخه‌های همان سده. هر چه خانه پررنگ‌تر باشد، عددش بزرگ‌تر است.',
            'برای سنجیدن سده‌ها با هم، حالت «سهم» را بخوانید. شمار نسخه‌ها در سده‌های متأخر در همۀ موضوع‌ها بیشتر است، چون نسخۀ بیشتری مانده است؛ ولی سهم نشان می‌دهد که از نسخه‌های هر سده چه بخشی به یک موضوع رسیده است. موضوع هر نسخه، موضوع اثر آن است.',
        ], $this->gridExamples($this->data['subjects'], 'نسخه‌های همان سده')];
    }

    private function features(): array
    {
        return [[
            'هر ردیف یک ویژگی نسخه است: مذهّب (آراسته به زر)، تصحیح‌شده، مقابله‌شده با نسخۀ دیگر، دارای حاشیه، و به خط مؤلف. هر خانه نسخه‌هایی را نشان می‌دهد که آن ویژگی را دارند و در آن سده کتابت شده‌اند. یک نسخه می‌تواند چند ویژگی داشته باشد و در چند ردیف شمرده شود.',
            'این ارقام بازتاب دقت فهرست‌نگار هم هست. فهرستی که حاشیه‌ها را همیشه یاد می‌کند، سهم «دارای حاشیه» را در نسخه‌های خودش بالا می‌برد. پس اختلاف‌های کوچک میان دو سده را نشانۀ دگرگونی واقعی نگیرید.',
        ], $this->gridExamples($this->data['features'], 'نسخه‌های همان سده')];
    }

    private function scribes(): array
    {
        $sb = $this->data['scribes'];
        $examples = [];
        $years = fn (array $row) => $row['from'] !== null
            ? ($row['from'] === $row['to'] ? 'سال ' . Fa::digits($row['from']) : 'سال‌های ' . Fa::digits($row['from']) . ' تا ' . Fa::digits($row['to']))
            : null;
        if ($first = $sb['items'][0] ?? null) {
            $examples[] = 'نخستین ردیف ' . $first['name'] . ' است، با ' . self::n($first['count']) . ' نسخه'
                . ($years($first) ? '. نسخه‌های سال‌دارش از ' . $years($first) . ' است' : '')
                . ($first['city'] ? '، و بیشترین نسخه‌اش در ' . $first['city'] . ' کتابت شده است.' : '.');
            if ($first['from'] !== null && $first['to'] - $first['from'] > 70) {
                $examples[] = 'فاصلۀ ' . Fa::digits($first['to'] - $first['from']) . ' سالۀ نسخه‌های این ردیف از عمر کاری یک کاتب بیشتر است؛ چنین بازه‌ای نشان می‌دهد که زیر این نام بیش از یک کاتب هم‌نام گرد آمده‌اند، یا تاریخ برخی نسخه‌ها نادرست است.';
            }
        }
        if ($sb['scribes'] > 0) {
            $examples[] = 'روی هم ' . self::n($sb['with_scribe']) . ' نسخه کاتبِ شناخته دارند، از ' . self::n($sb['scribes']) . ' کاتب؛ یعنی هر کاتب به‌طور میانگین '
                . Fa::digits(str_replace('.', '٫', (string) round($sb['with_scribe'] / $sb['scribes'], 1))) . ' نسخه. کاتبان این جدول بسیار بالاتر از این میانگین‌اند.';
        }

        return [[
            'جدول کاتبانی را می‌آورد که بیشترین نسخه به نامشان ثبت است. ستون «سال‌ها و شهر» بازۀ نسخه‌های سال‌دار هر کاتب و شهری را نشان می‌دهد که بیشتر در آن کتابت کرده است. روی شمار نسخه بزنید تا نسخه‌های همان کاتب را ببینید.',
            'نام‌های پرتکرار مانند «محمد حسین» یا «محمد علی» میان کاتبان بسیار است، و یک ردیف شاید چند کاتب هم‌نام را در خود داشته باشد. بازۀ سال‌ها بهترین نشانه است: بازه‌ای بلندتر از شصت یا هفتاد سال به یک نفر نمی‌خورد.',
        ], $examples];
    }

    private function libraries(): array
    {
        $lb = $this->data['libraries'];
        $total = $this->data['totals']['manuscripts'];
        $examples = [];
        if ($first = $lb['largest'][0] ?? null) {
            $examples[] = 'بزرگ‌ترین گنجینه ' . $first['name'] . ' (' . $first['city'] . ') است، با ' . self::n($first['count']) . ' نسخه، یعنی ' . self::share($first['count'], $total) . ' نسخه‌های این آمار.';
            $sum = array_sum(array_column(array_slice($lb['largest'], 0, 5), 'count'));
            if (count($lb['largest']) >= 5) {
                $examples[] = 'پنج کتابخانۀ نخست روی هم ' . self::n($sum) . ' نسخه دارند، یعنی ' . self::share($sum, $total) . ' نسخه‌ها، از میان ' . self::n($lb['count']) . ' کتابخانه.';
            }
        }
        if ($old = $lb['oldest'][0] ?? null) {
            $examples[] = 'در «کهن‌ترین گنجینه‌ها» نخستین ردیف ' . $old['name'] . ' (' . $old['city'] . ') است: میانگین سال کتابت ' . self::n($old['dated']) . ' نسخۀ تاریخ‌دارش '
                . Fa::digits($old['mean']) . ' قمری است.';
        }

        return [[
            '«بزرگ‌ترین گنجینه‌ها» کتابخانه‌ها را به ترتیب شمار نسخه می‌آورد. «کهن‌ترین گنجینه‌ها» آن‌ها را بر پایۀ میانگین سال کتابت نسخه‌های تاریخ‌دارشان می‌چیند: هر چه میانگین کوچک‌تر باشد، نسخه‌های آن کتابخانه روی هم کهن‌ترند. با «آمار» کنار هر نام، همین صفحه را تنها برای نسخه‌های آن کتابخانه می‌بینید.',
            'میانگین، کهن‌ترین نسخه را نشان نمی‌دهد. کتابخانه‌ای با چند نسخۀ بسیار کهن و هزاران نسخۀ قاجاری، میانگینی متأخر دارد. شمار نسخه هم شمار رکوردهای فهارس است: هر رسالۀ یک مجموعه رکورد جداگانه دارد، پس شمار مجلدهای کتابخانه از این عدد کمتر است.',
        ], $examples];
    }

    private function dedications(): array
    {
        $dd = $this->data['dedications'];
        $examples = [];
        if ($first = $dd['items'][0] ?? null) {
            $examples[] = 'نخستین نام ' . $first['name'] . ' است: ' . self::n($first['count']) . ' اثر به نام او یا به درخواست او نوشته شده است'
                . ($first['asked'] ? '، که ' . self::n($first['asked']) . ' اثر از آن‌ها به درخواست او بوده است.' : '.');
        }
        $cols = array_filter($dd['centuries'], fn ($c) => $c['works'] >= (int) config('insights.min_sample') * 10);
        if ($peak = self::top($cols, 'share')) {
            $examples[] = 'در نمودار سده‌ها، بیشترین سهم از ' . self::century($peak['century']) . ' است: ' . self::n($peak['count']) . ' اثر از ' . self::n($peak['works'])
                . ' اثرِ تاریخ‌دار آن سده (' . self::pct($peak['share']) . ') تقدیم‌نامه یا سفارش‌دهنده دارند.';
        }
        $examples[] = 'روی هم ' . self::n($dd['works']) . ' اثر (' . self::pct($dd['coverage']) . ') چنین آگاهی‌ای دارند، با ' . self::n($dd['names']) . ' نام.';

        return [[
            'فهرست کسانی را می‌آورد که مؤلفان اثرشان را به نام آنان کرده‌اند یا به درخواست آنان نوشته‌اند: پادشاهان، وزیران، حاکمان و عالمان. عدد کنار هر نام شمار آثار است، و «به درخواست» آن بخش را جدا می‌کند که سفارش آن شخص بوده است.',
            'تقدیم اثر به یک پادشاه نشان پیوند مؤلف با دربار و پشتیبانی از دانش در آن دوره است. نام‌هایی که به رکورد شخص پیوند نخورده‌اند، با همان صورت نوشتاری‌شان شمرده می‌شوند؛ پس «شاه عباس» و «شاه عباس صفوی» شاید دو ردیف باشند.',
        ], $examples];
    }

    private function patrons(): array
    {
        $pt = $this->data['patrons'];
        $examples = [];
        if ($first = $pt['items'][0] ?? null) {
            $examples[] = 'نخستین نام ' . $first['name'] . ' است، با ' . self::n($first['count']) . ' نسخه که به سفارش او کتابت شده است.';
        }
        $cols = array_filter($pt['centuries'], fn ($c) => $c['copies'] >= (int) config('insights.min_sample') * 10);
        if ($peak = self::top($cols, 'share')) {
            $examples[] = 'بیشترین سهم از ' . self::century($peak['century']) . ' است: ' . self::n($peak['count']) . ' نسخه از ' . self::n($peak['copies']) . ' نسخۀ آن سده ('
                . self::pct($peak['share']) . ') سفارش‌دهنده دارند.';
        }
        $examples[] = 'سفارش‌دهنده تنها برای ' . self::n($pt['with_patron']) . ' نسخه (' . self::pct($pt['coverage']) . ') ثبت است؛ بیشتر کاتبان برای خود یا بی نام بردن از سفارش‌دهنده نوشته‌اند.';

        return [[
            'سفارش‌دهنده کسی است که کاتب در انجامه نوشته نسخه را برای او یا به دستور او کتابت کرده است. این با تقدیم اثر فرق دارد: آنجا مؤلف اثر را به کسی پیشکش کرده، و اینجا کسی کتابت یک نسخه را خواسته است.',
            'نمودار سده‌ها سهم نسخه‌های سفارشی را از نسخه‌های هر سده نشان می‌دهد، نه شمارشان را؛ پس سدۀ کم‌نسخه با سدۀ پرنسخه سنجیدنی است.',
        ], $examples];
    }

    private function facsimiles(): array
    {
        $fx = $this->data['facsimiles'];
        $examples = [];
        if ($fx['count'] > 0) {
            $examples[] = self::n($fx['count']) . ' نسخۀ این آمار عکسی است، یعنی از هر صد نسخه نزدیک به ' . Fa::digits((int) round($fx['share'])) . ' نسخه.';
        }
        if ($first = $fx['libraries'][0] ?? null) {
            $examples[] = 'بیشترین نسخۀ عکسی را ' . $first['name'] . ' (' . $first['city'] . ') دارد: ' . self::n($first['count']) . ' نسخه، یعنی ' . self::pct($first['share'])
                . ' از ' . self::n($first['holdings']) . ' نسخۀ این کتابخانه در فهارس.';
        }

        return [[
            'نسخۀ عکسی، عکس یا میکروفیلم نسخه‌ای است که اصلش در جای دیگری است. کتابخانه‌هایی که برای گردآوری عکس نسخه‌ها ساخته شده‌اند، بخش بزرگی از دارایی‌شان عکسی است. درصد کنار هر کتابخانه سهم عکسی‌ها را در نسخه‌های همان کتابخانه نشان می‌دهد.',
            'اگر اصل یک نسخۀ عکسی هم در فهارس باشد، همان نسخه در نمودارهای این صفحه دو بار شمرده می‌شود. برای آماری بی این دوباره‌شماری، پیوند «آمار بی نسخه‌های عکسی» را بزنید.',
        ], $examples];
    }

    private function sizes(): array
    {
        $sz = $this->data['sizes'];
        $examples = [];
        $label = fn (array $band) => $band['upto'] === null ? 'بیش از ' . Fa::digits($band['above']) . ' برگ' : Fa::digits($band['above'] + 1) . ' تا ' . Fa::digits($band['upto']) . ' برگ';
        if ($sz['with_folios'] > 0) {
            $peak = self::top($sz['bands']);
            $examples[] = 'پرشمارترین دسته «' . $label($peak) . '» است: ' . self::n($peak['count']) . ' نسخه، یعنی ' . self::share($peak['count'], $sz['with_folios']) . ' نسخه‌هایی که شمار برگ دارند.';
            $examples[] = 'میانۀ شمار برگ ' . self::n($sz['median']) . ' است؛ یعنی نیمی از نسخه‌ها ' . self::n($sz['median']) . ' برگ یا کمتر دارند و نیمی بیشتر.';
        }
        $cols = array_values(array_filter($sz['centuries'], fn ($c) => $c['with_folios'] >= (int) config('insights.min_sample') && $c['folios'] !== null));
        if (count($cols) > 1) {
            $high = self::top($cols, 'folios');
            usort($cols, fn ($a, $b) => $a['folios'] <=> $b['folios']);
            $low = $cols[0];
            if ($high['century'] !== $low['century']) {
                $examples[] = 'در نمودار سده‌ها، نسخه‌های ' . self::century($high['century']) . ' به‌طور میانگین ' . self::n($high['folios']) . ' برگ دارند و نسخه‌های '
                    . self::century($low['century']) . ' به‌طور میانگین ' . self::n($low['folios']) . ' برگ.';
            }
        }

        return [[
            'نمودار راست نسخه‌ها را بر پایۀ شمار برگ دسته‌بندی می‌کند، از رساله‌های چندبرگی تا کتاب‌های چندصدبرگی. نمودار چپ میانگین شمار برگ نسخه‌های هر سده را با ستون نشان می‌دهد، و عددهای زیر آن میانگین سطرهای هر صفحه است.',
            'میانه از میانگین گویاتر است، چون چند نسخۀ بسیار پربرگ میانگین را بالا می‌کشند. در مجموعه‌ها هم فهرست‌ها یکسان نیستند: برخی برگ‌های همان رساله را می‌نویسند و برخی برگ‌های کل مجلد را.',
        ], $examples];
    }

    private function languageScripts(): array
    {
        $ls = $this->data['language_scripts'];

        return [[
            'دو جدول در این بخش هست که با دکمۀ بالا جابه‌جا می‌شوند: زبان نسخه‌ها و خط اصلی آن‌ها، هر کدام در گذر سده‌ها. مانند جدول موضوع‌ها، هر خانه شمار یا سهم نسخه‌های یک زبان یا خط را در یک سده نشان می‌دهد.',
            'زبان هر نسخه، زبان اثر آن است؛ پس نسخه‌ای عربی با ترجمۀ فارسی میان سطرها تنها عربی شمرده می‌شود، مگر اثر دوزبانه ثبت شده باشد. خط هم خط اصلی متن است، نه خط عنوان‌ها یا حاشیه‌ها.',
        ], array_merge(
            array_map(fn ($e) => 'زبان‌ها: ' . $e, $this->gridExamples($ls['languages'], 'نسخه‌های زبان‌دار همان سده')),
            array_map(fn ($e) => 'خطوط: ' . $e, $this->gridExamples($ls['scripts'], 'نسخه‌های خط‌دار همان سده')),
        )];
    }

    private function topWorks(): array
    {
        $items = $this->data['top_works']['items'];
        $total = $this->data['totals']['manuscripts'];
        $examples = [];
        if ($first = $items[0] ?? null) {
            $examples[] = 'پرنسخه‌ترین اثر «' . $first['title'] . '»' . ($first['author'] ? ' از ' . $first['author'] : '') . ' است، با ' . self::n($first['count']) . ' نسخه.';
        }
        if (count($items) > 1) {
            $last = end($items);
            $sum = array_sum(array_column($items, 'count'));
            $examples[] = 'واپسین ردیف جدول «' . $last['title'] . '» با ' . self::n($last['count']) . ' نسخه است. ' . self::n(count($items)) . ' اثر این جدول روی هم '
                . self::n($sum) . ' نسخه دارند، یعنی ' . self::share($sum, $total) . ' همۀ نسخه‌ها.';
        }

        return [[
            'جدول آثاری را می‌آورد که بیشترین نسخه از آن‌ها در فهارس ثبت است. شمار نسخه نشان رواج متن است: متنی که در مدرسه‌ها درس داده می‌شده یا در خانه‌ها خوانده می‌شده، بارها استنساخ شده است.',
            'نسخه‌های یک اثر گاه زیر چند عنوان پراکنده‌اند، مثلاً جلدهای یک کتاب بزرگ یا صورت‌های گوناگون نامش؛ پس شمار واقعی برخی آثار از این عدد بیشتر است.',
        ], $examples];
    }

    private function copiesPerWork(): array
    {
        $pw = $this->data['copies_per_work'];
        $examples = [];
        if ($pw['works'] > 0) {
            $examples[] = 'از ' . self::n($pw['works']) . ' اثر، ' . self::n($pw['single']) . ' اثر (' . self::share($pw['single'], $pw['works']) . ') تنها یک نسخه دارند؛ این آثار روی هم '
                . self::share($pw['single'], $pw['copies']) . ' نسخه‌ها هستند.';
            $last = end($pw['bands']);
            if ($last['works'] > 0) {
                $examples[] = 'در سوی دیگر، ' . self::n($last['works']) . ' اثر (' . self::share($last['works'], $pw['works']) . ' آثار) هر کدام بیش از ' . Fa::digits($last['above'])
                    . ' نسخه دارند و روی هم ' . self::n($last['copies']) . ' نسخه، یعنی ' . self::share($last['copies'], $pw['copies']) . ' همۀ نسخه‌ها.';
            }
            if ($pw['works'] >= 100) {
                $top = $pw['top'][0];
                $examples[] = 'به بیان دیگر، پرنسخه‌ترین یک درصدِ آثار (' . self::n($top['works']) . ' اثر) ' . self::pct($top['share']) . ' نسخه‌ها را دارند.';
            }
        }

        return [[
            'هر ردیف یک دسته از آثار است، بر پایۀ شمار نسخه‌هایشان. نوار سرخ شمار آثار آن دسته است و نوار طلایی شمار نسخه‌هایی که آن آثار روی هم دارند. دو نوار را با هم بخوانید: دسته‌ای که آثار بسیار دارد، شاید نسخۀ کمی داشته باشد، و برعکس.',
            'این نمودار ناهمسانی میراث مکتوب را نشان می‌دهد. اثری که تنها یک نسخه دارد، اگر آن نسخه از میان برود از میان رفته است؛ در برابر، متن‌های درسی و دعایی که بارها استنساخ شده‌اند، با شمار اندکشان بخش بزرگی از نسخه‌ها را پر می‌کنند.',
        ], $examples];
    }

    private function centuryWorks(): array
    {
        $cols = array_values(array_filter($this->data['century_works']['centuries'], fn ($c) => $c['items']));
        $examples = [];
        if ($cols) {
            $late = self::top($cols);
            $first = $late['items'][0];
            $examples[] = 'در ' . self::century($late['century']) . '، نخستین اثر «' . $first['title'] . '» است، با ' . self::n($first['count']) . ' نسخه از ' . self::n($late['count'])
                . ' نسخۀ آن سده (' . self::share($first['count'], $late['count']) . ').';
            // A work listed under the most centuries
            $seen = [];
            foreach ($cols as $col) {
                foreach ($col['items'] as $item) {
                    $seen[$item['title']][] = $col['century'];
                }
            }
            uasort($seen, fn ($a, $b) => count($b) <=> count($a));
            $title = array_key_first($seen);
            if (count($seen[$title]) > 2) {
                $examples[] = '«' . $title . '» در فهرست ' . self::n(count($seen[$title])) . ' سده آمده است (سده‌های ' . implode('، ', array_map(fn ($c) => Fa::digits($c), $seen[$title]))
                    . ')؛ یعنی متنی که رواجش چند سده پاییده است.';
            }
            $only = array_filter($seen, fn ($c) => count($c) === 1 && $c[0] === $late['century']);
            if ($only && count($cols) > 2) {
                $examples[] = 'در برابر، «' . array_key_first($only) . '» تنها در فهرست ' . self::century($late['century']) . ' هست و در سده‌های دیگر میان پنج اثر نخست نیست.';
            }
        }

        return [[
            'زیر هر سده، آثاری آمده‌اند که بیشترین نسخه از آن‌ها در همان سده کتابت شده است. عدد کنار هر اثر شمار نسخه‌های آن اثر در همان سده است، نه همۀ نسخه‌هایش. روی عدد بزنید تا همان نسخه‌ها را ببینید.',
            'با خواندن ستون‌ها از کهن به نو، دگرگونی متن‌های رایج دیده می‌شود: متنی که در یک سده در صدر است، در سدۀ بعد جایش را به شرح یا متن تازه‌تری می‌دهد. اثری که در سدۀ تألیفش در فهرست نیست و سده‌ای بعد پدیدار می‌شود، دیرتر رواج یافته است. سده‌های کهن نسخۀ کمی دارند، پس فهرستشان بر چند نسخه تکیه دارد.',
        ], $examples];
    }

    private function unprinted(): array
    {
        $up = $this->data['unprinted'];
        $examples = [];
        if ($first = $up['items'][0] ?? null) {
            $examples[] = 'نخستین ردیف «' . $first['title'] . '»' . ($first['author'] ? ' از ' . $first['author'] : '') . ' است: ' . self::n($first['count'])
                . ' نسخه از آن در فهارس هست و در رکوردش نشانی از چاپ نیامده است.';
        }
        if (count($up['items']) > 1) {
            $last = end($up['items']);
            $examples[] = 'واپسین ردیف جدول، «' . $last['title'] . '»، هنوز ' . self::n($last['count']) . ' نسخه دارد؛ پس همۀ آثار این جدول متن‌هایی‌اند که بارها استنساخ شده‌اند.';
        }
        $examples[] = 'فهارس تنها برای ' . self::n($up['printed']) . ' اثر (' . self::pct($up['coverage']) . ') آگاهی چاپ دارد؛ نبودِ این آگاهی برای یک اثر، دلیل چاپ‌نشدنش نیست.';

        return [[
            'جدول آثاری را می‌آورد که نسخۀ بسیار دارند ولی فهارس چاپی برایشان یاد نکرده است. سه شرط دارند: پدیدآورشان شناخته است، بخش «چاپ» رکوردشان خالی است، و واژۀ «چاپ» در متن رکوردشان نیامده است.',
            'این جدول راهنمای جست‌وجوست، نه فهرست قطعی آثار چاپ‌نشده. اثری که در آن می‌بینید شاید در سال‌های اخیر چاپ شده باشد، یا چاپش در منبعی آمده باشد که به فهارس نرسیده است. حاشیه‌ها و شرح‌های متن‌های درسی اغلب در این جدول می‌آیند؛ این گونه آثار پرنسخه بوده‌اند و کمتر به چاپ رسیده‌اند.',
        ], $examples];
    }

    private function collections(): array
    {
        $col = $this->data['collections'];
        $examples = [];
        $label = fn (array $band) => $band['upto'] === null ? 'بیش از ' . Fa::digits($band['above']) . ' رساله'
            : ($band['upto'] === $band['above'] + 1 ? Fa::digits($band['upto']) . ' رساله' : Fa::digits($band['above'] + 1) . ' تا ' . Fa::digits($band['upto']) . ' رساله');
        if ($col['volumes'] > 0) {
            $peak = self::top($col['bands'], 'volumes');
            $examples[] = 'پرشمارترین دسته «' . $label($peak) . '» است: ' . self::n($peak['volumes']) . ' مجموعه، یعنی ' . self::share($peak['volumes'], $col['volumes']) . ' مجموعه‌ها. بزرگ‌ترین مجموعه '
                . self::n($col['largest']) . ' رساله دارد.';
            $examples[] = self::n($col['copies']) . ' نسخه (' . self::pct($col['coverage']) . ' نسخه‌ها) رساله‌ای از یک مجموعه‌اند؛ یعنی این رکوردها کتابِ جداگانه نیستند، بخشی از یک مجلدند.';
        }
        if ($pair = $col['pairs'][0] ?? null) {
            $examples[] = '«' . $pair['works'][0]['title'] . '» و «' . $pair['works'][1]['title'] . '» در ' . self::n($pair['count']) . ' مجموعه با هم آمده‌اند؛ بیشترین هم‌نشینی در این آمار.';
        }

        return [[
            'مجموعه مجلدی است که چند رساله را در خود دارد. نمودار راست مجموعه‌ها را بر پایۀ شمار رساله‌هایشان دسته‌بندی می‌کند. فهرست چپ جفت آثاری را می‌آورد که در مجموعه‌های بیشتری کنار هم آمده‌اند، با شمار آن مجموعه‌ها.',
            'هم‌نشینی دو اثر اتفاقی نیست: متن و شرحش، دو رسالۀ یک مؤلف، یا متن‌هایی که در یک پایۀ درسی خوانده می‌شده‌اند، با هم صحافی یا با هم کتابت شده‌اند. پس این فهرست برنامۀ درسی و عادت‌های خواندن را نشان می‌دهد.',
        ], $examples];
    }

    private function scripts(): array
    {
        $s = $this->data['scripts'];
        $examples = [];
        if ($first = $s['items'][0] ?? null) {
            $text = 'رایج‌ترین خط ' . $first['name'] . ' است، با ' . self::n($first['count']) . ' نسخه، یعنی ' . self::share($first['count'], $s['counted']) . ' نسخه‌های خط‌دار';
            if ($second = $s['items'][1] ?? null) {
                $text .= '؛ پس از آن ' . $second['name'] . ' با ' . self::n($second['count']) . ' نسخه (' . self::share($second['count'], $s['counted']) . ')';
                $examples[] = $text . '. این دو روی هم ' . self::share($first['count'] + $second['count'], $s['counted']) . ' نسخه‌ها را دارند.';
            } else {
                $examples[] = $text . '.';
            }
        }
        if ($s['other'] > 0) {
            $examples[] = self::n($s['other_kinds']) . ' گونۀ خط دیگر در ردیف «سایر» گرد آمده‌اند و روی هم تنها ' . self::n($s['other']) . ' نسخه دارند.';
        }

        return [[
            'هر ردیف یک خط است و نوارش سهم آن خط از نسخه‌هایی که خطشان ثبت شده است. خط هر نسخه، خط اصلی متن آن است. روی هر خط بزنید تا نسخه‌هایش را ببینید.',
            'نام خط را فهرست‌نگار گذاشته است و مرز خط‌ها همیشه روشن نیست؛ «نسخ» و «نسخ تحریری»، یا «نستعلیق» و «شکسته نستعلیق» را فهرست‌نگاران یکسان به کار نبرده‌اند. دگرگونی سهم خط‌ها در گذر زمان در بخش «زبان‌ها و خطوط در گذر سده‌ها» آمده است.',
        ], $examples];
    }

    private function languages(): array
    {
        $l = $this->data['languages'];
        $examples = [];
        $works = array_sum(array_column($l['items'], 'works')) + $l['other']['works'];
        $copies = array_sum(array_column($l['items'], 'manuscripts')) + $l['other']['manuscripts'];
        if ($first = $l['items'][0] ?? null) {
            $examples[] = 'زبان نخست ' . $first['name'] . ' است: ' . self::n($first['works']) . ' اثر (' . self::share($first['works'], $works) . ' آثار) و ' . self::n($first['manuscripts'])
                . ' نسخه (' . self::share($first['manuscripts'], $copies) . ' نسخه‌ها).';
        }
        if ($second = $l['items'][1] ?? null) {
            $perWork = fn ($row) => $row['works'] > 0 ? round($row['manuscripts'] / $row['works'], 1) : 0;
            $examples[] = $second['name'] . ' ' . self::share($second['works'], $works) . ' آثار و ' . self::share($second['manuscripts'], $copies) . ' نسخه‌ها را دارد. هر اثر ' . $first['name']
                . ' به‌طور میانگین ' . Fa::digits(str_replace('.', '٫', (string) $perWork($first))) . ' نسخه دارد و هر اثر ' . $second['name'] . ' '
                . Fa::digits(str_replace('.', '٫', (string) $perWork($second))) . ' نسخه؛ دکمۀ «نسخه‌ها» همین تفاوت را نشان می‌دهد.';
        }
        if ($l['multilingual'] > 0) {
            $examples[] = self::n($l['multilingual']) . ' اثر چندزبانه‌اند، مانند فرهنگ‌های عربی به فارسی، و در هر زبانشان یک بار شمرده شده‌اند.';
        }

        return [[
            'هر ردیف یک زبان است. با دکمۀ بالا میان دو شمارش جابه‌جا می‌شوید: «آثار» می‌گوید چند اثر به آن زبان نوشته شده، و «نسخه‌ها» می‌گوید از آثار آن زبان چند نسخه مانده است.',
            'دو شمارش یک چیز را نمی‌گویند. زبانی که آثارش بارها استنساخ شده، در «نسخه‌ها» سهم بزرگ‌تری دارد تا در «آثار». زبان را فهرست‌نگار برای اثر ثبت کرده است، نه برای تک‌تک نسخه‌ها.',
        ], $examples];
    }

    private function sources(): array
    {
        $src = $this->data['sources'];
        $total = $this->data['totals']['manuscripts'];
        $examples = [];
        if ($first = $src['items'][0] ?? null) {
            $examples[] = 'بیشترین نسخه از «' . $first['title'] . '»' . ($first['volume'] && ! str_contains($first['title'], 'جلد') ? ' جلد ' . Fa::digits($first['volume']) : '') . ' آمده است: '
                . self::n($first['count']) . ' نسخه، یعنی ' . self::share($first['count'], $total) . ' نسخه‌های این آمار.';
        }
        if ($src['volumes'] > 0) {
            $examples[] = 'نسخه‌های این آمار از ' . self::n($src['volumes']) . ' جلد فهرست آمده‌اند؛ یعنی هر جلد به‌طور میانگین ' . self::n((int) round($src['counted'] / $src['volumes'])) . ' نسخه داده است.';
        }
        if ($src['unknown'] > 0) {
            $examples[] = 'جلد فهرستِ ' . self::n($src['unknown']) . ' نسخه (' . self::share($src['unknown'], $total) . ') هنوز شناخته نیست و در هیچ ردیفی نیامده‌اند.';
        }

        return [[
            'هر ردیف یک جلد از فهرست چاپی یک کتابخانه است و نوارش شمار نسخه‌هایی که از آن جلد به فهارس رسیده‌اند. با «آمار» کنار هر ردیف، همین صفحه را تنها برای نسخه‌های آن جلد می‌بینید.',
            'همۀ نمودارهای این صفحه بر همین فهرست‌ها بنا شده‌اند. کتابخانه‌ای که فهرستش به فهارس نرسیده، در هیچ نموداری نیست، و دقت هر نمودار به دقت فهرست‌نگاران همین جلدها بسته است. برای داوری دربارۀ هر رقم این صفحه، نخست ببینید نسخه‌هایش از کدام فهرست‌ها آمده‌اند.',
        ], $examples];
    }

    private function sourceOverlap(): array
    {
        $ov = $this->data['source_overlap'];
        $total = $this->data['totals']['manuscripts'];
        $examples = [];
        if ($ov['described'] > 0) {
            $examples[] = self::n($ov['counts'][1]) . ' نسخه (' . self::share($ov['counts'][1], $total) . ') تنها در یک فهرست کتابخانه وصف شده‌اند و ' . self::n($ov['several'])
                . ' نسخه (' . self::share($ov['several'], $total) . ') در دو فهرست یا بیشتر.';
        }
        if ($ov['counts'][0] > 0) {
            $examples[] = self::n($ov['counts'][0]) . ' نسخه (' . self::share($ov['counts'][0], $total) . ') «تنها در فنخا» هستند: فهرست کتابخانه‌شان هنوز به فهارس افزوده یا به آن‌ها پیوند نشده است.';
        }
        if ($pair = $ov['pairs'][0] ?? null) {
            $examples[] = 'بیشترین هم‌پوشانی میان «' . $pair['sources'][0]['title'] . '» و «' . $pair['sources'][1]['title'] . '» است، با ' . self::n($pair['count']) . ' نسخۀ مشترک.';
        }

        return [[
            'نمودار راست می‌گوید هر نسخه در چند فهرست کتابخانه وصف شده است. فنخا، که فهرستگان است و همۀ نسخه‌ها را دارد، در این شمارش نیست. فهرست چپ جفت فهرست‌هایی را می‌آورد که بیشترین نسخۀ مشترک را دارند.',
            'نسخه‌ای که دو فهرست وصفش کرده‌اند برای پژوهشگر باارزش‌تر است، چون دو وصف را می‌توان با هم سنجید: فهرست تازه‌تر گاه نام مؤلف یا تاریخ کتابت فهرست کهنه را اصلاح کرده است. هم‌پوشانی بیشتر از سه راه پدید می‌آید: فهرست‌نویسی دوبارۀ یک کتابخانه، فهرست جداگانۀ عکس‌ها و میکروفیلم‌های یک گنجینه، و جابه‌جایی یک مجموعه از کتابخانه‌ای به کتابخانۀ دیگر.',
        ], $examples];
    }
}
