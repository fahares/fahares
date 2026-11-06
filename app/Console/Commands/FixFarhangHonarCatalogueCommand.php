<?php

namespace App\Console\Commands;

/**
 * The catalogue of the Farhang va Honar public library of Mashhad had «تقی بینش», فرهنگ ایران زمین 1344 (step 14.56).
 * Its book is «فهرست کتب خطی کتابخانه عمومی فرهنگ و هنر مشهد، فارسی-عربی» by رمضانعلی شاکری, فرهنگ و هنر خراسان,
 * مشهد, آبان 1348, 127 pages: Fankha's table of catalogues, row 522, and the cover the site shows. Its copies cite pages
 * 6–109; Binesh's share for «کتابخانه فرهنگ» in «چهار کتابخانه مشهد» (row 540) is pp. 45–55 only. The site had no
 * cataloger record for Shakeri, so the run makes one under his MTIF entry (37248).
 */
class FixFarhangHonarCatalogueCommand extends FixAbdolazimNavabCataloguesCommand
{
    protected $signature = 'catalogs:fix-farhang-honar {--dry-run : Report the changes without saving them}';

    protected $description = 'Give the Farhang va Honar (Mashhad) catalogue its real cataloger and printing';

    public const SHAKERI = 37248;

    public const TITLE = 'فهرست کتب خطی کتابخانه عمومی فرهنگ و هنر مشهد';

    public const NEW_CATALOGERS = [
        self::SHAKERI => [
            'name' => 'رمضانعلی شاکری',
            'slug' => 'رمضانعلی-شاکری',
            'bio' => 'نگارنده‌ی «فهرست کتب خطی کتابخانه عمومی فرهنگ و هنر مشهد، فارسی-عربی» (فرهنگ و هنر خراسان، مشهد، آبان ۱۳۴۸، ۱۲۷ ص).',
            'metadata' => ['major_catalogs' => ['فهرست کتب خطی کتابخانه عمومی فرهنگ و هنر مشهد']],
        ],
    ];

    public const FIXES = [
        'catalogue-f72ec3ba3fbc' => [
            'from' => self::BINESH,
            'to' => self::SHAKERI,
            'source' => ['title' => self::TITLE, 'publisher' => 'فرهنگ و هنر خراسان', 'publication_year' => '1348'],
            'volumes' => [
                1 => ['title' => self::TITLE, 'publisher' => 'فرهنگ و هنر خراسان', 'publication_year' => '1348', 'pages_count' => 127],
            ],
        ],
    ];
}
