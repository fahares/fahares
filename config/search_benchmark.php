<?php

/*
| Benchmark queries of the site search (backlog item 83), run by `php artisan fahares:search-benchmark`
| before and after every change to the search. Each case searches one tab in one scope (default
| «titles_names», the site's default) and expects a hit within the first `top` results whose fields hold the
| given texts: compared normalized, «^» at the start means «begins with». Works can also ask for a least
| number of manuscripts (`min_manuscripts`), to check that the well-known work comes before the rare ones.
| Ids are not used: they differ between the local database and production.
*/

return [
    // Titles: the Arabic article, «ة» and hamza
    ['q' => 'کافی', 'type' => 'works', 'top' => 3, 'expect' => ['title' => 'الکافی', 'author' => 'کلینی']],
    ['q' => 'الکافی', 'type' => 'works', 'top' => 1, 'expect' => ['title' => 'الکافی', 'author' => 'کلینی']],
    ['q' => 'شفا', 'type' => 'works', 'top' => 3, 'expect' => ['title' => '^الشفا', 'author' => 'ابن سینا']],
    ['q' => 'الشفاء', 'type' => 'works', 'top' => 1, 'expect' => ['title' => '^الشفا', 'author' => 'ابن سینا']],
    ['q' => 'قانون', 'type' => 'works', 'top' => 3, 'expect' => ['title' => 'القانون', 'author' => 'ابن سینا']],
    ['q' => 'تجرید', 'type' => 'works', 'top' => 3, 'expect' => ['title' => 'تجرید', 'author' => 'نصیرالدین طوسی']],
    ['q' => 'تهذیب الاحکام', 'type' => 'works', 'top' => 1, 'expect' => ['title' => 'تهذیب الاحکام', 'author' => 'شیخ طوسی']],
    ['q' => 'تهذیب احکام', 'type' => 'works', 'top' => 1, 'expect' => ['title' => 'تهذیب الاحکام', 'author' => 'شیخ طوسی']],
    ['q' => 'الرسالة الذهبیة', 'type' => 'works', 'top' => 1, 'expect' => ['title' => 'الرساله الذهبیه', 'author' => 'علی بن موسی']],
    ['q' => 'رساله ذهبیه', 'type' => 'works', 'top' => 3, 'expect' => ['title' => 'الرساله الذهبیه', 'author' => 'علی بن موسی']],

    // A rare work named in full is not buried under well-known ones sharing a word
    ['q' => 'کافی در ترسیم', 'type' => 'works', 'top' => 1, 'expect' => ['title' => 'کافی در ترسیم']],
    ['q' => 'شفاء العلیل', 'type' => 'works', 'top' => 3, 'expect' => ['title' => 'شفا العلیل']],
    ['q' => 'اجوبة مسائل سئل عنها ابن سینا', 'type' => 'works', 'top' => 1, 'expect' => ['title' => 'اجوبه مسایل', 'author' => 'ابن سینا']],

    // An author's well-known works before the rare ones
    ['q' => 'ابن سینا', 'type' => 'works', 'top' => 3, 'expect' => ['author' => 'ابن سینا'], 'min_manuscripts' => 200],
    ['q' => 'مجلسی', 'type' => 'works', 'top' => 3, 'expect' => ['author' => 'مجلسی، محمد باقر'], 'min_manuscripts' => 100],
    ['q' => 'ملاصدرا', 'type' => 'works', 'top' => 3, 'expect' => ['author' => 'صدرالدین شیرازی']],

    // People: the well-known bearer of a name first, and names known by a title
    ['q' => 'مجلسی', 'type' => 'people', 'top' => 1, 'expect' => ['name' => 'مجلسی، محمد باقر']],
    ['q' => 'علامه حلی', 'type' => 'people', 'top' => 1, 'expect' => ['name' => 'علامه حلی، حسن بن یوسف']],
    ['q' => 'ملاصدرا', 'type' => 'people', 'top' => 1, 'expect' => ['name' => 'صدرالدین شیرازی']],
    ['q' => 'سعدی', 'type' => 'people', 'top' => 1, 'expect' => ['name' => 'سعدی، مصلح']],
    ['q' => 'مولانا', 'type' => 'people', 'top' => 1, 'expect' => ['name' => 'مولوی، جلال']],

    // Manuscripts by shelfmark, typed with either digits
    ['q' => 'مجلس 1189', 'type' => 'manuscripts', 'top' => 1, 'expect' => ['library' => 'مجلس', 'shelfmark' => '^1189']],
    ['q' => 'مجلس ۱۱۸۹', 'type' => 'manuscripts', 'top' => 1, 'expect' => ['library' => 'مجلس', 'shelfmark' => '^1189']],
    ['q' => '۱۱۸۹', 'type' => 'manuscripts', 'top' => 1, 'expect' => ['shelfmark' => '^1189']],
    ['q' => 'کافی', 'type' => 'manuscripts', 'top' => 5, 'expect' => ['title' => 'الکافی']],

    // One field only (phase 5): «مجلسی» as the scribe is not the author Majlisi, and the other way round
    ['q' => 'مجلسی', 'type' => 'manuscripts', 'scope' => 'scribe', 'top' => 1, 'expect' => ['scribe' => 'مجلسی']],
    ['q' => 'مجلسی', 'type' => 'manuscripts', 'scope' => 'author', 'top' => 1, 'expect' => ['author' => 'مجلسی']],
    ['q' => 'ابن سینا', 'type' => 'works', 'scope' => 'author', 'top' => 1, 'expect' => ['author' => 'ابن سینا'], 'min_manuscripts' => 200],

    // The printed entries of the sources (phase 7): a title, and words of the text
    ['q' => 'اثبات النص', 'type' => 'sources', 'top' => 3, 'expect' => ['title' => 'اثبات النص']],
    ['q' => 'عقائد الصدوق', 'type' => 'sources', 'top' => 3, 'expect' => ['title' => 'عقاید الصدوق']],
];
