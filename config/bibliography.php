<?php

return [
    /*
     * Reference works cited in a work's bibliography (مآخذ کتاب‌شناسی) that have an online edition
     * whose pages can be linked directly. Fankha cites them as «page/volume», e.g. «الذریعة 243/6».
     *
     * - pattern: regex for the source name, matched after normalization (ی/ک, ة→ه, أ/إ/آ→ا, no ZWNJ).
     * - volumes: number of volumes; used to tell «page/volume» from the occasional «volume/page».
     * - series_volumes (optional): other volume numbers Fankha uses for the book, mapped to its own.
     * - url (optional with a viewer): link template with {volume} and {page}. The edition's page numbers must match
     *   the ones Fankha cites. Without a url only the citations the viewer can show are linked.
     * - url_volumes (optional): the url's edition has only this many volumes; later ones are linked through the viewer.
     * - viewer (optional): page images in storage/app/public/catalogs/{catalog}, shown in the site's page viewer
     *   instead of following the url. last_pages is the last printed page of each volume, first_pages the first
     *   where it is not 1, and missing the pages the scan lacks; those citations keep the url
     *   (scripts/facsimile/dharia_page_mapping.json, kashf_page_mapping.json).
     */
    'sources' => [
        'dharia' => [
            'label' => 'الذریعة الی تصانیف الشیعة',
            'pattern' => '(?:ال)?ذریعه(?: الی تصانیف الشیعه)?',
            'volumes' => 26,
            'url' => 'https://lib.eshia.ir/71602/{volume}/{page}',
            // volume 26 (the author's Mustadrakat, Dar al-Adwa' 1406) is not on eshia; its scan is noorlib's
            'url_volumes' => 25,
            'site' => 'کتابخانه‌ی مدرسه‌ی فقاهت',
            'viewer' => [
                'catalog' => 'dharia',
                'name' => 'الذریعة',
                'last_pages' => [
                    1 => 536, 2 => 519, 3 => 491, 4 => 518, 5 => 318, 6 => 404, 7 => 294, 8 => 299, 9 => 1539,
                    10 => 261, 11 => 343, 12 => 293, 13 => 398, 14 => 276, 15 => 389, 16 => 440, 17 => 329,
                    18 => 433, 19 => 407, 20 => 427, 21 => 447, 22 => 443, 23 => 362, 24 => 487, 25 => 496, 26 => 407,
                ],
                'missing' => [
                    9 => [98, 99, 202, 203, 336, 640, 641, 658, 659, 670, 671, 716, 717, 866, 867, 900, 901, 1028, 1029,
                        1036, 1037, 1038, 1039, 1062, 1063, 1152, 1153, 1176, 1177, 1192, 1193, 1208, 1209, 1224, 1225,
                        1240, 1241, 1340, 1341, 1370, 1371, 1372, 1373, 1388, 1389, 1400, 1401, 1404, 1405, 1440, 1441,
                        1456, 1457, 1524, 1525],
                    18 => [48, 49, 126, 127, 156, 157, 250, 251, 352, 353],
                ],
            ],
        ],
        'rayhana' => [
            'label' => 'ریحانة الادب',
            'pattern' => 'ریحانه(?: الادب)?',
            'volumes' => 8,
            'url' => 'https://lib.eshia.ir/10984/{volume}/{page}',
            'site' => 'کتابخانه‌ی مدرسه‌ی فقاهت',
            // Khayyam edition (scripts/facsimile/rayhana_page_mapping.json); the scan ends one page early in
            // volumes 2 and 6, whose last pages keep the url.
            'viewer' => [
                'catalog' => 'rayhana',
                'name' => 'ریحانة الادب',
                'last_pages' => [1 => 452, 2 => 455, 3 => 484, 4 => 501, 5 => 398, 6 => 403, 7 => 590, 8 => 486],
                'missing' => [6 => [3, 4]],
            ],
        ],
        // Istanbul 1941-43 edition: numbered by column, counted on across both volumes (vol. 2 starts at 945).
        'kashf' => [
            'label' => 'کشف الظنون',
            'pattern' => 'کشف الظنون(?: عن اسامی الکتب و ?الفنون)?',
            'volumes' => 2,
            'unit' => 'ستون',
            'viewer' => [
                'catalog' => 'kashf',
                'name' => 'کشف الظنون',
                'first_pages' => [2 => 945],
                'last_pages' => [1 => 940, 2 => 2056],
            ],
        ],
        // Istanbul 1945-47 edition, numbered by column in each volume. Fankha also cites it as the supplement
        // (ذیل) of Kashf al-Zunun or of Chalabi (scripts/facsimile/idah_page_mapping.json).
        'idah' => [
            'label' => 'ایضاح المکنون',
            'pattern' => '(?:ایضاح ?المکنون(?: فی الذیل علی کشف الظنون)?|ذیل کشف الظنون|ذیل چلبی)',
            'volumes' => 2,
            // the Beirut reprint numbers the Istanbul set on: Kashf 1-2, Idah 3-4, Hadiyya 5-6
            'series_volumes' => [3 => 1, 4 => 2],
            'unit' => 'ستون',
            'viewer' => [
                'catalog' => 'idah',
                'name' => 'ایضاح المکنون',
                'last_pages' => [1 => 622, 2 => 732],
            ],
        ],
        // Istanbul 1951-55 edition, numbered by column in each volume; Fankha also writes «بغدادی، هدیه»
        // (scripts/facsimile/hadiyya_page_mapping.json).
        'hadiyya' => [
            'label' => 'هدیة العارفین',
            'pattern' => 'هدیه(?: ?العارفین)?',
            'volumes' => 2,
            'series_volumes' => [5 => 1, 6 => 2],
            'unit' => 'ستون',
            'viewer' => [
                'catalog' => 'hadiyya',
                'name' => 'هدیة العارفین',
                'last_pages' => [1 => 842, 2 => 574],
            ],
        ],
    ],
];
