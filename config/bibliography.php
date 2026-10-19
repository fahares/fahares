<?php

return [
    /*
     * Reference works cited in a work's bibliography (مآخذ کتاب‌شناسی) that have an online edition
     * whose pages can be linked directly. Fankha cites them as «page/volume», e.g. «الذریعة 243/6».
     *
     * - pattern: regex for the source name, matched after normalization (ی/ک, ة→ه, أ/إ/آ→ا, no ZWNJ).
     * - volumes: number of volumes; used to tell «page/volume» from the occasional «volume/page».
     * - url: link template with {volume} and {page}. The edition's page numbers must match the ones Fankha cites.
     * - viewer (optional): page images in storage/app/public/catalogs/{catalog}, shown in the site's page viewer
     *   instead of following the url. last_pages is the last printed page of each volume and missing the pages
     *   the scan lacks; those citations keep the url (scripts/facsimile/dharia_page_mapping.json).
     */
    'sources' => [
        'dharia' => [
            'label' => 'الذریعة الی تصانیف الشیعة',
            'pattern' => '(?:ال)?ذریعه(?: الی تصانیف الشیعه)?',
            'volumes' => 25,
            'url' => 'https://lib.eshia.ir/71602/{volume}/{page}',
            'site' => 'کتابخانه‌ی مدرسه‌ی فقاهت',
            'viewer' => [
                'catalog' => 'dharia',
                'name' => 'الذریعة',
                'last_pages' => [
                    1 => 536, 2 => 519, 3 => 491, 4 => 518, 5 => 318, 6 => 404, 7 => 294, 8 => 299, 9 => 1539,
                    10 => 261, 11 => 343, 12 => 293, 13 => 398, 14 => 276, 15 => 389, 16 => 440, 17 => 329,
                    18 => 433, 19 => 407, 20 => 427, 21 => 447, 22 => 443, 23 => 362, 24 => 487, 25 => 496,
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
        ],
    ],
];
