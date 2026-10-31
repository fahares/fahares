<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Visit stats (backlog item 103): the site's own count of the pages people open, tied to the record each one shows.
 *
 * A view is noted after the response is sent (RecordPageView) as one line appended to a file of the current
 * minute; `fahares:flush-page-views` loads the closed minutes into page_views every minute, so no page waits on
 * an INSERT. Nothing identifies the reader: no IP, no account, no cookie. Unique visitors are told apart by a
 * hash of IP and user agent with a salt that is drawn each day and deleted the next, so a hash cannot be traced
 * back nor matched across days. page_views keeps 30 days; `fahares:aggregate-page-views` folds each day into
 * page_view_daily first, which keeps the counts for good.
 */
final class PageViews
{
    /** Days are those of the readers, most of them in Iran */
    public const TIMEZONE = 'Asia/Tehran';

    /** How long the single views are kept; their daily counts stay */
    public const KEEP_DAYS = 30;

    /** Route name => the record type it shows, with the record's id in its {id} */
    public const RECORD_ROUTES = [
        'manuscripts.show' => 'manuscript',
        'works.show' => 'work',
        'people.show' => 'person',
        'libraries.show' => 'library',
        'catalogers.show' => 'cataloger',
        'subjects.show' => 'subject',
        'posts.show' => 'post',
    ];

    public const TYPE_LABELS = [
        'manuscript' => 'نسخه‌ها',
        'work' => 'آثار',
        'person' => 'اشخاص',
        'library' => 'کتابخانه‌ها',
        'source_page' => 'صفحه‌های منبع',
        'source_item' => 'مدخل‌های منبع',
        'source' => 'منابع',
        'cataloger' => 'فهرست‌نگاران',
        'subject' => 'موضوعات',
        'post' => 'نوشته‌ها',
        'search' => 'جست‌وجو',
        'page' => 'صفحه‌های دیگر',
    ];

    /**
     * Breakdowns of the whole site, kept in page_view_daily beside the records under these types: _site (key ''),
     * _device (mobile, desktop), _member (member, guest) and _referrer (a domain, '' for none, «self» for the site)
     */
    public const SITE = '_site';

    /** The referrer of a click inside the site */
    public const SELF = 'self';

    private const MOBILE_AGENTS = '~Mobi|Android|iPhone|iPad|iPod|Opera Mini|IEMobile~i';

    public static function today(): string
    {
        return now(self::TIMEZONE)->toDateString();
    }

    public static function directory(): string
    {
        return config('visits.buffer');
    }

