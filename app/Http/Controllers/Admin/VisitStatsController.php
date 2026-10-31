<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cataloger;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Post;
use App\Models\Source;
use App\Models\Subject;
use App\Models\Work;
use App\Support\Fa;
use App\Support\PageViews;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * «آمار بازدید» (backlog item 103): the site's daily views and visitors, the devices and referrers, and the most
 * viewed records of each type. The counts come from App\Support\PageViews.
 */
class VisitStatsController extends Controller
{
    public const PERIODS = SearchStatsController::PERIODS;

    /** Search engines besides Google, told apart from the other referring sites */
    private const SEARCH_ENGINES = '~(^|\.)(bing|yandex|duckduckgo|yahoo|ecosia|baidu|qwant|startpage|brave)\.~';

    /** Other pages by their route name */
    private const PAGE_LABELS = [
        'home' => 'صفحه‌ی خانه',
        'about' => 'درباره ما',
        'insights' => 'بینش‌ها',
        'sources.index' => 'منابع و مراجع',
        'libraries.index' => 'کتابخانه‌ها',
        'subjects.index' => 'موضوعات',
        'catalogers.index' => 'فهرست‌نگاران',
        'posts.index' => 'نوشته‌ها',
        'contribute.index' => 'مشارکت',
        'contributions.index' => 'مشارکت‌کنندگان',
        'login' => 'ورود',
        'register' => 'ثبت‌نام',
        'profile' => 'حساب من',
        'my.suggestions' => 'پیشنهادهای من',
        'notifications.index' => 'اعلان‌ها',
    ];

    public function index(Request $request)
    {
        $days = (int) $request->query('days', 30);
        if (! array_key_exists($days, self::PERIODS)) {
            $days = 30;
        }
        $today = now(PageViews::TIMEZONE)->startOfDay();
        $since = $today->copy()->subDays($days - 1);
        $rows = PageViews::rows($since->toDateString(), [PageViews::SITE, '_device', '_member', '_referrer']);

        $site = $rows->where('type', PageViews::SITE)->keyBy('day');
        // Every day of the period, so a quiet day shows as a gap rather than disappearing
        $calendar = [];
        for ($d = $since->copy(); $d->lte($today); $d->addDay()) {
            $row = $site->get($d->toDateString());
            $calendar[] = ['day' => $d->copy(), 'views' => (int) ($row->views ?? 0), 'visitors' => (int) ($row->visitors ?? 0)];
        }
        $sum = fn (string $type) => $rows->where('type', $type)->groupBy('ref')->map(fn ($g) => (int) $g->sum('views'));

        $views = (int) $site->sum('views');
        $referrers = $sum('_referrer');
        $sources = ['google' => 0, 'engines' => 0, 'direct' => 0, 'sites' => 0];
        $sites = [];
        foreach ($referrers as $host => $n) {
            if ($host === PageViews::SELF) {
                continue;
            }
            $group = match (true) {
                $host === '' => 'direct',
                str_contains($host, 'google') => 'google',
                (bool) preg_match(self::SEARCH_ENGINES, $host) => 'engines',
                default => 'sites',
            };
            $sources[$group] += $n;
            if ($group !== 'direct') {
                $sites[$host] = $n;
            }
        }
        arsort($sites);

        $top = [];
        foreach (array_keys(PageViews::TYPE_LABELS) as $type) {
            $items = PageViews::top($type, $since->toDateString(), $type === 'source_page' ? 20 : 10);
            if ($items->isNotEmpty()) {
                $labels = $this->labels($type, $items->keys());
                $top[$type] = $items->map(fn ($n, $ref) => (object) (['views' => $n->views, 'visitors' => $n->visitors] + ($labels[$ref] ?? ['label' => $ref, 'url' => null])));
            }
        }

        return view('admin.visit_stats.index', [
            'days' => $days,
            'daily' => $calendar,
            'totals' => [
                'views' => $views,
                'visitors' => (int) $site->sum('visitors'),
                'busiest' => (int) $site->max('visitors'),
                'mobile' => (int) ($sum('_device')['mobile'] ?? 0),
                'members' => (int) ($sum('_member')['member'] ?? 0),
                'internal' => (int) ($referrers[PageViews::SELF] ?? 0),
            ],
            'sources' => $sources,
            'sites' => array_slice($sites, 0, 15, true),
            'top' => $top,
            'lastAggregated' => PageViews::lastAggregated(),
        ]);
    }

    /** ref => [label, url] of the records or pages of a type */
    private function labels(string $type, Collection $refs): array
    {
        $ids = $refs->map(fn ($ref) => (int) $ref)->all();
        $records = fn (string $model, callable $label, string $route, array $with = []) => $model::with($with)->whereIn('id', $ids)->get()
            ->mapWithKeys(fn ($record) => [(string) $record->id => ['label' => $label($record), 'url' => route($route, $record)]])->all();
        $sourceNames = fn () => Source::whereIn('code', $refs->map(fn ($ref) => explode('/', $ref)[0])->unique())->get()
            ->mapWithKeys(fn ($s) => [$s->code => $s->short_name ?: $s->title]);

        return match ($type) {
            'manuscript' => $records(Manuscript::class, fn ($m) => trim(($m->work?->primary_title ?? '') . ' — ' . ($m->libraryRecord?->name ?? $m->library) . ' ' . Fa::digits($m->shelfmark), ' —'),
                'manuscripts.show', ['work:id,primary_title', 'libraryRecord:id,name']),
            'work' => $records(Work::class, fn ($w) => $w->primary_title, 'works.show'),
            'person' => $records(Person::class, fn ($p) => $p->name, 'people.show'),
            'library' => $records(Library::class, fn ($l) => $l->display_name, 'libraries.show'),
            'cataloger' => $records(Cataloger::class, fn ($c) => $c->display_name, 'catalogers.show'),
            'subject' => $records(Subject::class, fn ($s) => $s->name, 'subjects.show'),
            'post' => $records(Post::class, fn ($p) => $p->title, 'posts.show'),
            'source_page', 'source_item' => (function () use ($type, $refs, $sourceNames) {
                $names = $sourceNames();

                return $refs->mapWithKeys(function ($ref) use ($type, $names) {
                    [$code, $volume, $at] = array_pad(explode('/', $ref, 3), 3, '');
                    $label = ($names[$code] ?? $code) . '، ج ' . Fa::digits($volume) . ($type === 'source_page' ? '، ص ' : '، مدخل ') . Fa::digits($at);
                    $url = $type === 'source_page'
                        ? route('source-pages.show', ['code' => $code, 'volume' => $volume, 'page' => $at])
                        : route('source-items.show', ['code' => $code, 'volume' => $volume, 'key' => $at]);

                    return [$ref => ['label' => $label, 'url' => $url]];
                })->all();
            })(),
            'source' => $sourceNames()->mapWithKeys(fn ($name, $code) => [$code => ['label' => $name, 'url' => route('sources.show', $code)]])->all(),
            'search' => $refs->mapWithKeys(fn ($tab) => [$tab => [
                'label' => ['works' => 'آثار', 'manuscripts' => 'نسخه‌ها', 'people' => 'اشخاص'][$tab] ?? ($tab === '' ? 'بی تب' : $tab),
                'url' => route('search', array_filter(['type' => $tab])),
            ]])->all(),
            default => $refs->mapWithKeys(function ($name) {
                try {
                    $url = route($name);
                } catch (\Throwable) {
                    $url = null;
                }

                return [$name => ['label' => self::PAGE_LABELS[$name] ?? $name, 'url' => $url]];
            })->all(),
        };
    }
}
