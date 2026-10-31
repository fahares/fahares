<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SearchLog;
use App\Support\SearchExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * «آمار جست‌وجو» (backlog item 83, phase 0): what people search for, what finds nothing, and which rank they open.
 */
class SearchStatsController extends Controller
{
    public const PERIODS = [7 => '۷ روز', 30 => '۳۰ روز', 90 => '۹۰ روز', 365 => 'یک سال'];

    public const FILTER_LABELS = [
        'subject_id' => 'موضوع',
        'library_id' => 'کتابخانه',
        'city' => 'شهر کتابخانه',
        'script_id' => 'خط',
        'century' => 'سده',
        'year_from' => 'سال از',
        'year_to' => 'سال تا',
        'copy_city' => 'محل کتابت',
        'language_id' => 'زبان',
        'work_form' => 'قالب اثر',
        'source_id' => 'یادشده در منبع',
        'has_autograph' => 'دارای دستخط مؤلف',
        'facsimile' => 'اصل یا عکسی',
        'folios_from' => 'برگ از',
        'folios_to' => 'برگ تا',
        'flags' => 'ویژگی‌های نسخه',
        'role' => 'نقش شخص',
        'manuscripts_range' => 'فراوانی نسخ (پیشین)',
    ];

    public function index(Request $request)
    {
        $days = (int) $request->query('days', 30);
        if (! array_key_exists($days, self::PERIODS)) {
            $days = 30;
        }
        $since = now()->subDays($days)->startOfDay();
        $base = fn () => SearchLog::where('kind', SearchLog::KIND_SEARCH)->where('created_at', '>=', $since);
        $exportsBase = fn () => SearchLog::where('kind', SearchLog::KIND_EXPORT)->where('created_at', '>=', $since);

        $totals = $base()->selectRaw('COUNT(*) AS searches')
            ->selectRaw("COUNT(DISTINCT NULLIF(query, '')) AS queries")
            ->selectRaw('SUM(results_count = 0) AS empty')
            ->selectRaw('SUM(results_count > 0) AS answered')
            ->selectRaw('SUM(clicked_position IS NOT NULL) AS clicked')
            ->selectRaw('SUM(clicked_position = 1) AS first_clicked')
            ->selectRaw('AVG(clicked_position) AS avg_position')
            ->selectRaw('SUM(is_member) AS members')
            ->selectRaw('COUNT(DISTINCT user_id) AS users')
            ->first();

        $daily = $base()->selectRaw('DATE(created_at) AS day, COUNT(*) AS n, SUM(results_count = 0) AS empty')
            ->groupBy('day')->orderBy('day')->get()->keyBy('day');
        // Every day of the period, so a quiet day shows as a gap rather than disappearing
        $calendar = [];
        for ($d = $since->copy(); $d->lte(now()); $d->addDay()) {
            $row = $daily->get($d->toDateString());
            $calendar[] = ['day' => $d->copy(), 'n' => (int) ($row->n ?? 0), 'empty' => (int) ($row->empty ?? 0)];
        }

        $grouped = fn () => $base()->where('query', '!=', '')->select('query')
            ->selectRaw('COUNT(*) AS n, ROUND(AVG(results_count)) AS results, SUM(clicked_position IS NOT NULL) AS clicks, MAX(type) AS type, MAX(created_at) AS last_at')
            ->groupBy('query');

        $top = $grouped()->orderByDesc('n')->limit(30)->get();
        $empty = $grouped()->where('results_count', 0)->orderByDesc('n')->orderByDesc('last_at')->limit(30)->get();
        // Found something, yet nobody opened any of it: the results were likely not what was sought
        $unopened = $grouped()->where('results_count', '>', 0)->havingRaw('clicks = 0 AND n >= 2')->orderByDesc('n')->limit(30)->get();

        $tabs = $base()->selectRaw('type, COUNT(*) AS n')->groupBy('type')->pluck('n', 'type');
        $scopes = $base()->selectRaw('scope, COUNT(*) AS n')->groupBy('scope')->pluck('n', 'scope');

        $filterSums = $base()->whereNotNull('filters');
        foreach (array_keys(self::FILTER_LABELS) as $key) {
            $filterSums->selectRaw("SUM(JSON_EXTRACT(filters, '$.{$key}') IS NOT NULL) AS `{$key}`");
        }
        $filters = collect((array) $filterSums->first()?->getAttributes())->map(fn ($n) => (int) $n)->filter()->sortDesc();

        $positions = $base()->whereNotNull('clicked_position')
            ->selectRaw("CASE WHEN clicked_position <= 3 THEN clicked_position WHEN clicked_position <= 10 THEN 4 ELSE 11 END AS bucket, COUNT(*) AS n")
            ->groupBy('bucket')->pluck('n', 'bucket');

        // Exports (backlog item 85): how many, in which format and tab, of which searches, and how often the file
        // was cut at its row limit, which tells whether a larger, queued export is needed
        $exports = [
            'total' => $exportsBase()->count(),
            // «xlsx-queued»: a file built in the queue, counted with its format and on its own
            'formats' => $exportsBase()->selectRaw("SUBSTRING_INDEX(format, '-', 1) AS f, COUNT(*) AS n")->groupBy('f')->pluck('n', 'f'),
            'queued' => $exportsBase()->where('format', 'like', '%-queued')->count(),
            'tabs' => $exportsBase()->selectRaw('type, COUNT(*) AS n')->groupBy('type')->pluck('n', 'type'),
            'capped' => $exportsBase()->where(fn ($q) => $q->where(fn ($q) => $q->where('is_member', true)->where('results_count', '>', SearchExport::MEMBER_LIMIT))
                ->orWhere(fn ($q) => $q->where('is_member', false)->where('results_count', '>', SearchExport::GUEST_LIMIT)))->count(),
            'top' => $exportsBase()->select('query')->selectRaw("COUNT(*) AS n, MAX(results_count) AS results, MAX(type) AS type, MAX(created_at) AS last_at")
                ->groupBy('query')->orderByDesc('n')->limit(15)->get(),
        ];

        return view('admin.search_stats.index', [
            'exports' => $exports,
            'days' => $days,
            'totals' => $totals,
            'daily' => $calendar,
            'top' => $top,
            'empty' => $empty,
            'unopened' => $unopened,
            'tabs' => $tabs,
            'scopes' => $scopes,
            'filters' => $filters,
            'positions' => $positions,
        ]);
    }
}
