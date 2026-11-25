<?php

return [
    /*
     * Catalogues printed in two columns, by source code: their page text (/sources/{code}/{volume}/page/{page}) is set
     * in two columns as well, without following the print's own column break.
     */
    'columns' => [
        'fankha' => 2,
    ],

    /*
     * Page ranges inside a catalog that hold its addenda (استدراکات) rather than regular entries.
     * Records whose primary entry falls in one of these ranges are shown as addendum entries.
     */
    'addenda' => [
        'fankha' => [
            ['volume' => 34, 'from' => 969, 'to' => 985],
        ],
    ],

    /*
     * Printed catalogues of copies that introduce the work itself (step 14.378): its author with dates, its subject,
     * contents or chapters, its references in the bibliographies. A work's page shows the page of its first entry in
     * each of them, where the work is usually introduced, with links to its other pages (App\Support\WorkCatalogues).
     * Judged from sample pages of the 64 catalogues with page images; catalogues that give only the title and author
     * (Malek, Vaziri, Senate, Turathuna …) or only the copy (microfilm lists, albums) are left out.
     */
    'describe_works' => [
        'catalogue-4c7c0fc2e037', // فهرست نسخه‌های خطی کتابخانه عمومی آیت‌الله العظمی مرعشی نجفی
        'catalogue-56242973f0ee', // فهرست نسخه‌های عکسی کتابخانه مرعشی
        'catalogue-d0b9646d3e36', // فهرست نسخ خطی کتابخانه عمومی آیت‌الله گلپایگانی
        'catalogue-253dd9a6aff3', // فهرست نسخه‌های خطی کتابخانه دانشکده الهیات مشهد
        'catalogue-86c8a806d584', // فهرست کتب خطی کتابخانه مرکزی آستان قدس رضوی
        'catalogue-af2952129bfd', // فهرست کتابخانه مجلس شورای اسلامی
        'catalogue-459301b5ba0f', // فهرست کتب خطی کتابخانه مرکزی دانشگاه تهران
        'catalogue-b9897ff5222f', // فهرست نسخه‌های خطی کتابخانه ملی ایران
        'catalogue-0ebf22c6bcd9', // فهرست کتابخانه مدرسه عالی سپهسالار
        'catalogue-0158c1b6a580', // فهرست نسخه های خطی کتابخانه مرکزی و مرکز اسناد دانشگاه تهران
        'catalogue-e745d8f70fe2', // فهرست کتابخانه دانشگاه تهران (اهدایی مشکوه)
        'catalogue-2153c3d5f1cc', // فهرست کتب خطی کتابخانه دانشکده حقوق
        'catalogue-1f3ad1a2f1e5', // فهرست نسخه‌های خطی کتابخانه جامع گوهرشاد مشهد
        'catalogue-50ed622d9559', // فهرست نسخه‌های خطی کتابخانه مدرسه صدر بازار
        'catalogue-c9846fb18444', // فهرست کتابهای خطی کتابخانه حضرت شاهچراغ
        'catalogue-e3209510dc45', // فهرست کتب خطی کتابخانه‌های اصفهان (نفایس مخطوطات)
        'catalogue-87e2efc29ff5', // آشنایی با چند نسخه خطی (مدارس و کتابخانه‌های قم)
        'catalogue-37ef5a7d7428', // نشریه نسخه‌های خطی کتابخانه مرکزی دانشگاه تهران
        'catalogue-33a15e7808c5', // فهرست نسخه‌های خطی مرکز دائرةالمعارف بزرگ اسلامی
        'catalogue-e1a7c9ee7712', // فهرست نسخه‌های عکسی مرکز دائرةالمعارف بزرگ اسلامی
        'catalogue-0305e021af80', // فهرست نسخه‌های خطی کتابخانه امام صادق قزوین
        'catalogue-4ae9dc5de46a', // فهرست نسخه‌های خطی کتابخانه مدرسه مروی تهران
        'catalogue-508df9df7cd6', // فهرست کتب خطی کتابخانه آیت الله جلیلی (کرمانشاه)
        'catalogue-f6c46c30eab7', // فهرست نسخه‌های خطی کتابخانه حوزه علمیه امام صادق (ع) اردکان
        'catalogue-ed0e9f9b74ef', // فهرست نسخه‌های خطی مدرسه امام صادق (ع) چالوس
        'catalogue-42a263db2f70', // فهرست نسخه های خطی کتابخانه تخصصی مرکز تحقیقات دار الحدیث
        'catalogue-0c672e372d8f', // فهرست نسخه های خطی مدرسه امام عصر (عج) شیراز
        'catalogue-8c0430acd8f4', // فهرست کتابخانه دولتی تربیت تبریز،کتب خطی
        'catalogue-a9b6316e8d90', // فهرست کتب ادبی عرفانی خطی کاخ گلستان
        'catalogue-b6b3ae39f183', // فهرست مخطوطات مکتبة الخطیب الشیخ علی حیدر المؤید
        'catalogue-b2a058869779', // فهرست کتب خطی کتابخانه ملی فارس
        'catalogue-f0fe05d4ca84', // فهرست نسخه های خطی کتابخانه مرکزی و مرکز اسناد دانشگاه اصفهان
        'catalogue-7da93f513bb5', // فهرست نسخه‌های خطی مدرسه خاتم الانبیاء (صدر) بابل
        'catalogue-c0239bdf8fce', // فهرست نسخه های خطی کتابخانه مدرسه معمار باشی (تهران
        'catalogue-c55fd6cf6ef1', // فهرست نسخه های خطی کتابخانه حضرت آیة الله فاضل خوانساری
        'masjid-azam-ostadi', // فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم
        'catalogue-a22177a37451', // فهرست نسخه‌های خطی چهار کتابخانه مشهد
        'catalogue-76caa4780724', // فهرست نسخه‌های خطی سه کتابخانه اصفهان
        'sepahsalar-motahhari', // فهرست نسخه‌های خطی مدرسۀ عالی شهید مطهری (سپهسالار سابق)
    ],
];
