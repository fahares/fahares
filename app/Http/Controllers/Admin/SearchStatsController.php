<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SearchLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * «آمار جستجو» (backlog item 83, phase 0): what people search for, what finds nothing, and which rank they open.
 */
class SearchStatsController extends Controller
{
    public const PERIODS = [7 => '۷ روز', 30 => '۳۰ روز', 90 => '۹۰ روز', 365 => 'یک سال'];

    public const FILTER_LABELS = [
        'subject_id' => 'موضوع',
        'library_id' => 'کتابخانه',
        'script_id' => 'خط',
        'century' => 'سده',
        'copy_city' => 'محل کتابت',
        'language_id' => 'زبان',
        'work_form' => 'قالب اثر',
        'manuscripts_range' => 'فراوانی نسخ',
        'flags' => 'ویژگی‌های نسخه',
    ];

    public function index(Request $request)
    {
        $days = (int) $request->query('days', 30);
        if (! array_key_exists($days, self::PERIODS)) {
            $days = 30;
        }
        $since = now()->subDays($days)->startOfDay();
        $base = fn () => SearchLog::where('created_at', '>=', $since);

        $totals = $base()->selectRaw('COUNT(*) AS searches')
            ->selectRaw("COUNT(DISTINCT NULLIF(query, '')) AS queries")
            ->selectRaw('SUM(results_count = 0) AS empty')
            ->selectRaw('SUM(results_count > 0) AS answered')
            ->selectRaw('SUM(clicked_position IS NOT NULL) AS clicked')
            ->selectRaw('SUM(clicked_position = 1) AS first_clicked')
            ->selectRaw('AVG(clicked_position) AS avg_position')
            ->selectRaw('SUM(is_member) AS members')
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

        return view('admin.search_stats.index', [
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
