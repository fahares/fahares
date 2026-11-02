<?php

namespace App\Console\Commands;

use App\Models\Subject;

/**
 * Links the subjects Noor tags in al-Dhari'a's text (UB) to the site's subjects (step 13.145, plan
 * docs/plans/dharia-entity-links.md, step 4): «في الفقه», «في أصول الدين», «عرفاني». A mark is linked when its words,
 * without «ال» and punctuation, are one of the forms of SUBJECTS, a hand-made list of the forms most often printed; a
 * subject the site has no page for, or a form that may be of two («النجوم», «الأصول»), or a word the text uses for more than a subject («حديث»), is not linked.
 */
class LinkDhariaSubjectsCommand extends LinkDhariaHoldingsCommand
{
    public const KINDS = ['UB'];

    protected const TARGET = Subject::class;

    /** The forms of a subject al-Dhari'a prints, and the site's subject (its name). */
    public const SUBJECTS = [
        'الفقه' => 'فقه', 'فقه' => 'فقه', 'فقهية' => 'فقه', 'فقهي' => 'فقه', 'رسالة عملية' => 'فقه', 'العبادات' => 'فقه',
        'الطهارة و الصلاة' => 'فقه',
        'أصول الفقه' => 'اصول فقه',
        'أصول الدين' => 'کلام و اعتقادات', 'الكلام' => 'کلام و اعتقادات', 'علم الكلام' => 'کلام و اعتقادات',
        'العقائد' => 'کلام و اعتقادات', 'أصول الدين و فروعه' => 'کلام و اعتقادات',
        'الإمامة' => 'امامت',
        'الطب' => 'طب',
        'النحو' => 'نحو', 'الصرف' => 'صرف', 'اللغة' => 'لغت', 'العروض' => 'عروض و قافیه', 'المعاني و البيان' => 'معانی بیان',
        'الأدب' => 'ادبیات',
        'عرفاني' => 'عرفان و تصوف', 'عرفانية' => 'عرفان و تصوف', 'العرفان' => 'عرفان و تصوف', 'التصوف' => 'عرفان و تصوف',
        'السير و السلوك' => 'سیر و سلوک',
        'الأخلاق' => 'اخلاق', 'أخلاقي' => 'اخلاق', 'أخلاقية' => 'اخلاق', 'المواعظ' => 'مواعظ',
        'مقتل' => 'مقتل', 'المقتل' => 'مقتل', 'المراثي' => 'مراثی', 'مراثي' => 'مراثی',
        'المنطق' => 'منطق', 'الحكمة' => 'فلسفه', 'الفلسفة' => 'فلسفه',
        'التجويد' => 'تجوید', 'القراءة' => 'قرائت', 'تفسير' => 'تفسیر', 'التفسير' => 'تفسیر',
        'الأدعية' => 'دعا', 'الدعاء' => 'دعا', 'الأدعية و الأعمال' => 'دعا', 'الزيارات' => 'زیارات',
        'الكيمياء' => 'کیمیا', 'الهيئة' => 'هیئت', 'الأسطرلاب' => 'اسطرلاب', 'الرمل' => 'رمل', 'الجفر' => 'جفر',
        'الحساب' => 'حساب', 'الهندسة' => 'هندسه',
        'تاريخ' => 'تاریخ', 'التاريخ' => 'تاریخ', 'الرجال' => 'رجال', 'الأنساب' => 'انساب', 'النسب' => 'انساب',
        // not «الحديث»: «النص على حديث من كنت مولاه» is a hadith, not the subject
        'الدراية' => 'درایه',
    ];

    protected $signature = 'sources:link-dharia-subjects
        {--volume= : Only this volume}
        {--sample= : Write a random sample of the links of each subject, with their context, to this file}
        {--per-kind=50 : Links of each subject in the sample}
        {--dry-run : Match and count without saving}';

    protected $description = "Link the subjects tagged in al-Dhari'a's text to the site's subjects";

    protected function targets(): array
    {
        $byKey = [];
        foreach (self::SUBJECTS as $form => $name) {
            $subject = Subject::where('name', $name)->first();
            if (! $subject) {
                $this->warn("No subject {$name}");
                continue;
            }
            $byKey[self::key($form)] = $subject;
        }

        return $byKey;
    }

    protected function label($record): string
    {
        return $record->name;
    }

    public static function target(string $marked, array $byKey): array
    {
        $key = self::key($marked);

        return isset($byKey[$key]) ? [$byKey[$key], 'form'] : [null, $key === '' ? 'empty' : 'other'];
    }
}
