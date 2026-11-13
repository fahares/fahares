<?php

namespace App\Http\Controllers;

use App\Models\Cataloger;
use App\Review\CollectiveReview;
use App\Suggestions\Contributions;
use App\Models\HomeItem;
use App\Models\Library;
use App\Models\Subject;
use App\Support\SiteStats;

class HomeController extends Controller
{
    public function index()
    {
        $stats = SiteStats::all();

        // Direct indexed queries (< 1ms execution time)
        $topSubjects = Subject::orderByDesc('works_count')->take(8)->get();
        $topLibraries = Library::orderByDesc('manuscripts_count')->take(8)->get();

        $hero = HomeItem::section('hero')->active()->first();
        $slides = HomeItem::section('slides')->active()->get();
        $treasures = HomeItem::section('treasures')->active()->get();
        $homeCatalogers = Cataloger::whereNotNull('home_order')->orderBy('home_order')->get();

        // One example of each way of searching, drawn afresh on each visit
        $suggestedSearches = collect($this->getSuggestedSearchesPool())->map(fn ($group) => collect($group)->random())->values()->all();

        $contributions = $this->contributions();

        return view('home', compact('stats', 'topSubjects', 'topLibraries', 'hero', 'slides', 'treasures', 'homeCatalogers', 'suggestedSearches', 'contributions'));
    }

    /**
     * The strip under the search sheet (backlog item 80): the approved corrections in short, the latest of them, and
     * how much «تصحیح جمعی» still waits for.
     */
    private function contributions(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('home.contributions', 600, function () {
            $all = Contributions::publicQuery();
            $latest = (clone $all)->with(['user', 'suggestable'])->orderByDesc('reviewed_at')->orderByDesc('id')->first();
            $summary = $latest ? Contributions::summary($latest) : null;

            return [
                'total' => (clone $all)->count(),
                'researchers' => (clone $all)->whereNotNull('user_id')->distinct()->count('user_id'),
                'latest' => $summary ? ['action' => $summary['action'], 'record' => $summary['record']] : null,
                'waiting' => collect(CollectiveReview::counts())->sum('waiting'),
            ];
        });
    }

    /**
     * The examples under the home search box (backlog item 83): each group teaches one thing the box understands, an
     * exact phrase, a word left out, either of two, a shelfmark, a catalogue page, and one of each group is shown.
     * Each was checked on the live site to find records.
     */
    private function getSuggestedSearchesPool(): array
    {
        $ex = fn (string $q, string $type, string $hint) => ['label' => $q, 'hint' => $hint, 'url' => route('search', ['q' => $q, 'type' => $type], false)];

        return [
            'phrase' => [
                $ex('"شرح لمعه"', 'works', 'عبارت دقیق: واژه‌ها کنار هم و به همین ترتیب'),
                $ex('"نهج البلاغه"', 'works', 'عبارت دقیق: واژه‌ها کنار هم و به همین ترتیب'),
                $ex('"کشف الغمه"', 'works', 'عبارت دقیق: واژه‌ها کنار هم و به همین ترتیب'),
            ],
            'exclude' => [
                $ex('دیوان -حافظ', 'works', 'بی این واژه: دیوان‌ها جز دیوان حافظ'),
                $ex('اربعین -حدیث', 'works', 'بی این واژه: «-» پیش از واژه‌ای که نباید باشد'),
                $ex('رساله -فقه', 'works', 'بی این واژه: «-» پیش از واژه‌ای که نباید باشد'),
            ],
            'either' => [
                $ex('کافی | شافی', 'works', 'این یا آن: هر کدام از دو عبارت'),
                $ex('گلستان | بوستان', 'works', 'این یا آن: هر کدام از دو عبارت'),
                $ex('"شیخ بهایی" | میرداماد', 'authors', 'این یا آن: هر کدام از دو نام'),
            ],
            'combined' => [
                $ex('"شرح لمعه" -حاشیه', 'works', 'عبارت دقیق و بی واژه‌ی «حاشیه»، با هم'),
            ],
            'shelfmark' => [
                $ex('مجلس ۱۱۸۹', 'manuscripts', 'شماره‌ی نسخه: نام کتابخانه و شماره'),
            ],
            'catalogue' => [
                $ex('مرعشی ج ۳۳ ص ۲۹۴', 'manuscripts', 'صفحه‌ی فهرست: نسخه‌ای که فهرست مرعشی در این صفحه معرفی کرده'),
                $ex('ذریعه ج ۳ ص ۴۰۲', 'works', 'صفحه‌ی الذریعه: مدخل‌های این صفحه'),
            ],
        ];
    }
}
