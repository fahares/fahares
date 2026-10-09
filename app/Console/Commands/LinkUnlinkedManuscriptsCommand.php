<?php

namespace App\Console\Commands;

use App\Models\Catalog;
use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LinkUnlinkedManuscriptsCommand extends Command
{
    protected $signature = 'manuscripts:link-unlinked {--dry-run : Only simulate linking without modifying database}';
    protected $description = 'Link unlinked manuscripts to verified catalog volumes and catalogers from authoritative Fankha citations';

    protected array $stats = [];

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');
        DB::disableQueryLog();

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('Running in DRY-RUN mode. No database modifications will be saved.');
        }

        $initialUnlinked = Manuscript::whereNull('cataloger_id')->count();
        $this->info("Initial unlinked manuscripts: {$initialUnlinked}");

        // 1. نشریه نسخه‌های خطی کتابخانه مرکزی دانشگاه تهران (دفاتر ۱ تا ۱۲)
        $this->linkNashriyehTehranUniversity($dryRun);

        // 2. مرکز دائرةالمعارف بزرگ اسلامی (فهارس نسخ خطی و عکسی به نگارش استاد احمد منزوی)
        $this->linkCenterForGreatIslamicEncyclopedia($dryRun);

        // 3. کتابخانه مسجد اعظم قم (فهرست مختصر ۵ جلدی - رضا استادی، محمود طیار مراغی، مصطفی درایتی)
        $this->linkMasjedAzamQom($dryRun);

        // 4. دانشکده الهیات و معارف اسلامی مشهد (استاد محمود فاضل)
        $this->linkFacultyOfTheologyMashhad($dryRun);

        // 5. کتابخانه ملی تبریز (استاد میرودود سید یونسی - رفع تطبیق شناسه کتابخانه ۱۴)
        $this->linkNationalLibraryTabriz($dryRun);

        // 6. دانشگاه امام صادق (ع) (فهرست ۲ جلدی استاد احمد منزوی)
        $this->linkImamSadiqUniversity($dryRun);

        // 7. کتابخانه مدرسه مروی تهران (آیت‌الله رضا استادی)
        $this->linkMarviSchoolTehran($dryRun);

        // 8. کتابخانه عمومی آیت‌الله نمازی خوی (حجت‌الاسلام علی صدرائی خوئی)
        $this->linkNamaziLibraryKhoy($dryRun);

        // 9. کتابخانه آستان حضرت عبدالعظیم حسنی ع (حجت‌الاسلام علی صدرائی خوئی)
        $this->linkAbdAlAzimShrine($dryRun);

        // 10. مرکز مطالعات و تحقیقات ادیان و مذاهب قم (علی صدرائی خوئی و رضا استادی)
        $this->linkReligionsResearchCenter($dryRun);

        // 11. کتابخانه عمومی فاضل خوانساری (علی صدرائی خوئی)
        $this->linkFazelKhansariLibrary($dryRun);

        // 12. دانشکده حقوق و علوم سیاسی دانشگاه تهران (استاد محمدتقی دانش‌پژوه)
        $this->linkFacultyOfLawTehran($dryRun);

        // 13. کتابخانه مدرسه حجتیه قم (آیت‌الله رضا استادی)
        $this->linkHojjatiehSchoolQom($dryRun);

        // 14. مؤسسه آیت‌الله العظمی بروجردی قم (استاد حسین متقی)
        $this->linkBoroujerdiInstituteQom($dryRun);

        // 15. جمعیت نشر فرهنگ رشت و همدان (استاد محمدتقی دانش‌پژوه)
        $this->linkRashtHamadanCollections($dryRun);

        // 16. فصلنامه میراث شهاب کتابخانه مرعشی (استاد سید محمود مرعشی)
        $this->linkMirasShahabMarashi($dryRun);

        // 17. مجموعه مقالات اوراق عتیق (علی صدرائی خوئی و محمدحسین حکیم)
        $this->linkAwraqAtiqCollections($dryRun);

        // 18. کتابخانه مجلس سنا / شورا (استاد محمدتقی دانش‌پژوه)
        $this->linkSenateLibraryMajlis($dryRun);

        // 19. میکروفیلم‌های کتابخانه مرکزی دانشگاه تهران (استاد محمدتقی دانش‌پژوه)
        $this->linkMicrofilmsTehranUniversity($dryRun);

        // 20. کتابخانه خاندان جلیلی کرمانشاه (استاد سید محمدعلی روضاتی)
        $this->linkJaliliFamilyKermanshah($dryRun);

        // 21. کتابخانه ابراهیم دهگان اراک (استاد رضا استادی)
        $this->linkDehganLibraryArak($dryRun);

        // 22. مدارس علمیه امام صادق چالوس و اصفهان (علی صدرائی خوئی و رضا استادی)
        $this->linkImamSadiqSeminaries($dryRun);

        // 23. فهارس تک‌جلدی چاپی و تصحیح فواصل نگارشی [ف: - صفحه]
        $this->linkSingleVolumeGeneralCatalogs($dryRun);

        // Final recalculation and report
        $totalLinked = array_sum($this->stats);
        $this->newLine();
        $this->info("==========================================");
        $this->info("SUMMARY OF ENRICHMENT RESULTS");
        $this->info("==========================================");
        foreach ($this->stats as $category => $count) {
            $this->line(" - {$category}: " . number_format($count) . " manuscripts");
        }
        $this->info("------------------------------------------");
        $this->info("TOTAL MANUSCRIPTS LINKED: " . number_format($totalLinked));

        if (!$dryRun) {
            $this->recalculateCatalogersAndVolumes();
            $remaining = Manuscript::whereNull('cataloger_id')->count();
            $this->info("Remaining unlinked manuscripts: {$remaining} (reduced from {$initialUnlinked})");
        }

        return 0;
    }

    /**
     * 1. نشریه نسخه‌های خطی کتابخانه مرکزی دانشگاه تهران (دفاتر ۱ تا ۱۲)
     */
    protected function linkNashriyehTehranUniversity(bool $dryRun): void
    {
        $this->info("1. Processing نشریه نسخه‌های خطی دانشگاه تهران (دفاتر ۱ تا ۱۲)...");
        $daneshPazhouh = Cataloger::find(61);
        $afshar = Cataloger::find(7);

        // Register / ensure catalog volumes for Nashriyeh 1..13
        $nashriyehVols = [];
        for ($v = 1; $v <= 13; $v++) {
            $vol = CatalogVolume::firstOrCreate(
                [
                    'catalog_id' => 1,
                    'volume_number' => $v,
                    'citation_pattern' => "نشریه: {$v}",
                ],
                [
                    'title' => "نشریه نسخه‌های خطی کتابخانه مرکزی دانشگاه تهران - دفتر {$v}",
                    'publisher' => 'انتشارات دانشگاه تهران',
                    'metadata' => [
                        'series' => 'نشریه نسخه‌های خطی کتابخانه مرکزی دانشگاه تهران',
                        'editors' => ['محمدتقی دانش‌پژوه', 'ایرج افشار'],
                    ],
                ]
            );

            if (!$dryRun) {
                $vol->catalogers()->syncWithoutDetaching([61, 7]);
            }
            $nashriyehVols[$v] = $vol->id;
        }

        // Match manuscripts where citation contains [نشریه: X-...]
        $linked = 0;
        Manuscript::whereNull('cataloger_id')
            ->where('metadata->catalog_citation', 'LIKE', '%نشریه%')
            ->select(['id', 'metadata'])
            ->chunkById(1000, function ($chunk) use (&$linked, $nashriyehVols, $dryRun) {
                $updatesByVol = [];
                foreach ($chunk as $ms) {
                    $citation = $ms->metadata['catalog_citation'] ?? '';
                    if (preg_match('/نشریه[:\s]+(\d+)/u', $citation, $m)) {
                        $vNum = (int) $m[1];
                        if (isset($nashriyehVols[$vNum])) {
                            $volId = $nashriyehVols[$vNum];
                            $updatesByVol[$volId][] = $ms->id;
                            $linked++;
                        }
                    }
                }

                if (!$dryRun) {
                    foreach ($updatesByVol as $volId => $ids) {
                        DB::table('manuscripts')->whereIn('id', $ids)->update([
                            'catalog_volume_id' => $volId,
                            'cataloger_id' => 61, // Primary: محمدتقی دانش‌پژوه
                        ]);
                    }
                }
            });

        $this->stats['نشریه نسخه‌های خطی دانشگاه تهران (دانش‌پژوه و افشار)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 2. مرکز دائرةالمعارف بزرگ اسلامی (استاد احمد منزوی)
     */
    protected function linkCenterForGreatIslamicEncyclopedia(bool $dryRun): void
    {
        $this->info("2. Processing مرکز دائرةالمعارف بزرگ اسلامی (استاد احمد منزوی)...");
        $monzavi = Cataloger::find(5);

        // Register volumes 1, 2, 3 and photography volume 1
        $vols = [];
        for ($v = 1; $v <= 3; $v++) {
            $vol = CatalogVolume::firstOrCreate(
                [
                    'library_id' => 20,
                    'catalog_id' => 1,
                    'volume_number' => $v,
                ],
                [
                    'title' => "فهرست نسخه‌های خطی مرکز دائرةالمعارف بزرگ اسلامی - جلد {$v}",
                    'citation_pattern' => "ف: {$v}",
                    'publisher' => 'مرکز دائرةالمعارف بزرگ اسلامی',
                ]
            );
            if (!$dryRun) {
                $vol->catalogers()->syncWithoutDetaching([5]);
            }
            $vols[$v] = $vol->id;
        }

        $photoVol = CatalogVolume::firstOrCreate(
            [
                'library_id' => 20,
                'catalog_id' => 1,
                'citation_pattern' => 'عکسی ف: 1',
            ],
            [
                'title' => 'فهرست نسخه‌های عکسی مرکز دائرةالمعارف بزرگ اسلامی - جلد اول',
                'volume_number' => 1,
                'publisher' => 'مرکز دائرةالمعارف بزرگ اسلامی',
            ]
        );
        if (!$dryRun) {
            $photoVol->catalogers()->syncWithoutDetaching([5]);
        }

        $linked = 0;
        Manuscript::where('library_id', 20)
            ->whereNull('cataloger_id')
            ->select(['id', 'metadata'])
            ->chunkById(1000, function ($chunk) use (&$linked, $vols, $photoVol, $dryRun) {
                $updates = [];
                foreach ($chunk as $ms) {
                    $citation = $ms->metadata['catalog_citation'] ?? '';
                    $targetVolId = $vols[1] ?? null;

                    if (str_contains($citation, 'عکسی')) {
                        $targetVolId = $photoVol->id;
                    } elseif (preg_match('/(?:ف|فهرست)[:\s]+(\d+)/u', $citation, $m)) {
                        $vNum = (int) $m[1];
                        if (isset($vols[$vNum])) {
                            $targetVolId = $vols[$vNum];
                        }
                    }

                    $updates[$targetVolId][] = $ms->id;
                    $linked++;
                }

                if (!$dryRun) {
                    foreach ($updates as $volId => $ids) {
                        DB::table('manuscripts')->whereIn('id', $ids)->update([
                            'catalog_volume_id' => $volId,
                            'cataloger_id' => 5, // احمد منزوی
                        ]);
                    }
                }
            });

        $this->stats['مرکز دائرةالمعارف بزرگ اسلامی (احمد منزوی)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 3. کتابخانه مسجد اعظم قم (رضا استادی، طیار مراغی، مصطفی درایتی)
     */
    protected function linkMasjedAzamQom(bool $dryRun): void
    {
        $this->info("3. Processing کتابخانه مسجد اعظم قم (استادی، طیار مراغی، درایتی)...");

        // Ensure volume 1
        $vol1 = CatalogVolume::firstOrCreate(
            [
                'library_id' => 21,
                'catalog_id' => 1,
                'volume_number' => 1,
            ],
            [
                'title' => 'فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم - جلد اول',
                'citation_pattern' => 'ف مخ: 1',
            ]
        );
        if (!$dryRun) {
            $vol1->catalogers()->syncWithoutDetaching([21]); // رضا استادی
        }

        $vols = [
            1 => ['vol_id' => $vol1->id, 'cat_id' => 21], // استادی
            2 => ['vol_id' => 448, 'cat_id' => 64],       // طیار مراغی
            3 => ['vol_id' => 449, 'cat_id' => 64],
            4 => ['vol_id' => 450, 'cat_id' => 64],
            5 => ['vol_id' => 451, 'cat_id' => 64],
        ];

        $linked = 0;
        Manuscript::where('library_id', 21)
            ->whereNull('cataloger_id')
            ->select(['id', 'metadata'])
            ->chunkById(1000, function ($chunk) use (&$linked, $vols, $dryRun) {
                $grouped = [];
                foreach ($chunk as $ms) {
                    $citation = $ms->metadata['catalog_citation'] ?? '';
                    // Extract volume number from [ف مخ: X-...] or [ف مخ - X - ...]
                    if (preg_match('/(?:ف\s*مخ|ف)[:\s\-]+(\d+)/u', $citation, $m)) {
                        $vNum = (int) $m[1];
                        if (isset($vols[$vNum])) {
                            $target = $vols[$vNum];
                            $key = "{$target['vol_id']}_{$target['cat_id']}";
                            $grouped[$key]['vol'] = $target['vol_id'];
                            $grouped[$key]['cat'] = $target['cat_id'];
                            $grouped[$key]['ids'][] = $ms->id;
                            $linked++;
                        }
                    }
                }

                if (!$dryRun) {
                    foreach ($grouped as $g) {
                        DB::table('manuscripts')->whereIn('id', $g['ids'])->update([
                            'catalog_volume_id' => $g['vol'],
                            'cataloger_id' => $g['cat'],
                        ]);
                    }
                }
            });

        $this->stats['کتابخانه مسجد اعظم قم (استادی و طیار مراغی)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 4. دانشکده الهیات مشهد (استاد محمود فاضل)
     */
    protected function linkFacultyOfTheologyMashhad(bool $dryRun): void
    {
        $this->info("4. Processing دانشکده الهیات مشهد (استاد محمود فاضل)...");
        $volTheology = CatalogVolume::firstOrCreate(
            [
                'library_id' => 40,
                'catalog_id' => 1,
                'volume_number' => 1,
            ],
            [
                'title' => 'فهرست نسخه‌های خطی کتابخانه دانشکده الهیات و معارف اسلامی مشهد',
                'citation_pattern' => 'ف: الهیات مشهد',
                'publisher' => 'دانشگاه فردوسی مشهد',
            ]
        );
        if (!$dryRun) {
            $volTheology->catalogers()->syncWithoutDetaching([66]); // محمود فاضل
        }

        $linked = 0;
        Manuscript::whereIn('library_id', [40, 341])
            ->whereNull('cataloger_id')
            ->chunkById(1000, function ($chunk) use (&$linked, $volTheology, $dryRun) {
                $ids = $chunk->pluck('id')->all();
                $linked += count($ids);
                if (!$dryRun) {
                    DB::table('manuscripts')->whereIn('id', $ids)->update([
                        'catalog_volume_id' => $volTheology->id,
                        'cataloger_id' => 66, // محمود فاضل
                    ]);
                }
            });

        $this->stats['دانشکده الهیات مشهد (محمود فاضل)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 5. کتابخانه ملی تبریز (استاد میرودود سید یونسی - رفع شناسه کتابخانه ۱۴)
     */
    protected function linkNationalLibraryTabriz(bool $dryRun): void
    {
        $this->info("5. Processing کتابخانه ملی تبریز (استاد میرودود سید یونسی)...");
        $vols = [
            1 => 474,
            2 => 475,
            3 => 476,
        ];

        // Also update volumes 474..476 to be associated with library 14
        if (!$dryRun) {
            DB::table('catalog_volumes')->whereIn('id', [474, 475, 476])->update(['library_id' => 14]);
        }

        $linked = 0;
        Manuscript::where('library_id', 14)
            ->whereNull('cataloger_id')
            ->chunkById(1000, function ($chunk) use (&$linked, $vols, $dryRun) {
                $grouped = [];
                foreach ($chunk as $ms) {
                    $citation = $ms->metadata['catalog_citation'] ?? '';
                    $vNum = 1;
                    if (preg_match('/(?:ف|فهرست)[:\s]+(\d+)/u', $citation, $m)) {
                        $vNum = (int) $m[1];
                    }
                    $volId = $vols[$vNum] ?? 474;
                    $grouped[$volId][] = $ms->id;
                    $linked++;
                }

                if (!$dryRun) {
                    foreach ($grouped as $volId => $ids) {
                        DB::table('manuscripts')->whereIn('id', $ids)->update([
                            'catalog_volume_id' => $volId,
                            'cataloger_id' => 70, // میرودود سید یونسی
                        ]);
                    }
                }
            });

        $this->stats['کتابخانه ملی تبریز (میرودود سید یونسی)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 6. دانشگاه امام صادق (ع) (فهرست ۲ جلدی استاد احمد منزوی)
     */
    protected function linkImamSadiqUniversity(bool $dryRun): void
    {
        $this->info("6. Processing دانشگاه امام صادق (ع) (استاد احمد منزوی)...");
        $vols = [];
        for ($v = 1; $v <= 2; $v++) {
            $vol = CatalogVolume::firstOrCreate(
                [
                    'library_id' => 83,
                    'catalog_id' => 1,
                    'volume_number' => $v,
                ],
                [
                    'title' => "فهرست نسخه‌های خطی کتابخانه دانشگاه امام صادق (ع) - جلد {$v}",
                    'citation_pattern' => "ف: {$v}",
                    'publisher' => 'دانشگاه امام صادق (ع)',
                ]
            );
            if (!$dryRun) {
                $vol->catalogers()->syncWithoutDetaching([5]); // احمد منزوی
            }
            $vols[$v] = $vol->id;
        }

        $linked = 0;
        Manuscript::where('library_id', 83)
            ->whereNull('cataloger_id')
            ->chunkById(1000, function ($chunk) use (&$linked, $vols, $dryRun) {
                $grouped = [];
                foreach ($chunk as $ms) {
                    $citation = $ms->metadata['catalog_citation'] ?? '';
                    $vNum = 1;
                    if (preg_match('/(?:ف|فهرست)[:\s]+(\d+)/u', $citation, $m)) {
                        $vNum = (int) $m[1];
                    }
                    $volId = $vols[$vNum] ?? $vols[1];
                    $grouped[$volId][] = $ms->id;
                    $linked++;
                }

                if (!$dryRun) {
                    foreach ($grouped as $volId => $ids) {
                        DB::table('manuscripts')->whereIn('id', $ids)->update([
                            'catalog_volume_id' => $volId,
                            'cataloger_id' => 5, // احمد منزوی
                        ]);
                    }
                }
            });

        $this->stats['دانشگاه امام صادق (احمد منزوی)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 7. کتابخانه مدرسه مروی تهران (آیت‌الله رضا استادی)
     */
    protected function linkMarviSchoolTehran(bool $dryRun): void
    {
        $this->info("7. Processing کتابخانه مدرسه مروی تهران (آیت‌الله رضا استادی)...");
        $volMarvi = CatalogVolume::firstOrCreate(
            [
                'library_id' => 7,
                'catalog_id' => 1,
                'volume_number' => 1,
            ],
            [
                'title' => 'فهرست نسخه‌های خطی کتابخانه مدرسه مروی تهران',
                'citation_pattern' => 'ف: مروی',
            ]
        );
        if (!$dryRun) {
            $volMarvi->catalogers()->syncWithoutDetaching([21]); // رضا استادی
        }

        $linked = 0;
        Manuscript::where('library_id', 7)
            ->whereNull('cataloger_id')
            ->chunkById(1000, function ($chunk) use (&$linked, $volMarvi, $dryRun) {
                $ids = $chunk->pluck('id')->all();
                $linked += count($ids);
                if (!$dryRun) {
                    DB::table('manuscripts')->whereIn('id', $ids)->update([
                        'catalog_volume_id' => $volMarvi->id,
                        'cataloger_id' => 21, // رضا استادی
                    ]);
                }
            });

        $this->stats['کتابخانه مدرسه مروی تهران (رضا استادی)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 8. کتابخانه عمومی آیت‌الله نمازی خوی (حجت‌الاسلام علی صدرائی خوئی)
     */
    protected function linkNamaziLibraryKhoy(bool $dryRun): void
    {
        $this->info("8. Processing کتابخانه عمومی آیت‌الله نمازی خوی (علی صدرائی خوئی)...");
        $volNamazi = CatalogVolume::firstOrCreate(
            [
                'library_id' => 19,
                'catalog_id' => 1,
                'volume_number' => 1,
            ],
            [
                'title' => 'فهرست نسخه‌های خطی کتابخانه عمومی آیت‌الله نمازی خوی',
                'citation_pattern' => 'ف: نمازی خوی',
                'publisher' => 'دلیل ما',
            ]
        );
        if (!$dryRun) {
            $volNamazi->catalogers()->syncWithoutDetaching([44]); // علی صدرائی خوئی
        }

        $linked = 0;
        Manuscript::where('library_id', 19)
            ->whereNull('cataloger_id')
            ->chunkById(1000, function ($chunk) use (&$linked, $volNamazi, $dryRun) {
                $ids = $chunk->pluck('id')->all();
                $linked += count($ids);
                if (!$dryRun) {
                    DB::table('manuscripts')->whereIn('id', $ids)->update([
                        'catalog_volume_id' => $volNamazi->id,
                        'cataloger_id' => 44, // علی صدرائی خوئی
                    ]);
                }
            });

        $this->stats['کتابخانه نمازی خوی (علی صدرائی خوئی)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 9. کتابخانه آستان حضرت عبدالعظیم حسنی ع (حجت‌الاسلام علی صدرائی خوئی)
     */
    protected function linkAbdAlAzimShrine(bool $dryRun): void
    {
        $this->info("9. Processing کتابخانه آستان حضرت عبدالعظیم حسنی (علی صدرائی خوئی)...");
        $vols = [];
        for ($v = 1; $v <= 2; $v++) {
            $vol = CatalogVolume::firstOrCreate(
                [
                    'library_id' => 69,
                    'catalog_id' => 1,
                    'volume_number' => $v,
                ],
                [
                    'title' => "فهرست نسخه‌های خطی کتابخانه آستان حضرت عبدالعظیم حسنی (ع) - جلد {$v}",
                    'citation_pattern' => "ف: {$v}",
                ]
            );
            if (!$dryRun) {
                $vol->catalogers()->syncWithoutDetaching([44]); // علی صدرائی خوئی
            }
            $vols[$v] = $vol->id;
        }

        $linked = 0;
        Manuscript::where('library_id', 69)
            ->whereNull('cataloger_id')
            ->chunkById(1000, function ($chunk) use (&$linked, $vols, $dryRun) {
                $grouped = [];
                foreach ($chunk as $ms) {
                    $citation = $ms->metadata['catalog_citation'] ?? '';
                    $vNum = 1;
                    if (preg_match('/(?:ف|فهرست)[:\s]+(\d+)/u', $citation, $m)) {
                        $vNum = (int) $m[1];
                    }
                    $volId = $vols[$vNum] ?? $vols[1];
                    $grouped[$volId][] = $ms->id;
                    $linked++;
                }

                if (!$dryRun) {
                    foreach ($grouped as $volId => $ids) {
                        DB::table('manuscripts')->whereIn('id', $ids)->update([
                            'catalog_volume_id' => $volId,
                            'cataloger_id' => 44, // علی صدرائی خوئی
                        ]);
                    }
                }
            });

        $this->stats['آستان حضرت عبدالعظیم (علی صدرائی خوئی)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 10. مرکز مطالعات و تحقیقات ادیان و مذاهب قم (علی صدرائی خوئی و رضا استادی)
     */
    protected function linkReligionsResearchCenter(bool $dryRun): void
    {
        $this->info("10. Processing مرکز مطالعات ادیان و مذاهب (علی صدرائی خوئی)...");
        $vols = [];
        for ($v = 1; $v <= 2; $v++) {
            $vol = CatalogVolume::firstOrCreate(
                [
                    'library_id' => 41,
                    'catalog_id' => 1,
                    'volume_number' => $v,
                ],
                [
                    'title' => "فهرست نسخه‌های خطی مرکز مطالعات و تحقیقات ادیان و مذاهب - جلد {$v}",
                    'citation_pattern' => "ف: {$v}",
                ]
            );
            if (!$dryRun) {
                $vol->catalogers()->syncWithoutDetaching([44, 21]); // صدرائی خوئی و استادی
            }
            $vols[$v] = $vol->id;
        }

        $linked = 0;
        Manuscript::where('library_id', 41)
            ->whereNull('cataloger_id')
            ->chunkById(1000, function ($chunk) use (&$linked, $vols, $dryRun) {
                $grouped = [];
                foreach ($chunk as $ms) {
                    $citation = $ms->metadata['catalog_citation'] ?? '';
                    $vNum = 1;
                    if (preg_match('/(?:ف|فهرست)[:\s]+(\d+)/u', $citation, $m)) {
                        $vNum = (int) $m[1];
                    }
                    $volId = $vols[$vNum] ?? $vols[1];
                    $grouped[$volId][] = $ms->id;
                    $linked++;
                }

                if (!$dryRun) {
                    foreach ($grouped as $volId => $ids) {
                        DB::table('manuscripts')->whereIn('id', $ids)->update([
                            'catalog_volume_id' => $volId,
                            'cataloger_id' => 44, // علی صدرائی خوئی
                        ]);
                    }
                }
            });

        $this->stats['مرکز مطالعات ادیان و مذاهب (علی صدرائی خوئی)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 11. کتابخانه عمومی فاضل خوانساری (علی صدرائی خوئی)
     */
    protected function linkFazelKhansariLibrary(bool $dryRun): void
    {
        $this->info("11. Processing کتابخانه فاضل خوانساری (علی صدرائی خوئی)...");
        $vols = [];
        for ($v = 1; $v <= 2; $v++) {
            $vol = CatalogVolume::firstOrCreate(
                [
                    'library_id' => 90,
                    'catalog_id' => 1,
                    'volume_number' => $v,
                ],
                [
                    'title' => "فهرست نسخه‌های خطی کتابخانه عمومی فاضل خوانساری - جلد {$v}",
                    'citation_pattern' => "ف: {$v}",
                ]
            );
            if (!$dryRun) {
                $vol->catalogers()->syncWithoutDetaching([44]); // علی صدرائی خوئی
            }
            $vols[$v] = $vol->id;
        }

        $linked = 0;
        Manuscript::where('library_id', 90)
            ->whereNull('cataloger_id')
            ->chunkById(1000, function ($chunk) use (&$linked, $vols, $dryRun) {
                $grouped = [];
                foreach ($chunk as $ms) {
                    $citation = $ms->metadata['catalog_citation'] ?? '';
                    $vNum = 1;
                    if (preg_match('/(?:ف|فهرست)[:\s]+(\d+)/u', $citation, $m)) {
                        $vNum = (int) $m[1];
                    }
                    $volId = $vols[$vNum] ?? $vols[1];
                    $grouped[$volId][] = $ms->id;
                    $linked++;
                }

                if (!$dryRun) {
                    foreach ($grouped as $volId => $ids) {
                        DB::table('manuscripts')->whereIn('id', $ids)->update([
                            'catalog_volume_id' => $volId,
                            'cataloger_id' => 44, // علی صدرائی خوئی
                        ]);
                    }
                }
            });

        $this->stats['کتابخانه فاضل خوانساری (علی صدرائی خوئی)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 12. دانشکده حقوق و علوم سیاسی دانشگاه تهران (استاد محمدتقی دانش‌پژوه)
     */
    protected function linkFacultyOfLawTehran(bool $dryRun): void
    {
        $this->info("12. Processing دانشکده حقوق دانشگاه تهران (استاد محمدتقی دانش‌پژوه)...");
        $volLaw = CatalogVolume::firstOrCreate(
            [
                'library_id' => 26,
                'catalog_id' => 1,
                'volume_number' => 1,
            ],
            [
                'title' => 'فهرست کتب خطی کتابخانه دانشکده حقوق دانشگاه تهران',
                'citation_pattern' => 'ف: حقوق',
                'publisher' => 'انتشارات دانشگاه تهران',
            ]
        );
        if (!$dryRun) {
            $volLaw->catalogers()->syncWithoutDetaching([61]); // محمدتقی دانش‌پژوه
        }

        $linked = 0;
        Manuscript::where('library_id', 26)
            ->whereNull('cataloger_id')
            ->chunkById(1000, function ($chunk) use (&$linked, $volLaw, $dryRun) {
                $ids = $chunk->pluck('id')->all();
                $linked += count($ids);
                if (!$dryRun) {
                    DB::table('manuscripts')->whereIn('id', $ids)->update([
                        'catalog_volume_id' => $volLaw->id,
                        'cataloger_id' => 61, // محمدتقی دانش‌پژوه
                    ]);
                }
            });

        $this->stats['دانشکده حقوق دانشگاه تهران (محمدتقی دانش‌پژوه)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 13. کتابخانه مدرسه حجتیه قم (آیت‌الله رضا استادی)
     */
    protected function linkHojjatiehSchoolQom(bool $dryRun): void
    {
        $this->info("13. Processing کتابخانه مدرسه حجتیه قم (آیت‌الله رضا استادی)...");
        $volHojjatieh = CatalogVolume::firstOrCreate(
            [
                'library_id' => 115,
                'catalog_id' => 1,
                'volume_number' => 1,
            ],
            [
                'title' => 'فهرست نسخه‌های خطی کتابخانه مدرسه حجتیه قم',
                'citation_pattern' => 'ف: حجتیه',
            ]
        );
        if (!$dryRun) {
            $volHojjatieh->catalogers()->syncWithoutDetaching([21]); // رضا استادی
        }

        $linked = 0;
        Manuscript::where('library_id', 115)
            ->whereNull('cataloger_id')
            ->chunkById(1000, function ($chunk) use (&$linked, $volHojjatieh, $dryRun) {
                $ids = $chunk->pluck('id')->all();
                $linked += count($ids);
                if (!$dryRun) {
                    DB::table('manuscripts')->whereIn('id', $ids)->update([
                        'catalog_volume_id' => $volHojjatieh->id,
                        'cataloger_id' => 21, // رضا استادی
                    ]);
                }
            });

        $this->stats['مدرسه حجتیه قم (رضا استادی)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 14. مؤسسه آیت‌الله العظمی بروجردی قم (استاد حسین متقی)
     */
    protected function linkBoroujerdiInstituteQom(bool $dryRun): void
    {
        $this->info("14. Processing مؤسسه آیت‌الله بروجردی قم (استاد حسین متقی)...");
        $vols = [];
        for ($v = 1; $v <= 2; $v++) {
            $vol = CatalogVolume::firstOrCreate(
                [
                    'library_id' => 37,
                    'catalog_id' => 1,
                    'volume_number' => $v,
                ],
                [
                    'title' => "فهرست نسخه‌های خطی کتابخانه مؤسسه آیت‌الله بروجردی - جلد {$v}",
                    'citation_pattern' => "ف: {$v}",
                ]
            );
            if (!$dryRun) {
                $vol->catalogers()->syncWithoutDetaching([18]); // حسین متقی
            }
            $vols[$v] = $vol->id;
        }

        $linked = 0;
        Manuscript::where('library_id', 37)
            ->whereNull('cataloger_id')
            ->chunkById(1000, function ($chunk) use (&$linked, $vols, $dryRun) {
                $grouped = [];
                foreach ($chunk as $ms) {
                    $citation = $ms->metadata['catalog_citation'] ?? '';
                    $vNum = 1;
                    if (preg_match('/(?:ف|فهرست)[:\s]+(\d+)/u', $citation, $m)) {
                        $vNum = (int) $m[1];
                    }
                    $volId = $vols[$vNum] ?? $vols[1];
                    $grouped[$volId][] = $ms->id;
                    $linked++;
                }

                if (!$dryRun) {
                    foreach ($grouped as $volId => $ids) {
                        DB::table('manuscripts')->whereIn('id', $ids)->update([
                            'catalog_volume_id' => $volId,
                            'cataloger_id' => 18, // حسین متقی
                        ]);
                    }
                }
            });

        $this->stats['مؤسسه آیت‌الله بروجردی (حسین متقی)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 15. جمعیت نشر فرهنگ رشت و همدان (استاد محمدتقی دانش‌پژوه)
     */
    protected function linkRashtHamadanCollections(bool $dryRun): void
    {
        $this->info("15. Processing فهرست کتابخانه‌های رشت و همدان (استاد محمدتقی دانش‌پژوه)...");
        $volRasht = CatalogVolume::firstOrCreate(
            [
                'catalog_id' => 1,
                'citation_pattern' => 'رشت و همدان: ف',
            ],
            [
                'title' => 'فهرست نسخه‌های خطی کتابخانه‌های رشت و همدان',
                'volume_number' => 1,
                'publisher' => 'جمعیت نشر فرهنگ',
            ]
        );
        if (!$dryRun) {
            $volRasht->catalogers()->syncWithoutDetaching([61]); // محمدتقی دانش‌پژوه
        }

        $linked = 0;
        Manuscript::whereNull('cataloger_id')
            ->where('metadata->catalog_citation', 'LIKE', '%رشت و همدان%')
            ->chunkById(1000, function ($chunk) use (&$linked, $volRasht, $dryRun) {
                $ids = $chunk->pluck('id')->all();
                $linked += count($ids);
                if (!$dryRun) {
                    DB::table('manuscripts')->whereIn('id', $ids)->update([
                        'catalog_volume_id' => $volRasht->id,
                        'cataloger_id' => 61, // محمدتقی دانش‌پژوه
                    ]);
                }
            });

        $this->stats['فهرست کتابخانه‌های رشت و همدان (محمدتقی دانش‌پژوه)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 16. فصلنامه میراث شهاب کتابخانه مرعشی (استاد سید محمود مرعشی)
     */
    protected function linkMirasShahabMarashi(bool $dryRun): void
    {
        $this->info("16. Processing فصلنامه میراث شهاب کتابخانه مرعشی (استاد سید محمود مرعشی)...");
        $volMiras = CatalogVolume::firstOrCreate(
            [
                'library_id' => 2,
                'catalog_id' => 1,
                'citation_pattern' => 'میراث شهاب',
            ],
            [
                'title' => 'فصلنامه میراث شهاب کتابخانه عمومی آیت‌الله مرعشی نجفی',
                'volume_number' => 1,
                'publisher' => 'کتابخانه آیت‌الله مرعشی نجفی',
            ]
        );
        if (!$dryRun) {
            $volMiras->catalogers()->syncWithoutDetaching([33]); // سید محمود مرعشی
        }

        $linked = 0;
        Manuscript::where('library_id', 2)
            ->whereNull('cataloger_id')
            ->where('metadata->catalog_citation', 'LIKE', '%میراث شهاب%')
            ->chunkById(1000, function ($chunk) use (&$linked, $volMiras, $dryRun) {
                $ids = $chunk->pluck('id')->all();
                $linked += count($ids);
                if (!$dryRun) {
                    DB::table('manuscripts')->whereIn('id', $ids)->update([
                        'catalog_volume_id' => $volMiras->id,
                        'cataloger_id' => 33, // سید محمود مرعشی
                    ]);
                }
            });

        $this->stats['فصلنامه میراث شهاب (سید محمود مرعشی)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 17. مجموعه مقالات اوراق عتیق (علی صدرائی خوئی و محمدحسین حکیم)
     */
    protected function linkAwraqAtiqCollections(bool $dryRun): void
    {
        $this->info("17. Processing مجموعه اوراق عتیق (علی صدرائی خوئی و محمدحسین حکیم)...");
        $vols = [];
        for ($v = 1; $v <= 2; $v++) {
            $vol = CatalogVolume::firstOrCreate(
                [
                    'catalog_id' => 1,
                    'volume_number' => $v,
                    'citation_pattern' => "اوراق عتیق: {$v}",
                ],
                [
                    'title' => "مجموعه مقالات و فهارس اوراق عتیق - دفتر {$v}",
                    'publisher' => 'کتابخانه مجلس شورای اسلامی',
                ]
            );
            if (!$dryRun) {
                $vol->catalogers()->syncWithoutDetaching([44, 32]); // صدرائی خوئی و حکیم
            }
            $vols[$v] = $vol->id;
        }

        $linked = 0;
        Manuscript::whereNull('cataloger_id')
            ->where('metadata->catalog_citation', 'LIKE', '%اوراق عتیق%')
            ->chunkById(1000, function ($chunk) use (&$linked, $vols, $dryRun) {
                $grouped = [];
                foreach ($chunk as $ms) {
                    $citation = $ms->metadata['catalog_citation'] ?? '';
                    $vNum = 1;
                    if (preg_match('/اوراق عتیق[:\s]+(\d+)/u', $citation, $m)) {
                        $vNum = (int) $m[1];
                    }
                    $volId = $vols[$vNum] ?? $vols[1];
                    $grouped[$volId][] = $ms->id;
                    $linked++;
                }

                if (!$dryRun) {
                    foreach ($grouped as $volId => $ids) {
                        DB::table('manuscripts')->whereIn('id', $ids)->update([
                            'catalog_volume_id' => $volId,
                            'cataloger_id' => 44, // علی صدرائی خوئی
                        ]);
                    }
                }
            });

        $this->stats['مجموعه مقالات اوراق عتیق (علی صدرائی خوئی و محمدحسین حکیم)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 18. کتابخانه مجلس سنا / شورا (استاد محمدتقی دانش‌پژوه)
     */
    protected function linkSenateLibraryMajlis(bool $dryRun): void
    {
        $this->info("18. Processing کتابخانه مجلس سنا (استاد محمدتقی دانش‌پژوه)...");
        $vols = [];
        for ($v = 1; $v <= 2; $v++) {
            $vol = CatalogVolume::firstOrCreate(
                [
                    'library_id' => 883,
                    'catalog_id' => 1,
                    'volume_number' => $v,
                ],
                [
                    'title' => "فهرست نسخه‌های خطی کتابخانه مجلس سنا - جلد {$v}",
                    'citation_pattern' => "سنا: ف: {$v}",
                    'publisher' => 'کتابخانه مجلس شورای اسلامی',
                ]
            );
            if (!$dryRun) {
                $vol->catalogers()->syncWithoutDetaching([61]); // محمدتقی دانش‌پژوه
            }
            $vols[$v] = $vol->id;
        }

        $volBrief = CatalogVolume::firstOrCreate(
            [
                'library_id' => 883,
                'catalog_id' => 1,
                'citation_pattern' => 'مختصر ف: سنا',
            ],
            [
                'title' => 'فهرست مختصر نسخه‌های خطی مجلس سنا',
                'volume_number' => 1,
                'publisher' => 'کتابخانه مجلس شورای اسلامی',
            ]
        );
        if (!$dryRun) {
            $volBrief->catalogers()->syncWithoutDetaching([61]); // محمدتقی دانش‌پژوه
        }

        $linked = 0;
        Manuscript::where('library_id', 883)
            ->whereNull('cataloger_id')
            ->where(function ($q) {
                $q->where('metadata->catalog_citation', 'LIKE', '%سنا%')
                  ->orWhere('metadata->catalog_citation', 'LIKE', '%مختصر%');
            })
            ->select(['id', 'metadata'])
            ->chunkById(1000, function ($chunk) use (&$linked, $vols, $volBrief, $dryRun) {
                $grouped = [];
                foreach ($chunk as $ms) {
                    $citation = $ms->metadata['catalog_citation'] ?? '';
                    if (str_contains($citation, 'مختصر')) {
                        $volId = $volBrief->id;
                    } else {
                        $vNum = 1;
                        if (preg_match('/(?:سنا|ف)[:\s]+(\d+)/u', $citation, $m)) {
                            $vNum = (int) $m[1];
                        }
                        $volId = $vols[$vNum] ?? $vols[1];
                    }
                    $grouped[$volId][] = $ms->id;
                    $linked++;
                }

                if (!$dryRun) {
                    foreach ($grouped as $volId => $ids) {
                        DB::table('manuscripts')->whereIn('id', $ids)->update([
                            'catalog_volume_id' => $volId,
                            'cataloger_id' => 61, // محمدتقی دانش‌پژوه
                        ]);
                    }
                }
            });

        $this->stats['کتابخانه مجلس سنا و فهرست مختصر (محمدتقی دانش‌پژوه)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 19. میکروفیلم‌های کتابخانه مرکزی دانشگاه تهران (استاد محمدتقی دانش‌پژوه)
     */
    protected function linkMicrofilmsTehranUniversity(bool $dryRun): void
    {
        $this->info("19. Processing فهرست میکروفیلم‌های دانشگاه تهران (استاد محمدتقی دانش‌پژوه)...");
        $volFilm = CatalogVolume::firstOrCreate(
            [
                'catalog_id' => 1,
                'citation_pattern' => 'فیلمها',
            ],
            [
                'title' => 'فهرست میکروفیلم‌های کتابخانه مرکزی دانشگاه تهران',
                'volume_number' => 1,
                'publisher' => 'انتشارات دانشگاه تهران',
            ]
        );
        if (!$dryRun) {
            $volFilm->catalogers()->syncWithoutDetaching([61]); // محمدتقی دانش‌پژوه
        }

        $linked = 0;
        Manuscript::whereNull('cataloger_id')
            ->where(function ($q) {
                $q->where('metadata->catalog_citation', 'LIKE', '%فیلم%')
                  ->orWhere('metadata->catalog_citation', 'LIKE', '%چهار کتابخانه مشهد%')
                  ->orWhere('metadata->catalog_citation', 'LIKE', '%چند نسخه%');
            })
            ->chunkById(1000, function ($chunk) use (&$linked, $volFilm, $dryRun) {
                $ids = $chunk->pluck('id')->all();
                $linked += count($ids);
                if (!$dryRun) {
                    DB::table('manuscripts')->whereIn('id', $ids)->update([
                        'catalog_volume_id' => $volFilm->id,
                        'cataloger_id' => 61, // محمدتقی دانش‌پژوه
                    ]);
                }
            });

        $this->stats['فهرست میکروفیلم‌ها و مجموعه‌های مشهد (محمدتقی دانش‌پژوه)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 20. کتابخانه خاندان جلیلی کرمانشاه (استاد سید محمدعلی روضاتی)
     */
    protected function linkJaliliFamilyKermanshah(bool $dryRun): void
    {
        $this->info("20. Processing کتابخانه خاندان جلیلی کرمانشاه (استاد روضاتی)...");
        $volJalili = CatalogVolume::firstOrCreate(
            [
                'library_id' => 103,
                'catalog_id' => 1,
                'volume_number' => 1,
            ],
            [
                'title' => 'فهرست نسخه‌های خطی کتابخانه خاندان جلیلی کرمانشاه',
                'citation_pattern' => 'ف: جلیلی',
            ]
        );
        if (!$dryRun) {
            $volJalili->catalogers()->syncWithoutDetaching([42]); // سید محمدعلی روضاتی
        }

        $linked = 0;
        Manuscript::where('library_id', 103)
            ->whereNull('cataloger_id')
            ->chunkById(1000, function ($chunk) use (&$linked, $volJalili, $dryRun) {
                $ids = $chunk->pluck('id')->all();
                $linked += count($ids);
                if (!$dryRun) {
                    DB::table('manuscripts')->whereIn('id', $ids)->update([
                        'catalog_volume_id' => $volJalili->id,
                        'cataloger_id' => 42, // روضاتی
                    ]);
                }
            });

        $this->stats['کتابخانه خاندان جلیلی (سید محمدعلی روضاتی)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 21. کتابخانه ابراهیم دهگان اراک (استاد رضا استادی)
     */
    protected function linkDehganLibraryArak(bool $dryRun): void
    {
        $this->info("21. Processing کتابخانه ابراهیم دهگان اراک (استاد رضا استادی)...");
        $volDehgan = CatalogVolume::firstOrCreate(
            [
                'library_id' => 59,
                'catalog_id' => 1,
                'volume_number' => 1,
            ],
            [
                'title' => 'فهرست نسخه‌های خطی کتابخانه ابراهیم دهگان اراک',
                'citation_pattern' => 'ف: دهگان',
            ]
        );
        if (!$dryRun) {
            $volDehgan->catalogers()->syncWithoutDetaching([21]); // رضا استادی
        }

        $linked = 0;
        Manuscript::where('library_id', 59)
            ->whereNull('cataloger_id')
            ->chunkById(1000, function ($chunk) use (&$linked, $volDehgan, $dryRun) {
                $ids = $chunk->pluck('id')->all();
                $linked += count($ids);
                if (!$dryRun) {
                    DB::table('manuscripts')->whereIn('id', $ids)->update([
                        'catalog_volume_id' => $volDehgan->id,
                        'cataloger_id' => 21, // استادی
                    ]);
                }
            });

        $this->stats['کتابخانه ابراهیم دهگان (رضا استادی)'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * 22. مدارس علمیه امام صادق چالوس و اصفهان (علی صدرائی خوئی و رضا استادی)
     */
    protected function linkImamSadiqSeminaries(bool $dryRun): void
    {
        $this->info("22. Processing مدارس علمیه امام صادق چالوس و اصفهان...");

        // چالوس: علی صدرائی خوئی (library_id: 56)
        $volChaloos = CatalogVolume::firstOrCreate(
            [
                'library_id' => 56,
                'catalog_id' => 1,
                'volume_number' => 1,
            ],
            [
                'title' => 'فهرست نسخه‌های خطی مدرسه امام صادق (ع) چالوس',
                'citation_pattern' => 'ف: چالوس',
            ]
        );
        if (!$dryRun) {
            $volChaloos->catalogers()->syncWithoutDetaching([44]); // علی صدرائی خوئی
        }

        // اصفهان: رضا استادی (library_id: 109)
        $volIsfahan = CatalogVolume::firstOrCreate(
            [
                'library_id' => 109,
                'catalog_id' => 1,
                'volume_number' => 1,
            ],
            [
                'title' => 'فهرست نسخه‌های خطی مدرسه امام صادق (ع) چهارباغ اصفهان',
                'citation_pattern' => 'ف: اصفهان',
            ]
        );
        if (!$dryRun) {
            $volIsfahan->catalogers()->syncWithoutDetaching([21]); // رضا استادی
        }

        $linked56 = 0;
        Manuscript::where('library_id', 56)
            ->whereNull('cataloger_id')
            ->chunkById(1000, function ($chunk) use (&$linked56, $volChaloos, $dryRun) {
                $ids = $chunk->pluck('id')->all();
                $linked56 += count($ids);
                if (!$dryRun) {
                    DB::table('manuscripts')->whereIn('id', $ids)->update([
                        'catalog_volume_id' => $volChaloos->id,
                        'cataloger_id' => 44, // علی صدرائی خوئی
                    ]);
                }
            });

        $linked109 = 0;
        Manuscript::where('library_id', 109)
            ->whereNull('cataloger_id')
            ->chunkById(1000, function ($chunk) use (&$linked109, $volIsfahan, $dryRun) {
                $ids = $chunk->pluck('id')->all();
                $linked109 += count($ids);
                if (!$dryRun) {
                    DB::table('manuscripts')->whereIn('id', $ids)->update([
                        'catalog_volume_id' => $volIsfahan->id,
                        'cataloger_id' => 21, // رضا استادی
                    ]);
                }
            });

        $totalSeminaries = $linked56 + $linked109;
        $this->stats['مدارس امام صادق چالوس و اصفهان (صدرائی خوئی و استادی)'] = $totalSeminaries;
        $this->info("   -> Linked {$totalSeminaries} manuscripts.");
    }

    /**
     * 23. تطبیق کتابخانه‌های دارای مجلدات ثبت‌شده که به دلیل نقص فاصله در ارجاع فنخا نادیده گرفته شده بودند
     */
    protected function linkSingleVolumeGeneralCatalogs(bool $dryRun): void
    {
        $this->info("23. Processing کتابخانه‌های دارای مجلد ثبت‌شده با ارجاع فاصله‌دار [ف: - صفحه]...");

        // Find all catalog volumes that have an attached cataloger
        $registeredVolumes = CatalogVolume::whereNotNull('library_id')
            ->with('catalogers')
            ->get();

        $volsByLib = [];
        foreach ($registeredVolumes as $vol) {
            $catId = $vol->catalogers->first()?->id;
            if ($catId) {
                $vNum = $vol->volume_number ?: 1;
                $volsByLib[$vol->library_id][$vNum] = [
                    'vol_id' => $vol->id,
                    'cat_id' => $catId,
                ];
            }
        }

        $linked = 0;
        foreach ($volsByLib as $libId => $rules) {
            Manuscript::where('library_id', $libId)
                ->whereNull('cataloger_id')
                ->whereNotNull('metadata->catalog_citation')
                ->select(['id', 'metadata'])
                ->chunkById(1000, function ($chunk) use (&$linked, $rules, $dryRun) {
                    $grouped = [];
                    foreach ($chunk as $ms) {
                        $citation = $ms->metadata['catalog_citation'] ?? '';
                        $vNum = null;
                        if (preg_match('/(?:ف|فهرست)[:\s]+(\d+)\s*[-ـ]/u', $citation, $m)) {
                            $vNum = (int) $m[1];
                        } elseif (count($rules) === 1) {
                            $vNum = array_key_first($rules);
                        }

                        if ($vNum && isset($rules[$vNum])) {
                            $target = $rules[$vNum];
                            $key = "{$target['vol_id']}_{$target['cat_id']}";
                            $grouped[$key]['vol'] = $target['vol_id'];
                            $grouped[$key]['cat'] = $target['cat_id'];
                            $grouped[$key]['ids'][] = $ms->id;
                            $linked++;
                        }
                    }

                    if (!$dryRun) {
                        foreach ($grouped as $g) {
                            DB::table('manuscripts')->whereIn('id', $g['ids'])->update([
                                'catalog_volume_id' => $g['vol'],
                                'cataloger_id' => $g['cat'],
                            ]);
                        }
                    }
                });
        }

        $this->stats['تطبیق عمومی مجلدات کتابخانه‌ها با ارجاعات فاصله‌دار'] = $linked;
        $this->info("   -> Linked {$linked} manuscripts.");
    }

    /**
     * Recalculate manuscripts counts and volumes counts for catalogers and volumes
     */
    protected function recalculateCatalogersAndVolumes(): void
    {
        $this->info("Recalculating manuscripts count and volumes count for all catalogers...");

        foreach (CatalogVolume::all() as $vol) {
            $count = DB::table('manuscripts')->where('catalog_volume_id', $vol->id)->count();
            $vol->update(['manuscripts_count' => $count]);
        }

        foreach (Cataloger::all() as $cat) {
            // Count manuscripts directly authored or via catalog volumes
            $mssCount = DB::table('manuscripts')
                ->where(function ($q) use ($cat) {
                    $q->where('cataloger_id', $cat->id)
                      ->orWhereIn('catalog_volume_id', function ($sq) use ($cat) {
                          $sq->select('catalog_volume_id')
                             ->from('catalog_volume_cataloger')
                             ->where('cataloger_id', $cat->id);
                      });
                })
                ->count();

            $vCount = $cat->catalogVolumes()->count();
            $cat->update([
                'manuscripts_count' => $mssCount,
                'volumes_count' => $vCount,
            ]);
        }

        $this->info("Recalculation completed successfully.");
    }
}
