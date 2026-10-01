<?php

namespace App\Http\Controllers;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Subject;
use App\Models\Work;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index()
    {
        $stats = [
            'volumes_count' => 34,
            'works_count' => Work::count(),
            'manuscripts_count' => Manuscript::count(),
            'people_count' => Person::count(),
            'libraries_count' => Library::count(),
        ];

        // Direct indexed queries (< 1ms execution time)
        $topSubjects = Subject::orderByDesc('works_count')->take(8)->get();
        $topLibraries = Library::orderByDesc('manuscripts_count')->take(8)->get();

        $suggestedSearches = collect($this->getSuggestedSearchesPool())->random(7)->values()->all();

        return view('home', compact('stats', 'topSubjects', 'topLibraries', 'suggestedSearches'));
    }

    /**
     * Curated, diverse search suggestions covering classic works,
     * smart compound queries (work + author), major thinkers, scribes, and codicological flags.
     */
    private function getSuggestedSearchesPool(): array
    {
        return [
            // ۱. شاهکارهای تک‌عنوان (ادبیات، حکمت، طب، ریاضیات، ادعیه، حدیث، فقه)
            ['label' => 'شاهنامه', 'url' => route('search', ['q' => 'شاهنامه', 'type' => 'works'], false)],
            ['label' => 'دیوان حافظ', 'url' => route('search', ['q' => 'دیوان حافظ', 'type' => 'works'], false)],
            ['label' => 'نهج‌البلاغه', 'url' => route('search', ['q' => 'نهج‌البلاغه', 'type' => 'works'], false)],
            ['label' => 'صحیفه سجادیه', 'url' => route('search', ['q' => 'صحیفه سجادیه', 'type' => 'works'], false)],
            ['label' => 'ذخیره خوارزمشاهی', 'url' => route('search', ['q' => 'ذخیره خوارزمشاهی', 'type' => 'works'], false)],
            ['label' => 'القانون فی الطب', 'url' => route('search', ['q' => 'القانون فی الطب', 'type' => 'works'], false)],
            ['label' => 'کیمیای سعادت', 'url' => route('search', ['q' => 'کیمیای سعادت', 'type' => 'works'], false)],
            ['label' => 'التفهیم', 'url' => route('search', ['q' => 'التفهیم', 'type' => 'works'], false)],
            ['label' => 'تحریر اقلیدس', 'url' => route('search', ['q' => 'تحریر اقلیدس', 'type' => 'works'], false)],
            ['label' => 'مثنوی معنوی', 'url' => route('search', ['q' => 'مثنوی معنوی', 'type' => 'works'], false)],
            ['label' => 'گلستان', 'url' => route('search', ['q' => 'گلستان', 'type' => 'works'], false)],
            ['label' => 'الکافی', 'url' => route('search', ['q' => 'الکافی', 'type' => 'works'], false)],
            ['label' => 'زاد المعاد', 'url' => route('search', ['q' => 'زاد المعاد', 'type' => 'works'], false)],
            ['label' => 'جامع التواریخ', 'url' => route('search', ['q' => 'جامع التواریخ', 'type' => 'works'], false)],
            ['label' => 'کلیله و دمنه', 'url' => route('search', ['q' => 'کلیله و دمنه', 'type' => 'works'], false)],

            // ۲. جستجوهای ترکیبی هوشمند (اثر + پدیدآورنده)
            ['label' => 'قانون ابن سینا', 'url' => route('search', ['q' => 'قانون ابن سینا', 'type' => 'works'], false)],
            ['label' => 'شرح کافیه رضی', 'url' => route('search', ['q' => 'شرح کافیه رضی', 'type' => 'works'], false)],
            ['label' => 'شرح نهج‌البلاغه ابن ابی‌الحدید', 'url' => route('search', ['q' => 'شرح نهج البلاغه ابن ابی الحدید', 'type' => 'works'], false)],
            ['label' => 'اسفار ملاصدرا', 'url' => route('search', ['q' => 'اسفار ملاصدرا', 'type' => 'works'], false)],
            ['label' => 'جامع عباسی شیخ بهایی', 'url' => route('search', ['q' => 'جامع عباسی شیخ بهایی', 'type' => 'works'], false)],
            ['label' => 'شرح لمعه شهید ثانی', 'url' => route('search', ['q' => 'شرح لمعه شهید ثانی', 'type' => 'works'], false)],
            ['label' => 'تجرید الاعتقاد خواجه نصیر', 'url' => route('search', ['q' => 'تجرید الاعتقاد خواجه نصیر', 'type' => 'works'], false)],
            ['label' => 'من لایحضره الفقیه صدوق', 'url' => route('search', ['q' => 'من لایحضره الفقیه صدوق', 'type' => 'works'], false)],
            ['label' => 'زیج الغ بیگ', 'url' => route('search', ['q' => 'زیج الغ بیگ', 'type' => 'works'], false)],

            // ۳. پدیدآورندگان و اندیشمندان شاخص
            ['label' => 'علامه حلی', 'url' => route('search', ['q' => 'علامه حلی', 'type' => 'people'], false)],
            ['label' => 'خواجه نصیر طوسی', 'url' => route('search', ['q' => 'خواجه نصیر طوسی', 'type' => 'people'], false)],
            ['label' => 'شیخ بهایی', 'url' => route('search', ['q' => 'شیخ بهایی', 'type' => 'people'], false)],
            ['label' => 'ابن سینا', 'url' => route('search', ['q' => 'ابن سینا', 'type' => 'people'], false)],
            ['label' => 'میرداماد', 'url' => route('search', ['q' => 'میرداماد', 'type' => 'people'], false)],
            ['label' => 'ملاصدرا', 'url' => route('search', ['q' => 'ملاصدرا', 'type' => 'people'], false)],
            ['label' => 'سهروردی', 'url' => route('search', ['q' => 'سهروردی', 'type' => 'people'], false)],

            // ۴. کاتبان و خوشنویسان شهیر
            ['label' => 'یاقوت مستعصمی', 'url' => route('search', ['q' => 'یاقوت مستعصمی', 'type' => 'manuscripts'], false)],
            ['label' => 'میرعماد حسنی', 'url' => route('search', ['q' => 'میرعماد', 'type' => 'manuscripts'], false)],
            ['label' => 'احمد نیریزی', 'url' => route('search', ['q' => 'احمد نیریزی', 'type' => 'manuscripts'], false)],

            // ۵. ویژگی‌های کالبدی و نسخه‌شناسی
            ['label' => 'دستخط مؤلف', 'url' => route('search', ['type' => 'manuscripts', 'flag' => 'is_autograph'], false)],
            ['label' => 'نسخه‌های تذهیب‌دار', 'url' => route('search', ['type' => 'manuscripts', 'flag' => 'is_illuminated'], false)],
            ['label' => 'نسخه‌های مصور', 'url' => route('search', ['type' => 'manuscripts', 'flag' => 'is_illustrated'], false)],
        ];
    }
}
