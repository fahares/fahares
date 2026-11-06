<?php

namespace App\Console\Commands;

/**
 * The catalogue of the Jalili library of Kermanshah had «سید محمدعلی روضاتی» as its cataloger (step 14.55). Its book
 * is «فهرست کتب خطی کتابخانه آیت الله جلیلی (کرمانشاه)» by محمد وفادار مرادی, سازمان کتابخانه‌ها، موزه‌ها و مرکز
 * اسناد آستان قدس رضوی, مشهد 1383, 372 pages: Fankha's table of catalogues, row 464, and mtif.org/book/737, whose
 * cover the site shows. Its copies cite pages 11–365. The same fix as the Abdolazim and Navab catalogues.
 */
class FixJaliliCatalogueCommand extends FixAbdolazimNavabCataloguesCommand
{
    protected $signature = 'catalogs:fix-jalili {--dry-run : Report the changes without saving them}';

    protected $description = 'Give the Jalili (Kermanshah) catalogue its real cataloger and printing';

    public const ROWZATI = 37309;

    public const VAFADAR = 37490;

    public const TITLE = 'فهرست کتب خطی کتابخانه آیت الله جلیلی (کرمانشاه)';

    public const PUBLISHER = 'سازمان کتابخانه‌ها، موزه‌ها و مرکز اسناد آستان قدس رضوی';

    public const FIXES = [
        'catalogue-508df9df7cd6' => [
            'from' => self::ROWZATI,
            'to' => self::VAFADAR,
            'source' => ['title' => self::TITLE, 'publisher' => self::PUBLISHER, 'publication_year' => '1383'],
            'volumes' => [
                1 => ['title' => self::TITLE, 'publisher' => self::PUBLISHER, 'publication_year' => '1383', 'pages_count' => 372],
            ],
        ],
    ];
}