    /** Notes a view of the page, if it is a page a person opened; never fails the request */
    public static function record(Request $request, Response $response): void
    {
        try {
            if (! self::shouldRecord($request, $response) || ! ($subject = self::subject($request))) {
                return;
            }
            $day = self::today();
            $line = json_encode([
                $day,
                $subject[0],
                $subject[1],
                self::visitor($request, $day),
                self::referrer($request),
                (bool) preg_match(self::MOBILE_AGENTS, (string) $request->userAgent()),
                auth()->check(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $dir = self::directory();
            is_dir($dir) || @mkdir($dir, 0775, true);
            file_put_contents($dir . '/views-' . gmdate('YmdHi') . '.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            Log::warning('Page view not recorded: ' . $e->getMessage());
        }
    }

    public static function shouldRecord(Request $request, Response $response): bool
    {
        return $request->isMethod('GET')
            && $response->getStatusCode() === 200
            && str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')
            && ! $request->ajax() && ! $request->expectsJson() && ! $request->hasHeader('X-Livewire')
            && ! $request->is('admin', 'admin/*', 'up')
            && ! in_array($request->ip(), ['127.0.0.1', '::1'], true)
            // A browser always names its languages; scripts and many crawlers that pass as browsers do not
            && trim((string) $request->headers->get('Accept-Language')) !== ''
            && Visitors::isPerson($request);
    }

    /** [type, key] of the page: the record and its id, a source page or entry by its address, or the route */
    public static function subject(Request $request): ?array
    {
        $route = $request->route();
        $name = $route?->getName();
        if (! $name) {
            return null;
        }
        if (isset(self::RECORD_ROUTES[$name])) {
            $id = (int) $route->parameter('id');

            return $id > 0 ? [self::RECORD_ROUTES[$name], (string) $id] : null;
        }

        return match ($name) {
            'source-pages.show' => ['source_page', $route->parameter('code') . '/' . (int) $route->parameter('volume') . '/' . (int) $route->parameter('page')],
            'source-items.show' => ['source_item', $route->parameter('code') . '/' . (int) $route->parameter('volume') . '/' . mb_substr((string) $route->parameter('key'), 0, 40)],
            'sources.show' => ['source', (string) $route->parameter('code')],
            // The tab searched: works, manuscripts or people
            'search' => ['search', mb_substr((string) $request->query('type', ''), 0, 20)],
            default => ['page', mb_substr($name, 0, 120)],
        };
    }

    /** The referring site's domain only, «self» for a click inside the site, null when there is none */
    public static function referrer(Request $request): ?string
    {
        $host = parse_url((string) $request->headers->get('referer'), PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower($host);
        if ($host === strtolower($request->getHost())) {
            return self::SELF;
        }

        return mb_substr(preg_replace('~^www\.~', '', $host), 0, 100);
    }

    /** One reader of one day, without anything that can be traced back to them */
    public static function visitor(Request $request, string $day): string
    {
        return substr(hash('sha256', self::salt($day) . '|' . $request->ip() . '|' . $request->userAgent()), 0, 16);
    }

    /** The day's random salt, drawn by the first view of the day and deleted after it (see flush) */
    private static function salt(string $day): string
    {
        $dir = self::directory();
        is_dir($dir) || @mkdir($dir, 0775, true);
        $file = "$dir/salt-$day";
        if (! is_file($file) && ($handle = @fopen($file, 'x'))) {
            fwrite($handle, bin2hex(random_bytes(32)));
            fclose($handle);
        }
        // Another request may have created it a moment ago and not yet written it
        for ($try = 0; $try < 20; $try++) {
            $salt = (string) @file_get_contents($file);
            if (strlen($salt) === 64) {
                return $salt;
            }
            usleep(5000);
        }

        throw new \RuntimeException('No salt for ' . $day);
    }

    /**
     * Loads the noted views into page_views and returns their number. Files of the last two minutes may still be
     * written to and wait for the next run, unless $all. Salts of past days are deleted here.
     */
    public static function flush(bool $all = false): int
    {
        $dir = self::directory();
        $open = $all ? [] : ['views-' . gmdate('YmdHi') . '.jsonl', 'views-' . gmdate('YmdHi', time() - 60) . '.jsonl'];
        $loaded = 0;
        foreach (glob("$dir/views-*.jsonl") ?: [] as $file) {
            // Renaming claims the file, so two runs never load it twice
            if (in_array(basename($file), $open, true) || ! @rename($file, "$file.loading")) {
                continue;
            }
            $loaded += self::load("$file.loading");
        }
        // Left by a run that stopped half-way
        foreach (glob("$dir/views-*.jsonl.loading") ?: [] as $file) {
            if (filemtime($file) < time() - 600) {
                $loaded += self::load($file);
            }
        }
        foreach (glob("$dir/salt-*") ?: [] as $file) {
            if (substr(basename($file), 5) < self::today()) {
                @unlink($file);
            }
        }

        return $loaded;
    }

    private static function load(string $file): int
    {
        $rows = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $view = json_decode($line, true);
            if (! is_array($view) || count($view) !== 7) {
                continue;
            }
            [$day, $type, $key, $visitor, $referrer, $mobile, $member] = $view;
            $rows[] = ['day' => $day, 'type' => $type, 'ref' => $key, 'visitor' => $visitor, 'referrer' => $referrer,
                'is_mobile' => (bool) $mobile, 'is_member' => (bool) $member];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('page_views')->insert($chunk);
        }
        @unlink($file);

        return count($rows);
    }

    /** The day's counts from the single views: one row per record or page, and the site's breakdowns */
    public static function dayRows(string $day): array
    {
        $views = fn () => DB::table('page_views')->where('day', $day);
        $count = 'COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors';
        $rows = [];
        $add = function ($type, $key, $row) use ($day, &$rows) {
            $rows[] = ['day' => $day, 'type' => $type, 'ref' => (string) $key, 'views' => (int) $row->views, 'visitors' => (int) $row->visitors];
        };

        foreach ($views()->select('type', 'ref')->selectRaw($count)->groupBy('type', 'ref')->get() as $row) {
            $add($row->type, $row->ref, $row);
        }
        $site = $views()->selectRaw($count)->first();
        if (! $site->views) {
            return [];
        }
        $add(self::SITE, '', $site);
        foreach ($views()->select('is_mobile')->selectRaw($count)->groupBy('is_mobile')->get() as $row) {
            $add('_device', $row->is_mobile ? 'mobile' : 'desktop', $row);
        }
        foreach ($views()->select('is_member')->selectRaw($count)->groupBy('is_member')->get() as $row) {
            $add('_member', $row->is_member ? 'member' : 'guest', $row);
        }
        foreach ($views()->selectRaw("COALESCE(referrer, '') AS host")->selectRaw($count)->groupBy('host')->get() as $row) {
            $add('_referrer', $row->host, $row);
        }

        return $rows;
    }

    /** The last day folded into page_view_daily; the views of later days are counted from page_views */
    public static function lastAggregated(): ?string
    {
        return DB::table('page_view_daily')->max('day');
    }

    /**
     * Folds the past days into page_view_daily: each day after the last one folded, and that day again in case
     * views of it were loaded late. Returns the days folded.
     */
    public static function aggregate(): array
    {
        $last = self::lastAggregated();
        $days = DB::table('page_views')->where('day', '<', self::today())
            ->when($last, fn ($q) => $q->where('day', '>=', $last))
            ->distinct()->orderBy('day')->pluck('day')
            ->map(fn ($day) => Carbon::parse($day)->toDateString());

        foreach ($days as $day) {
            $rows = self::dayRows($day);
            DB::transaction(function () use ($day, $rows) {
                DB::table('page_view_daily')->where('day', $day)->delete();
                foreach (array_chunk($rows, 1000) as $chunk) {
                    DB::table('page_view_daily')->insert($chunk);
                }
            });
        }

        return $days->all();
    }

    /** Deletes the single views older than KEEP_DAYS whose day is folded; returns their number */
    public static function prune(): int
    {
        $cutoff = now(self::TIMEZONE)->subDays(self::KEEP_DAYS)->toDateString();
        $last = self::lastAggregated();
        if (! $last) {
            return 0;
        }

        return DB::table('page_views')->where('day', '<', min($cutoff, $last))->delete();
    }

    /**
     * Counts from $since: page_view_daily for the folded days and page_views for the rest, as rows of
     * day, type, ref, views, visitors. $types limits them to these types.
     */
    public static function rows(string $since, array $types): \Illuminate\Support\Collection
    {
        $last = self::lastAggregated();
        $folded = $last ? DB::table('page_view_daily')->where('day', '>=', $since)->where('day', '<=', $last)
            ->whereIn('type', $types)->get(['day', 'type', 'ref', 'views', 'visitors']) : collect();

        $live = DB::table('page_views')->where('day', '>=', $since)->when($last, fn ($q) => $q->where('day', '>', $last))
            ->distinct()->pluck('day')
            ->flatMap(fn ($day) => self::dayRows(Carbon::parse($day)->toDateString()))
            ->filter(fn ($row) => in_array($row['type'], $types, true))
            ->map(fn ($row) => (object) $row);

        return $folded->map(function ($row) {
            $row->day = Carbon::parse($row->day)->toDateString();

            return $row;
        })->concat($live)->values();
    }

    /**
     * The most viewed records or pages of a type from $since: ref => (object) [views, visitors], visitors being the
     * sum of each day's unique visitors. Summed in the database, since a year of records is too many rows to load.
     */
    public static function top(string $type, string $since, int $limit = 10): \Illuminate\Support\Collection
    {
        $last = self::lastAggregated();
        // Each side is cut to a few times the limit before they are merged; enough when the days left unfolded are few
        $folded = $last ? DB::table('page_view_daily')->where('type', $type)->whereBetween('day', [$since, $last])
            ->select('ref')->selectRaw('SUM(views) AS views, SUM(visitors) AS visitors')
            ->groupBy('ref')->orderByDesc('views')->limit($limit * 5)->get() : collect();
        $live = DB::table('page_views')->where('type', $type)->where('day', '>=', $since)->when($last, fn ($q) => $q->where('day', '>', $last))
            ->select('ref')->selectRaw('COUNT(*) AS views, COUNT(DISTINCT day, visitor) AS visitors')
            ->groupBy('ref')->orderByDesc('views')->limit($limit * 5)->get();

        $merged = [];
        foreach ($folded->concat($live) as $row) {
            $merged[$row->ref] ??= (object) ['views' => 0, 'visitors' => 0];
            $merged[$row->ref]->views += (int) $row->views;
            $merged[$row->ref]->visitors += (int) $row->visitors;
        }

        return collect($merged)->sortByDesc('views')->take($limit);
    }

    /** All views of one record or page, for its admins (folded days and the rest) */
    public static function total(string $type, string $key): int
    {
        $last = self::lastAggregated();

        return (int) DB::table('page_view_daily')->where('type', $type)->where('ref', $key)->sum('views')
            + DB::table('page_views')->where('type', $type)->where('ref', $key)->when($last, fn ($q) => $q->where('day', '>', $last))->count();
    }
}
