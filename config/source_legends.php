<?php

/**
 * The signs and short forms a source prints its entries with, by the source's code (step 14.159): shown as «راهنمای
 * نشانه‌ها» under that source's text on a record's page, and in full on the source's own page. A source without a list
 * here (or in its `metadata.legend`, which comes first) shows nothing.
 *
 * `origin` says where the source itself explains them. Each item has the `sign`, its `meaning`, and optionally a
 * `match`: the pattern that finds the sign in a text. Without one the sign is looked for as a whole word. A sign of one
 * or two letters needs its own pattern, or it would be found in every text.
 */
$d = '[\d۰-۹]';

return [
    'fankha' => [
        'origin' => '«رموز و اختصارات»، ج ۱، ص هشت تا ده',
        'items' => [
            ['sign' => 'کا', 'meaning' => 'کاتب', 'match' => '/(?<![\p{L}\x{200C}])کا:/u'],
            ['sign' => 'بی‌کا', 'meaning' => 'بدون کاتب: نام کاتب در نسخه نیامده است'],
            ['sign' => 'تا', 'meaning' => 'تاریخ کتابت', 'match' => '/(?<![\p{L}\x{200C}])تا:/u'],
            ['sign' => 'بی‌تا', 'meaning' => 'بدون تاریخ کتابت'],
            ['sign' => 'جا', 'meaning' => 'محل کتابت', 'match' => '/(?<![\p{L}\x{200C}])جا:/u'],
            ['sign' => 'کاتب = مؤلف', 'meaning' => 'نسخه به خط مؤلف است', 'match' => '/کاتب\s*=\s*م[ؤو]لف/u'],
            ['sign' => 'گ', 'meaning' => 'برگ', 'match' => "/{$d}\s?گ(?![\p{L}\x{200C}])/u"],
            ['sign' => 'ص', 'meaning' => 'صفحه', 'match' => "/{$d}\s?ص(?![\p{L}\x{200C}])|(?<![\p{L}\x{200C}])ص\s?{$d}/u"],
            ['sign' => 'ر', 'meaning' => 'روی برگ', 'match' => "/{$d}ر(?![\p{L}\x{200C}])/u"],
            ['sign' => 'پ', 'meaning' => 'پشت برگ', 'match' => "/{$d}پ(?![\p{L}\x{200C}])/u"],
            ['sign' => 'اندازه', 'meaning' => 'اندازه‌ی صفحه', 'match' => '/اندازه:/u'],
            ['sign' => 'سم', 'meaning' => 'سانتی‌متر', 'match' => "/{$d}\s?سم(?![\p{L}\x{200C}])/u"],
            ['sign' => 'آغاز: برابر', 'meaning' => 'آغاز نسخه همان است که در نمونه‌ی کتاب آمده', 'match' => '/آغاز:\s*برابر/u'],
            ['sign' => 'انجام: برابر', 'meaning' => 'انجام نسخه همان است که در نمونه‌ی کتاب آمده', 'match' => '/انجام:\s*برابر/u'],
            ['sign' => 'آغاز و انجام: برابر', 'meaning' => 'آغاز و انجام نسخه همان است که در نمونه‌ی کتاب آمده', 'match' => '/آغاز و انجام:?\s*برابر/u'],
            ['sign' => 'ف', 'meaning' => 'فهرست چاپی همان کتابخانه؛ شماره‌های پس از آن جلد و صفحه‌ی فهرست است. اگر نام مأخذ با نام کتابخانه یکی نباشد، نام مأخذ پیش از آن می‌آید', 'match' => '/(?<![\p{L}\x{200C}])ف:/u'],
            ['sign' => 'مخ', 'meaning' => 'فهرست مخطوط است (چاپ نشده)'],
            ['sign' => 'عکسی', 'meaning' => 'کنار شماره‌ی نسخه: نسخه عکسی است. در نام مأخذ: فهرست نسخه‌های عکسی آن کتابخانه'],
            ['sign' => 'د.ث', 'meaning' => 'دفتر ثبت کتابخانه‌ی مجلس شورای اسلامی', 'match' => '/د\.\s?ث/u'],
            ['sign' => 'رایانه', 'meaning' => 'رایانه‌ی کتابخانه‌ی ملی، آستان قدس رضوی یا مسجد گوهرشاد. شماره‌ی پس از آن در کتابخانه‌ی ملی قابل پیگیری نیست'],
            ['sign' => 'نک', 'meaning' => 'نگاه کنید'],
            ['sign' => 'وابسته به', 'meaning' => 'این اثر شرح، حاشیه، گزیده یا ترجمه‌ی اثری دیگر است که نام آن و مؤلفش در پی می‌آید'],
            ['sign' => 'همانند', 'meaning' => 'اثر بی نام مؤلف است و نسخه‌های آمده در پی آن یک اثرند', 'match' => '/(?<!غیر )(?<!احتمالاً )(?<!احتمالا )(?<!بعضاً )(?<!بعضا )(?<![\p{L}\x{200C}])همانند(?![\p{L}\x{200C}])/u'],
            ['sign' => 'غیر همانند', 'meaning' => 'نسخه‌هایی با یک نام که آثاری جدا هستند؛ نام تکراری‌شان برای کوتاهی یک بار آمده', 'match' => '/(?<!احتمالاً )(?<!احتمالا )غیر همانند/u'],
            ['sign' => 'احتمالاً همانند', 'meaning' => 'نسخه‌های بی نام مؤلف با یک نام که شاید برخی از آن‌ها یک اثر باشند', 'match' => '/احتمالاً? (بعضاً? )?همانند/u'],
            ['sign' => 'احتمالاً غیر همانند', 'meaning' => 'نسخه‌های بی نام مؤلف با یک نام که به احتمال آثاری جدا هستند', 'match' => '/احتمالاً? غیر همانند/u'],
        ],
    ],
];
