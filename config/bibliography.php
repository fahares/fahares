<?php

return [
    /*
     * Reference works cited in a work's bibliography (مآخذ کتاب‌شناسی) that have an online edition
     * whose pages can be linked directly. Fankha cites them as «page/volume», e.g. «الذریعة 243/6».
     *
     * - pattern: regex for the source name, matched after normalization (ی/ک, ة→ه, أ/إ/آ→ا, no ZWNJ).
     * - volumes: number of volumes; used to tell «page/volume» from the occasional «volume/page».
     * - url: link template with {volume} and {page}. The edition's page numbers must match the ones Fankha cites.
     */
    'sources' => [
        'dharia' => [
            'label' => 'الذریعة الی تصانیف الشیعة',
            'pattern' => '(?:ال)?ذریعه(?: الی تصانیف الشیعه)?',
            'volumes' => 25,
            'url' => 'https://lib.eshia.ir/71602/{volume}/{page}',
            'site' => 'کتابخانه‌ی مدرسه‌ی فقاهت',
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
