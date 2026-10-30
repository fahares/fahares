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
];
