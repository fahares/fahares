<?php

/*
 * Settings of the statistical insights page (/insights), built daily by `insights:build`.
 *
 * copy_places: the historical copying centres, read from the free-text copy_place of manuscripts.
 * A place is matched to the city whose name (or another name of it) comes first in the text, as a
 * whole word, after both are normalized (ك/ي/ة, hamza, ZWNJ). Names of countries and regions
 * (ایران، هندوستان، خراسان) are left out on purpose: they have no point on the map.
 * lat/lon are those of the old city, enough for a map of the region.
 */

return [

    // Hijri centuries on the timeline
    'centuries' => [4, 14],

    // Hijri centuries on the timelines of authors (by death) and works (by composition)
    'author_centuries' => [1, 15],

    // A date range of up to this many years is a known year, put at its middle; a wider one («قرن 13», «اواخر قرن
    // 12») is a century or a part of one, its own layer on the timelines
    'exact_span' => 10,

    // Bands of years from the author's death to a copy: a band runs from just above one edge to the next. The first,
    // more than 80 years before the death, is a date or an attribution at fault; the second is the author's lifetime.
    'copy_gap' => [-80, 0, 50, 100, 200, 300, 500],

    // Rows of the most-copied works table
    'top_works' => 20,

    // Author-less records whose title names a kind of text, not one work (a poem collection, a
    // prayer book …). They stay out of the most-copied works table and are named under it.
    'generic_titles' => [
        'اشعار', 'جنگ', 'ادعیه', 'نامه‌ها', 'منشآت', 'احادیث', 'طبی', 'مرقع', 'متفرقات', 'رمل',
        'کیمیا', 'الکیمیاء', 'جفر', 'تجوید', 'تفسیر', 'التفسیر', 'مجالس', 'تقویم', 'الاخلاق',
        'کتابچه جمع و خرج', 'فقه شیعی', 'الفقه الشیعی', 'اصول الفقه', 'تقریرات الاصول',
        'رساله عملیه', 'عرفانی (رساله)', 'فرهنگ عربی به فارسی',
    ],

    // Scripts shown on their own in the share chart; the rest are grouped as «سایر»
    'scripts_shown' => 8,

    // Languages shown on their own in the share chart
    'languages_shown' => 6,

    // A page narrowed to a scope (/insights?author=…, backlog item 107) is built when asked for and kept this long
    'scope_ttl' => 86400,

    // Scopes not yet in the cache that one visitor may have built in an hour
    'scope_builds_per_hour' => 30,

    // «چند دامنه برای دیدن» under the filter form (backlog item 155): ready-made scopes worth a look. A record is named
    // by its slug (author, scribe, work, library) or its name (subject, language, script), never by an id, which
    // differs between copies of the data; the other filters take their value as the query string does. `topic` is the
    // page the link opens (the page the reader is on when it is left out). A preset whose records are missing or
    // whose scope holds fewer copies than preset_min_copies is not shown (InsightsPresets).
    'preset_min_copies' => 50,
    'presets' => [
        ['label' => 'هرات در سدۀ ۹', 'topic' => 'time', 'scope' => ['copy_city' => 'هرات', 'century' => '9'],
            'note' => 'کتابت در پایتخت تیموریان: خط زمانی دهه‌ها، اوج و فرود آن را کنار نوار حکومت‌ها نشان می‌دهد.'],
        ['label' => 'اصفهان در سدۀ ۱۱', 'topic' => 'works', 'scope' => ['copy_city' => 'اصفهان', 'century' => '11'],
            'note' => 'در پایتخت صفویان چه متن‌هایی بیشتر استنساخ می‌شد، و هر موضوع و زبان چه سهمی از نسخه‌ها داشت.'],
        ['label' => 'کتابت‌شده در نجف', 'topic' => 'places', 'scope' => ['copy_city' => 'نجف'],
            'note' => 'نسخه‌هایی که در نجف نوشته شده‌اند امروز در کدام شهرهایند؛ نمودار جابه‌جایی راهشان را نشان می‌دهد.'],
        ['label' => 'کتابت‌شده در حیدرآباد', 'topic' => 'time', 'scope' => ['copy_city' => 'حیدرآباد'],
            'note' => 'کتابت در دکن: خط زمانی اوج آن را نشان می‌دهد، و نمودار سلسله‌ها سهم روزگار قطبشاهیان و نظام‌ها را.'],
        ['label' => 'نسخه‌های سده‌های ۵ تا ۷', 'topic' => 'libraries', 'scope' => ['century' => '5-7'],
            'note' => 'کهن‌ترین نسخه‌های تاریخ‌دار در کدام کتابخانه‌ها نگهداری می‌شوند و از کدام فهرست‌ها شناخته شده‌اند.'],
        ['label' => 'موضوع طب', 'topic' => 'works', 'scope' => ['subject' => 'طب'],
            'note' => 'پرنسخه‌ترین متن‌های پزشکی در هر سده، و سهم فارسی و عربی در آثار و نسخه‌های این دانش.'],
        ['label' => 'آثار ترکی', 'topic' => 'works', 'scope' => ['language' => 'ترکی'],
            'note' => 'پرنسخه‌ترین متن‌های ترکی در کتابخانه‌های ایران، و آثاری که در هر سده بیشتر استنساخ شده‌اند.'],
        ['label' => 'شرایع الاسلام', 'topic' => 'time', 'scope' => ['work' => 'sarayi-ul-islam-fi-masail-il-alal-i-wa-l-aram-v18-2191'],
            'note' => 'یک متن درسی فقه در گذر هفت سده: نسخه‌هایش در هر سده، و فاصلۀ کتابتشان تا وفات محقق حلی.'],
        ['label' => 'کتابخانۀ وزیری یزد', 'topic' => 'time', 'scope' => ['library' => 'yzd-ozyry-32'],
            'note' => 'گنجینۀ یک شهر: نسخه‌های این کتابخانه از چه سده‌هایی است، و مؤلفانشان در چه روزگاری زیسته‌اند.'],
    ],

    // A chart of a scope that rests on fewer records than this is not drawn; below small_sample it carries a warning
    'min_sample' => 10,
    'small_sample' => 50,

    // Movement of copies: the cities of copying (or of the present libraries) listed, and the places shown for each
    'movement_cities' => 12,
    'movement_shown' => 6,

    // Subjects (main ones, or those under the scope's subject) in the grid of subjects per century
    'subjects_shown' => 10,

    // In the share reading of a grid per century, a century with fewer copies than this keeps its number but no shade:
    // a share of a few copies looked like a peak
    'grid_min_copies' => 100,

    // Features of the copies in the grid of features per century (columns of manuscripts, SearchFilters::FLAGS)
    'features' => ['is_illuminated', 'is_corrected', 'is_collated', 'has_marginal_notes', 'is_autograph'],

    // Scribes and libraries listed; a library is among the oldest only with this many dated copies
    'scribes_shown' => 15,
    'libraries_shown' => 12,
    'oldest_min_dated' => 100,

    // Bands of folios: a band runs from just above one edge up to the next
    'folio_bands' => [20, 50, 100, 200, 400],

    // Languages and scripts in the grids per century; catalogue volumes listed as sources
    'languages_grid' => 6,
    'scripts_grid' => 8,
    'sources_shown' => 12,

    // Patrons and facsimile copies (step 14.117): the names shown, the libraries shown
    'patrons_shown' => 15,
    'facsimile_libraries' => 10,
    // The people works were dedicated to or written for (step 14.119): the names shown
    'dedications_shown' => 20,

    // The topic pages of the statistics (backlog item 153, step 14.263): /insights is the hub, /insights/{slug} a
    // topic. `charts` are the files of resources/views/insights/charts in the order shown; two in one array sit
    // side by side. The id of a chart's section is its file name, so an old link /insights#places finds its page.
    'topics' => [
        'time' => [
            'name' => 'زمان',
            'title' => 'کتابت و تألیف در گذر سده‌ها',
            'lead' => 'خط زمانی کتابت نسخه‌ها، نسخه‌ها در روزگار هر سلسله، مؤلفان و آثار در هر سده، و فاصلۀ کتابت هر نسخه تا وفات مؤلف.',
            'charts' => ['timeline', 'dynasties', 'authors', 'copy-gap'],
        ],
        'places' => [
            'name' => 'مکان',
            'title' => 'کانون‌های استنساخ و تألیف',
            'lead' => 'نقشۀ شهرهای کتابت و تألیف در هر سده، و راهی که نسخه‌ها از محل کتابت تا کتابخانۀ امروز رفته‌اند.',
            'charts' => ['places', 'movement'],
        ],
        'works' => [
            'name' => 'آثار',
            'title' => 'آثار و نسخه‌هایشان',
            'lead' => 'آثار پرنسخه، شمار نسخۀ هر اثر، متن‌های رایج هر سده، آثار پرنسخۀ بی نشانی چاپ، موضوع‌ها و زبان‌ها.',
            'charts' => ['top-works', 'copies-per-work', 'century-works', 'unprinted', 'subjects', 'languages'],
        ],
        'copies' => [
            'name' => 'نسخه‌ها',
            'title' => 'ویژگی‌های نسخه‌ها',
            'lead' => 'آرایش و تصحیح نسخه‌ها، اندازه، زبان و خط در گذر سده‌ها، مجموعه‌ها و نسخه‌های عکسی.',
            'charts' => ['features', 'sizes', 'language-scripts', ['scripts', 'facsimiles'], 'collections'],
        ],
        'people' => [
            'name' => 'اشخاص',
            'title' => 'کاتبان و حامیان',
            'lead' => 'کاتبان پرکار، سفارش‌دهندگان کتابت، و کسانی که اثری به نام یا به درخواستشان نوشته شده است.',
            'charts' => [['scribes', 'patrons'], 'dedications'],
        ],
        'libraries' => [
            'name' => 'کتابخانه‌ها و فهرست‌ها',
            'title' => 'کتابخانه‌ها و فهرست‌هایشان',
            'lead' => 'بزرگ‌ترین و کهن‌ترین گنجینه‌ها، فهرست‌هایی که این آمار بر آن‌ها بنا شده، و نسخه‌هایی که چند فهرست وصفشان کرده‌اند.',
            'charts' => ['libraries', 'sources', 'firsthand', 'source-overlap'],
        ],
    ],

    // Five more figures (backlog item 146, step 14.228)
    // Bands of copies per work: a band runs from just above one edge up to the next
    'copy_bands' => [1, 2, 5, 10, 50, 100],
    // The most-copied works named under each century
    'century_works' => 5,
    // Rows of the table of much-copied works with no note of a printing
    'unprinted_shown' => 20,
    // Bands of treatises per bound volume, and the pairs of works bound together that are listed
    'collection_bands' => [1, 2, 5, 10, 20, 50],
    'collection_pairs' => 20,
    // Pairs of catalogues that describe the same copies
    'source_pairs' => 12,

    // Dynasties listed with the copies made under them (backlog item 154, step 14.265)
    'dynasties_shown' => 20,

    'copy_places' => [
        'اصفهان' => ['lat' => 32.65, 'lon' => 51.67, 'aliases' => ['سپاهان', 'صفاهان', 'اصبهان']],
        'تهران' => ['lat' => 35.69, 'lon' => 51.39, 'aliases' => ['طهران']],
        'مشهد' => ['lat' => 36.30, 'lon' => 59.60, 'aliases' => ['توس', 'طوس', 'ارض اقدس']],
        'نجف' => ['lat' => 32.00, 'lon' => 44.33, 'aliases' => ['غری']],
        'کربلا' => ['lat' => 32.61, 'lon' => 44.02, 'aliases' => ['حائر', 'کربلای']],
        'شیراز' => ['lat' => 29.59, 'lon' => 52.58, 'aliases' => []],
        'تبریز' => ['lat' => 38.08, 'lon' => 46.29, 'aliases' => []],
        'قزوین' => ['lat' => 36.27, 'lon' => 50.00, 'aliases' => []],
        'یزد' => ['lat' => 31.90, 'lon' => 54.37, 'aliases' => []],
        'قم' => ['lat' => 34.64, 'lon' => 50.88, 'aliases' => []],
        'کرمانشاه' => ['lat' => 34.31, 'lon' => 47.07, 'aliases' => ['کرمانشاهان']],
        'کرمان' => ['lat' => 30.28, 'lon' => 57.08, 'aliases' => []],
        'کاشان' => ['lat' => 33.98, 'lon' => 51.44, 'aliases' => []],
        'قمصر' => ['lat' => 33.75, 'lon' => 51.43, 'aliases' => []],
        'هرات' => ['lat' => 34.35, 'lon' => 62.20, 'aliases' => ['هری']],
        'همدان' => ['lat' => 34.80, 'lon' => 48.51, 'aliases' => []],
        'بروجرد' => ['lat' => 33.90, 'lon' => 48.75, 'aliases' => []],
        'اردبیل' => ['lat' => 38.25, 'lon' => 48.29, 'aliases' => []],
        'رشت' => ['lat' => 37.28, 'lon' => 49.58, 'aliases' => []],
        'مراغه' => ['lat' => 37.39, 'lon' => 46.24, 'aliases' => []],
        'لاهیجان' => ['lat' => 37.21, 'lon' => 50.00, 'aliases' => []],
        'شوشتر' => ['lat' => 32.05, 'lon' => 48.85, 'aliases' => ['تستر']],
        'استرآباد' => ['lat' => 36.84, 'lon' => 54.43, 'aliases' => ['استراباد', 'گرگان']],
        'زنجان' => ['lat' => 36.68, 'lon' => 48.48, 'aliases' => []],
        'سبزوار' => ['lat' => 36.21, 'lon' => 57.68, 'aliases' => []],
        'خوانسار' => ['lat' => 33.22, 'lon' => 50.31, 'aliases' => []],
        'سنندج' => ['lat' => 35.31, 'lon' => 47.00, 'aliases' => []],
        'ساری' => ['lat' => 36.56, 'lon' => 53.06, 'aliases' => []],
        'ابرقو' => ['lat' => 31.13, 'lon' => 53.28, 'aliases' => ['ابرقوه']],
        'خوی' => ['lat' => 38.55, 'lon' => 44.95, 'aliases' => []],
        'نیشابور' => ['lat' => 36.21, 'lon' => 58.80, 'aliases' => ['نیسابور']],
        'میانه' => ['lat' => 37.42, 'lon' => 47.72, 'aliases' => []],
        'سمنان' => ['lat' => 35.57, 'lon' => 53.40, 'aliases' => []],
        'قوچان' => ['lat' => 37.11, 'lon' => 58.51, 'aliases' => ['خبوشان']],
        'سجاس' => ['lat' => 36.24, 'lon' => 48.55, 'aliases' => []],
        'شهرضا' => ['lat' => 32.01, 'lon' => 51.87, 'aliases' => ['قمشه']],
        'بوشهر' => ['lat' => 28.97, 'lon' => 50.84, 'aliases' => []],
        'بابل' => ['lat' => 36.54, 'lon' => 52.68, 'aliases' => ['بارفروش']],
        'آمل' => ['lat' => 36.47, 'lon' => 52.35, 'aliases' => []],
        'آشتیان' => ['lat' => 34.52, 'lon' => 50.01, 'aliases' => []],
        'تفرش' => ['lat' => 34.69, 'lon' => 50.01, 'aliases' => []],
        'جهرم' => ['lat' => 28.50, 'lon' => 53.56, 'aliases' => []],
        'ارومیه' => ['lat' => 37.55, 'lon' => 45.08, 'aliases' => ['ارومی']],
        'مهاباد' => ['lat' => 36.76, 'lon' => 45.72, 'aliases' => ['ساوجبلاغ']],
        'گلپایگان' => ['lat' => 33.45, 'lon' => 50.29, 'aliases' => []],
        'نطنز' => ['lat' => 33.51, 'lon' => 51.92, 'aliases' => []],
        'کاشمر' => ['lat' => 35.24, 'lon' => 58.46, 'aliases' => ['ترشیز']],
        'تربت حیدریه' => ['lat' => 35.27, 'lon' => 59.22, 'aliases' => []],
        'بیرجند' => ['lat' => 32.87, 'lon' => 59.22, 'aliases' => []],
        'قائن' => ['lat' => 33.73, 'lon' => 59.18, 'aliases' => ['قاین']],
        'تون' => ['lat' => 34.02, 'lon' => 58.17, 'aliases' => []],
        'ری' => ['lat' => 35.59, 'lon' => 51.44, 'aliases' => []],
        'دزفول' => ['lat' => 32.38, 'lon' => 48.40, 'aliases' => []],
        'حویزه' => ['lat' => 31.46, 'lon' => 48.07, 'aliases' => []],
        'بهبهان' => ['lat' => 30.60, 'lon' => 50.24, 'aliases' => []],
        'نائین' => ['lat' => 32.86, 'lon' => 53.09, 'aliases' => ['نایین']],
        'اردستان' => ['lat' => 33.38, 'lon' => 52.37, 'aliases' => []],
        'فسا' => ['lat' => 28.94, 'lon' => 53.65, 'aliases' => []],
        'لار' => ['lat' => 27.68, 'lon' => 54.34, 'aliases' => []],
        'کازرون' => ['lat' => 29.62, 'lon' => 51.65, 'aliases' => []],
        'اراک' => ['lat' => 34.09, 'lon' => 49.69, 'aliases' => ['سلطان آباد']],
        'ملایر' => ['lat' => 34.30, 'lon' => 48.82, 'aliases' => []],
        'خرم آباد' => ['lat' => 33.49, 'lon' => 48.36, 'aliases' => []],
        'دامغان' => ['lat' => 36.17, 'lon' => 54.34, 'aliases' => []],
        'بسطام' => ['lat' => 36.48, 'lon' => 55.00, 'aliases' => []],
        'ساوه' => ['lat' => 35.02, 'lon' => 50.36, 'aliases' => ['ساوج']],
        'نهاوند' => ['lat' => 34.19, 'lon' => 48.38, 'aliases' => []],
        'سلطانیه' => ['lat' => 36.43, 'lon' => 48.80, 'aliases' => []],
        'ابهر' => ['lat' => 36.15, 'lon' => 49.22, 'aliases' => []],
        'اشتهارد' => ['lat' => 35.72, 'lon' => 50.37, 'aliases' => []],
        'آستارا' => ['lat' => 38.43, 'lon' => 48.87, 'aliases' => []],
        'دیلمان' => ['lat' => 36.89, 'lon' => 49.91, 'aliases' => []],

        'بغداد' => ['lat' => 33.31, 'lon' => 44.36, 'aliases' => ['دارالسلام']],
        'کاظمین' => ['lat' => 33.38, 'lon' => 44.34, 'aliases' => []],
        'سامرا' => ['lat' => 34.20, 'lon' => 43.87, 'aliases' => []],
        'حله' => ['lat' => 32.48, 'lon' => 44.42, 'aliases' => []],
        'کوفه' => ['lat' => 32.03, 'lon' => 44.40, 'aliases' => []],
        'بصره' => ['lat' => 30.51, 'lon' => 47.78, 'aliases' => []],
        'سماوه' => ['lat' => 31.31, 'lon' => 45.28, 'aliases' => []],
        'ماردین' => ['lat' => 37.31, 'lon' => 40.74, 'aliases' => []],
        'مکه' => ['lat' => 21.42, 'lon' => 39.83, 'aliases' => []],
        'مدینه' => ['lat' => 24.47, 'lon' => 39.61, 'aliases' => []],
        'طائف' => ['lat' => 21.27, 'lon' => 40.42, 'aliases' => []],
        'صنعا' => ['lat' => 15.37, 'lon' => 44.19, 'aliases' => []],
        'بحرین' => ['lat' => 26.22, 'lon' => 50.58, 'aliases' => []],
        'احسا' => ['lat' => 25.38, 'lon' => 49.59, 'aliases' => []],
        'دمشق' => ['lat' => 33.51, 'lon' => 36.29, 'aliases' => ['شام']],
        'جبل عامل' => ['lat' => 33.30, 'lon' => 35.45, 'aliases' => ['جزین', 'کرک نوح']],
        'حلب' => ['lat' => 36.20, 'lon' => 37.13, 'aliases' => []],
        'قاهره' => ['lat' => 30.04, 'lon' => 31.24, 'aliases' => ['مصر']],
        'اسکندریه' => ['lat' => 31.20, 'lon' => 29.92, 'aliases' => []],
        'استانبول' => ['lat' => 41.01, 'lon' => 28.98, 'aliases' => ['قسطنطنیه', 'اسلامبول']],
        'ارزروم' => ['lat' => 39.91, 'lon' => 41.28, 'aliases' => ['ارز روم']],
        'بدلیس' => ['lat' => 38.40, 'lon' => 42.11, 'aliases' => []],
        'تفلیس' => ['lat' => 41.72, 'lon' => 44.79, 'aliases' => []],
        'شماخی' => ['lat' => 40.63, 'lon' => 48.64, 'aliases' => []],
        'گنجه' => ['lat' => 40.68, 'lon' => 46.36, 'aliases' => []],
        'شوشی' => ['lat' => 39.76, 'lon' => 46.75, 'aliases' => []],
        'نخجوان' => ['lat' => 39.21, 'lon' => 45.41, 'aliases' => []],

        'بخارا' => ['lat' => 39.77, 'lon' => 64.42, 'aliases' => []],
        'سمرقند' => ['lat' => 39.65, 'lon' => 66.96, 'aliases' => []],
        'خیوه' => ['lat' => 41.38, 'lon' => 60.36, 'aliases' => ['خوارزم']],
        'مرو' => ['lat' => 37.60, 'lon' => 61.83, 'aliases' => []],
        'بلخ' => ['lat' => 36.76, 'lon' => 66.90, 'aliases' => []],
        'کابل' => ['lat' => 34.53, 'lon' => 69.17, 'aliases' => []],
        'قندهار' => ['lat' => 31.63, 'lon' => 65.71, 'aliases' => []],
        'فراه' => ['lat' => 32.37, 'lon' => 62.11, 'aliases' => []],

        'لاهور' => ['lat' => 31.55, 'lon' => 74.34, 'aliases' => []],
        'ملتان' => ['lat' => 30.20, 'lon' => 71.47, 'aliases' => ['مولتان']],
        'کشمیر' => ['lat' => 34.08, 'lon' => 74.80, 'aliases' => []],
        'دهلی' => ['lat' => 28.65, 'lon' => 77.23, 'aliases' => ['شاه جهان آباد', 'شاهجهان آباد', 'جهان آباد']],
        'آگره' => ['lat' => 27.18, 'lon' => 78.01, 'aliases' => ['اگره', 'اکبرآباد', 'اکبر آباد', 'اکره']],
        'لکهنو' => ['lat' => 26.85, 'lon' => 80.95, 'aliases' => ['لکنهو']],
        'جونپور' => ['lat' => 25.75, 'lon' => 82.68, 'aliases' => []],
        'پتنه' => ['lat' => 25.59, 'lon' => 85.14, 'aliases' => ['عظیم آباد', 'عظیم‌آباد']],
        'کلکته' => ['lat' => 22.57, 'lon' => 88.36, 'aliases' => []],
        'اجمیر' => ['lat' => 26.45, 'lon' => 74.64, 'aliases' => []],
        'احمدآباد' => ['lat' => 23.02, 'lon' => 72.57, 'aliases' => ['احمد آباد']],
        'سورت' => ['lat' => 21.17, 'lon' => 72.83, 'aliases' => []],
        'بمبئی' => ['lat' => 18.94, 'lon' => 72.84, 'aliases' => []],
        'برهانپور' => ['lat' => 21.31, 'lon' => 76.23, 'aliases' => []],
        'اورنگ آباد' => ['lat' => 19.88, 'lon' => 75.34, 'aliases' => ['اورنگ‌آباد']],
        'احمدنگر' => ['lat' => 19.09, 'lon' => 74.74, 'aliases' => ['احمد نگر']],
        'بیدر' => ['lat' => 17.91, 'lon' => 77.52, 'aliases' => []],
        'حیدرآباد' => ['lat' => 17.38, 'lon' => 78.47, 'aliases' => ['حیدر آباد']],
        'گلکنده' => ['lat' => 17.38, 'lon' => 78.40, 'aliases' => ['کلکنده', 'گلکندا']],
        'بیجاپور' => ['lat' => 16.83, 'lon' => 75.71, 'aliases' => []],
        'مدراس' => ['lat' => 13.08, 'lon' => 80.27, 'aliases' => []],
        // Cities added from the place texts that named none of the list (backlog item 139, step 14.221)
        'ادرنه' => ['lat' => 41.68, 'lon' => 26.56, 'aliases' => []],
        'موصل' => ['lat' => 36.34, 'lon' => 43.13, 'aliases' => []],
        'ایروان' => ['lat' => 40.18, 'lon' => 44.51, 'aliases' => []],
        'دلیجان' => ['lat' => 33.99, 'lon' => 50.68, 'aliases' => []],
        'اهر' => ['lat' => 38.48, 'lon' => 47.07, 'aliases' => []],
        'تربت جام' => ['lat' => 35.24, 'lon' => 60.62, 'aliases' => []],
        'تویسرکان' => ['lat' => 34.55, 'lon' => 48.45, 'aliases' => []],
        'شاهرود' => ['lat' => 36.42, 'lon' => 54.98, 'aliases' => []],
        'ملطیه' => ['lat' => 38.35, 'lon' => 38.31, 'aliases' => []],
        'اجین' => ['lat' => 23.18, 'lon' => 75.78, 'aliases' => []],
        'اردکان' => ['lat' => 32.31, 'lon' => 54.02, 'aliases' => []],
        'استهبان' => ['lat' => 29.13, 'lon' => 54.04, 'aliases' => ['اصطهبانات', 'اصطهبان']],
        'سقز' => ['lat' => 36.25, 'lon' => 46.27, 'aliases' => []],
        'سیواس' => ['lat' => 39.75, 'lon' => 37.02, 'aliases' => []],
        'کرکوک' => ['lat' => 35.47, 'lon' => 44.39, 'aliases' => []],
        'کنگاور' => ['lat' => 34.50, 'lon' => 47.96, 'aliases' => []],
        'آماسیه' => ['lat' => 40.65, 'lon' => 35.83, 'aliases' => []],
        'اسفراین' => ['lat' => 37.08, 'lon' => 57.51, 'aliases' => []],
        'بنارس' => ['lat' => 25.32, 'lon' => 82.97, 'aliases' => []],
        'پاریس' => ['lat' => 48.86, 'lon' => 2.35, 'aliases' => []],
        'لندن' => ['lat' => 51.51, 'lon' => -0.13, 'aliases' => []],
        'پیشاور' => ['lat' => 34.01, 'lon' => 71.58, 'aliases' => []],
        'طبس' => ['lat' => 33.60, 'lon' => 56.92, 'aliases' => []],
        'نراق' => ['lat' => 34.01, 'lon' => 50.84, 'aliases' => []],
        'ورامین' => ['lat' => 35.32, 'lon' => 51.65, 'aliases' => []],
        'بیت المقدس' => ['lat' => 31.78, 'lon' => 35.23, 'aliases' => ['القدس', 'قدس شریف']],
        'تفت' => ['lat' => 31.74, 'lon' => 54.20, 'aliases' => []],
        'تنکابن' => ['lat' => 36.82, 'lon' => 50.87, 'aliases' => []],
        'دماوند' => ['lat' => 35.72, 'lon' => 52.07, 'aliases' => []],
        'سیالکوت' => ['lat' => 32.49, 'lon' => 74.53, 'aliases' => []],
        'قطیف' => ['lat' => 26.56, 'lon' => 50.01, 'aliases' => []],
        'قونیه' => ['lat' => 37.87, 'lon' => 32.48, 'aliases' => []],
        'بورسا' => ['lat' => 40.19, 'lon' => 29.06, 'aliases' => ['بروسه', 'بورسه', 'بروسا', 'برصه']],
        'آنکارا' => ['lat' => 39.93, 'lon' => 32.86, 'aliases' => ['آنقره', 'انقره']],
        'ارزنجان' => ['lat' => 39.75, 'lon' => 39.49, 'aliases' => []],
        'انطاکیه' => ['lat' => 36.20, 'lon' => 36.16, 'aliases' => []],
        'باکو' => ['lat' => 40.41, 'lon' => 49.87, 'aliases' => ['بادکوبه']],
        'بافق' => ['lat' => 31.61, 'lon' => 55.41, 'aliases' => []],
        'بانه' => ['lat' => 35.99, 'lon' => 45.89, 'aliases' => []],
        'شمیران' => ['lat' => 35.80, 'lon' => 51.43, 'aliases' => ['تجریش']],
        'سن پترزبورگ' => ['lat' => 59.94, 'lon' => 30.31, 'aliases' => ['پطرزبورغ', 'پطرزبورگ', 'پترزبورگ']],
        'دیاربکر' => ['lat' => 37.91, 'lon' => 40.24, 'aliases' => ['دیار بکر']],
        'خوسف' => ['lat' => 32.78, 'lon' => 58.89, 'aliases' => []],
        'آستراخان' => ['lat' => 46.35, 'lon' => 48.04, 'aliases' => ['حاجی ترخان']],
        'داکا' => ['lat' => 23.81, 'lon' => 90.41, 'aliases' => ['جهانگیر نگر', 'جهانگیرنگر']],
        'بهشهر' => ['lat' => 36.69, 'lon' => 53.55, 'aliases' => ['اشرف البلاد', 'اشرف مازندران', 'اشرف طبرستان']],
        'قوص' => ['lat' => 25.92, 'lon' => 32.76, 'aliases' => []],
    ],

    // The countries and regions a place text may name beside, or instead of, a city (backlog item 139, step 14.211):
    // «هندوستان», «آمل مازندران», «احمد نگر دکن هند». They are read as the cities are (CopyPlaces::regionOf), the name
    // that comes first in the text as a whole word, and stored in copy_region and composition_region. They are no
    // points on the map. «روم» is left out: «ارز روم» is a city.
    'copy_regions' => [
        'ایران' => ['kind' => 'country', 'aliases' => []],
        'هند' => ['kind' => 'country', 'aliases' => ['هندوستان']],
        'ترکیه' => ['kind' => 'country', 'aliases' => ['عثمانی', 'بلاد روم']],
        'عراق' => ['kind' => 'country', 'aliases' => ['عتبات']],
        'مصر' => ['kind' => 'country', 'aliases' => []],
        'یمن' => ['kind' => 'country', 'aliases' => []],
        'افغانستان' => ['kind' => 'country', 'aliases' => []],
        'لبنان' => ['kind' => 'country', 'aliases' => ['جبل عامل']],
        'بحرین' => ['kind' => 'country', 'aliases' => []],
        'شام' => ['kind' => 'region', 'aliases' => ['سوریه']],
        'حجاز' => ['kind' => 'region', 'aliases' => []],
        'خراسان' => ['kind' => 'region', 'aliases' => []],
        'گیلان' => ['kind' => 'region', 'aliases' => []],
        'مازندران' => ['kind' => 'region', 'aliases' => ['طبرستان']],
        'کردستان' => ['kind' => 'region', 'aliases' => []],
        'آذربایجان' => ['kind' => 'region', 'aliases' => []],
        'فارس' => ['kind' => 'region', 'aliases' => []],
        'خوزستان' => ['kind' => 'region', 'aliases' => []],
        'لرستان' => ['kind' => 'region', 'aliases' => []],
        'سیستان' => ['kind' => 'region', 'aliases' => []],
        'گجرات' => ['kind' => 'region', 'aliases' => []],
        'دکن' => ['kind' => 'region', 'aliases' => []],
        'کشمیر' => ['kind' => 'region', 'aliases' => []],
        'بنگاله' => ['kind' => 'region', 'aliases' => ['بنگال']],
        'پنجاب' => ['kind' => 'region', 'aliases' => []],
        'ماوراءالنهر' => ['kind' => 'region', 'aliases' => ['ماوراء النهر']],
        'ترکستان' => ['kind' => 'region', 'aliases' => []],
        'بدخشان' => ['kind' => 'region', 'aliases' => []],
        'قفقاز' => ['kind' => 'region', 'aliases' => []],
        'داغستان' => ['kind' => 'region', 'aliases' => []],
    ],
];
