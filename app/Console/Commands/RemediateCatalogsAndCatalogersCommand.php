<?php

namespace App\Console\Commands;

use App\Models\Catalog;
use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class RemediateCatalogsAndCatalogersCommand extends Command
{
    protected $signature = 'catalogs:remediate {--dry-run : Run without committing changes}';
    protected $description = 'Remediate catalogers, catalog volumes, and link manuscripts using verified master dataset';

    public function handle()
    {
        $jsonPath = database_path('data/fankha_catalogs_master_verified.json');
        if (!File::exists($jsonPath)) {
            $jsonPath = storage_path('app/fankha_catalogs_master_verified.json');
        }
        if (!File::exists($jsonPath)) {
            $this->error("Verified master JSON not found at database/data or storage/app.");
            return 1;
        }

        $dryRun = $this->option('dry-run');
        if ($dryRun) {
            $this->warn("=== DRY-RUN MODE: No changes will be saved to database ===");
        }

        $masterData = json_decode(File::get($jsonPath), true);
        $this->info("Loaded " . count($masterData) . " verified catalog volume entries.");

        // -------------------------------------------------------------
        // STEP 1: Normalize and Ensure Catalogers
        // -------------------------------------------------------------
        $this->info("Step 1: Normalizing cataloger records...");
        $catalogerMap = $this->prepareCatalogers($dryRun);

        // -------------------------------------------------------------
        // STEP 2: Upsert Catalog Volumes & Pivot
        // -------------------------------------------------------------
        $this->info("Step 2: Upserting catalog volumes and linking catalogers...");
        $volumeMap = $this->upsertCatalogVolumes($masterData, $catalogerMap, $dryRun);

        // -------------------------------------------------------------
        // STEP 3: Link Manuscripts (Master Library Series)
        // -------------------------------------------------------------
        $this->info("Step 3: Linking manuscripts to catalogers and volumes...");
        $this->linkManuscripts($masterData, $volumeMap, $dryRun);

        // -------------------------------------------------------------
        // STEP 4: Remediate Periodicals & City Catalogs (Phase 2 - 14 Scholars)
        // -------------------------------------------------------------
        $this->info("Step 4: Linking periodicals and city catalogs for key scholars...");
        $this->remediatePeriodicalsAndCityScholars($catalogerMap, $dryRun);

        // -------------------------------------------------------------
        // STEP 5: Purge Foreign & Invalid Zero-Manuscript Catalogers
        // -------------------------------------------------------------
        $this->info("Step 5: Purging zero-manuscript foreign & invalid catalogers...");
        $this->purgeInvalidAndForeignCatalogers($dryRun);

        // -------------------------------------------------------------
        // STEP 6: Recalculate Manuscript & Volume Counts
        // -------------------------------------------------------------
        if (!$dryRun) {
            $this->info("Step 6: Recalculating volumes and manuscripts counts...");
            $this->recalculateCounts();
        }

        $this->info("Remediation completed successfully!");
        return 0;
    }

    protected function prepareCatalogers(bool $dryRun): array
    {
        // 1. Name normalization map for existing records
        $renames = [
            'علینقی منزوی تهرانی' => 'علینقی منزوی',
            'محقق طباطبائی' => 'سید عبدالعزیز طباطبائی',
            'علامه سید محمدعلی روضاتی' => 'سید محمدعلی روضاتی',
            'محمود یزدی مطلق (فاضل)' => 'محمود فاضل',
            'یوسف اعتصام الملک' => 'یوسف اعتصامی',
            'محمد باقر حجتی' => 'محمدباقر حجتی',
            'محمدتقی دانش پژوه' => 'محمدتقی دانش‌پژوه',
            'بهاء الدین علمی انواری' => 'بهاءالدین علمی انواری',
            'میر ودود سید یونسی' => 'میرودود سید یونسی',
            'محمد علی حائری' => 'محمدعلی حائری',
            'محمد صادق فقیر' => 'محمدصادق فقیری',
            'محمدصادق فقیر' => 'محمدصادق فقیری',
            'سید محمود مرعشی نجفی' => 'سید محمود مرعشی',
            'سید محمدحسین حکیم' => 'محمدحسین حکیم',
        ];

        foreach ($renames as $oldName => $newName) {
            $c = Cataloger::where('name', $oldName)->first();
            if ($c) {
                $this->line("Renaming cataloger: [{$oldName}] -> [{$newName}]");
                if (!$dryRun) {
                    $c->update(['name' => $newName]);
                }
            }
        }

        // 2. Remove invalid entries (like Rumi)
        $invalid = Cataloger::where('name', 'مولانا جلال الدین محمد بلخی رومی')->first();
        if ($invalid) {
            $this->line("Removing non-cataloger: {$invalid->name}");
            if (!$dryRun) {
                $invalid->delete();
            }
        }

        // 3. Known years for specific catalogers if not set
        $knownYears = [
            'علینقی منزوی' => ['birth' => 1302, 'death' => 1389],
            'محمدتقی دانش‌پژوه' => ['birth' => 1290, 'death' => 1375],
            'سید احمد حسینی اشکوری' => ['birth' => 1310, 'death' => null],
            'سید جعفر حسینی اشکوری' => ['birth' => 1344, 'death' => null],
            'عبدالحسین حائری' => ['birth' => 1306, 'death' => 1394],
            'احمد منزوی' => ['birth' => 1304, 'death' => 1394],
            'علی صدرائی خوئی' => ['birth' => 1342, 'death' => null],
            'ابوالفضل حافظیان بابلی' => ['birth' => 1344, 'death' => null],
            'سید محمدعلی روضاتی' => ['birth' => 1308, 'death' => 1391],
            'عبدالله انوار' => ['birth' => 1303, 'death' => 1401],
            'محمدباقر حجتی' => ['birth' => 1311, 'death' => null],
            'محمود فاضل' => ['birth' => 1312, 'death' => 1400],
            'محمود طیار مراغی' => ['birth' => 1348, 'death' => null],
            'محمد شیروانی' => ['birth' => 1308, 'death' => 1370],
            'براتعلی غلامی مقدم' => ['birth' => 1335, 'death' => null],
            'سید عبدالعزیز طباطبائی' => ['birth' => 1308, 'death' => 1374],
            'یوسف اعتصامی' => ['birth' => 1253, 'death' => 1316],
            'ابن یوسف شیرازی' => ['birth' => 1261, 'death' => 1330],
            'سعید نفیسی' => ['birth' => 1274, 'death' => 1345],
            'ایرج افشار' => ['birth' => 1304, 'death' => 1389],
            'رضا استادی' => ['birth' => 1316, 'death' => null],
            'سید صادق حسینی اشکوری' => ['birth' => 1349, 'death' => null],
            'حبیب‌الله عظیمی' => ['birth' => 1338, 'death' => null],
            'احمد گلچین معانی' => ['birth' => 1295, 'death' => 1379],
            'فخری راستکار' => ['birth' => 1298, 'death' => 1374],
            'محمد وفادار مرادی' => ['birth' => 1340, 'death' => null],
            'محمد آصف فکرت' => ['birth' => 1325, 'death' => 1401],
            'غلامعلی عرفانیان' => ['birth' => 1315, 'death' => null],
            'محمد نخجوانی' => ['birth' => 1260, 'death' => 1341],
            'میرودود سید یونسی' => ['birth' => 1298, 'death' => 1378],
            'محمدحسین حکیم' => ['birth' => 1354, 'death' => null],
            'مصطفی درایتی' => ['birth' => 1337, 'death' => null],
        ];

        // 4. Fetch all existing and create any missing from verified dataset
        $catalogerMap = [];
        foreach (Cataloger::all() as $cat) {
            $norm = $this->normalizeName($cat->name);
            $catalogerMap[$norm] = $cat;
            $catalogerMap[$cat->name] = $cat;

            if (!$dryRun && isset($knownYears[$cat->name])) {
                $y = $knownYears[$cat->name];
                $updates = [];
                if (empty($cat->birth_year_solar) && $y['birth']) $updates['birth_year_solar'] = $y['birth'];
                if (empty($cat->death_year_solar) && $y['death']) $updates['death_year_solar'] = $y['death'];
                if (!empty($updates)) {
                    $cat->update($updates);
                }
            }
        }

        return $catalogerMap;
    }

    protected function getOrCreateCataloger(string $name, array &$catalogerMap, bool $dryRun): ?Cataloger
    {
        $name = trim($name);
        if (empty($name)) return null;

        $norm = $this->normalizeName($name);
        if (isset($catalogerMap[$norm])) {
            return $catalogerMap[$norm];
        }
        if (isset($catalogerMap[$name])) {
            return $catalogerMap[$name];
        }

        $cat = Cataloger::where('name', $name)->first();
        if ($cat) {
            $catalogerMap[$norm] = $cat;
            $catalogerMap[$name] = $cat;
            return $cat;
        }

        $this->line("Creating new cataloger: [{$name}]");
        if ($dryRun) {
            return null;
        }

        $slug = Str::slug($name, '-', null);
        if (empty($slug)) {
            $slug = 'cataloger-' . Str::random(6);
        }
        $existingCount = Cataloger::where('slug', $slug)->count();
        if ($existingCount > 0) {
            $slug .= '-' . ($existingCount + 1);
        }

        $newCat = Cataloger::create([
            'name' => $name,
            'slug' => $slug,
            'manuscripts_count' => 0,
            'volumes_count' => 0,
        ]);

        $catalogerMap[$norm] = $newCat;
        $catalogerMap[$name] = $newCat;
        return $newCat;
    }

    protected function upsertCatalogVolumes(array $masterData, array &$catalogerMap, bool $dryRun): array
    {
        $volumeMap = []; // id in json -> CatalogVolume model

        foreach ($masterData as $item) {
            $libId = $item['library_db_id'];
            $volNum = is_numeric($item['volume_number']) ? (int)$item['volume_number'] : null;
            $title = $item['volume_title'] ? "{$item['catalog_series']} - {$item['volume_title']}" : $item['catalog_series'];
            $citationPattern = $item['citation_pattern'];

            if ($dryRun) {
                continue;
            }

            // Disambiguated Lookup
            $query = CatalogVolume::query();
            if ($libId) {
                $query->where('library_id', $libId);
            }

            if ($citationPattern && str_contains($citationPattern, 'فیلم')) {
                $query->where('title', 'like', '%فیلم%');
            } elseif ($citationPattern && str_contains($citationPattern, 'سنا')) {
                $query->where('title', 'like', '%سنا%');
            } elseif ($citationPattern && str_contains($citationPattern, 'اهدائی')) {
                $query->where('title', 'like', '%اهدائی%');
            } elseif ($citationPattern && str_contains($citationPattern, 'ارموی')) {
                $query->where('title', 'like', '%ارموی%');
            } elseif ($citationPattern && str_contains($citationPattern, 'عکسی')) {
                $query->where('title', 'like', '%عکسی%');
            } elseif ($citationPattern && str_contains($citationPattern, 'الفبائی')) {
                $query->where('title', 'like', '%الفبائی%');
            } elseif ($volNum !== null) {
                $query->where('volume_number', $volNum);
                if (str_contains($item['catalog_series'], 'مشکوه')) {
                    $query->where('title', 'like', '%مشکوه%');
                }
            } else {
                $query->where('title', 'like', "%{$item['volume_title']}%");
            }

            $vol = $query->first();
            $pages = is_numeric($item['pages']) ? (int)$item['pages'] : null;

            if (!$vol) {
                $vol = CatalogVolume::create([
                    'title' => $title,
                    'volume_number' => $volNum,
                    'library_id' => $libId,
                    'catalog_id' => 1,
                    'publisher' => $item['publisher'] ?: null,
                    'publication_year' => $item['year'] ?: null,
                    'pages_count' => $pages,
                    'manuscripts_range' => $item['manuscript_range'] ?: null,
                    'citation_pattern' => $citationPattern,
                    'metadata' => ['series' => $item['catalog_series'], 'notes' => $item['notes']],
                ]);
            } else {
                $vol->update([
                    'title' => $title,
                    'publisher' => $item['publisher'] ?: $vol->publisher,
                    'publication_year' => $item['year'] ?: $vol->publication_year,
                    'pages_count' => $pages ?: $vol->pages_count,
                    'manuscripts_range' => $item['manuscript_range'] ?: $vol->manuscripts_range,
                    'citation_pattern' => $citationPattern ?: $vol->citation_pattern,
                ]);
            }

            // Sync Catalogers & Supervisors
            $syncData = [];
            foreach ($item['catalogers'] as $cName) {
                $catModel = $this->getOrCreateCataloger($cName, $catalogerMap, $dryRun);
                if ($catModel) {
                    $syncData[$catModel->id] = ['role' => 'cataloger'];
                }
            }
            foreach ($item['supervisors'] as $sName) {
                $cleanS = trim(explode('(', $sName)[0]);
                $catModel = $this->getOrCreateCataloger($cleanS, $catalogerMap, $dryRun);
                if ($catModel && !isset($syncData[$catModel->id])) {
                    $syncData[$catModel->id] = ['role' => 'supervisor'];
                }
            }

            if (!empty($syncData)) {
                $vol->catalogers()->sync($syncData);
            }

            $volumeMap[$item['id']] = $vol;
        }

        $this->info("Processed " . count($masterData) . " catalog volumes.");
        return $volumeMap;
    }

    protected function linkManuscripts(array $masterData, array $volumeMap, bool $dryRun)
    {
        // Build library-specific rules strictly from verified master dataset!
        $rulesByLibrary = [];

        foreach ($masterData as $item) {
            $libId = $item['library_db_id'];
            if (!$libId) continue;

            $volModel = $volumeMap[$item['id']] ?? null;
            if (!$volModel && !$dryRun) continue;

            $volId = $volModel?->id;
            
            // Primary cataloger is the FIRST cataloger specified in verified JSON
            $firstCatName = $item['catalogers'][0] ?? null;
            $catModel = $firstCatName ? Cataloger::where('name', $firstCatName)->first() : null;
            $catId = $catModel?->id;

            $cp = $item['citation_pattern'];
            $volNum = is_numeric($item['volume_number']) ? (int)$item['volume_number'] : null;

            if ($cp && (str_contains($cp, 'سنا') || str_contains($cp, 'فیلم') || str_contains($cp, 'اهدائی') || str_contains($cp, 'ارموی') || str_contains($cp, 'عکسی') || str_contains($cp, 'الفبائی') || str_contains($cp, 'مختصر') || str_contains($cp, 'نشریه'))) {
                $rulesByLibrary[$libId]['special'][$cp] = [
                    'volume_id' => $volId,
                    'cataloger_id' => $catId,
                ];
            } elseif ($volNum !== null) {
                // If library has multiple series with integer vol numbers (like Meshkat vs UT general)
                $rulesByLibrary[$libId]['numbered'][$volNum] = [
                    'volume_id' => $volId,
                    'cataloger_id' => $catId,
                ];
            }
        }

        $totalLinked = 0;

        foreach ($rulesByLibrary as $libId => $rules) {
            $lib = Library::find($libId);
            $libName = $lib ? $lib->name : "Library {$libId}";
            $mssCount = Manuscript::where('library_id', $libId)->count();
            if ($mssCount === 0) continue;

            $this->info("Processing {$libName} (Library ID: {$libId}) - Total MSS: {$mssCount}...");

            $specialRules = $rules['special'] ?? [];
            $numberedRules = $rules['numbered'] ?? [];

            // Sort special prefixes by length descending
            uksort($specialRules, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));

            $chunkUpdated = 0;
            Manuscript::where('library_id', $libId)
                ->select(['id', 'metadata', 'library_id', 'cataloger_id', 'catalog_volume_id'])
                ->chunkById(1000, function ($chunk) use (&$chunkUpdated, $specialRules, $numberedRules, $dryRun) {
                    $updates = [];

                    foreach ($chunk as $ms) {
                        $citation = $ms->metadata['catalog_citation'] ?? '';
                        if (empty($citation)) continue;

                        $matchedVolId = null;
                        $matchedCatId = null;

                        // 1. Check special prefixes first
                        foreach ($specialRules as $prefix => $target) {
                            if (str_contains($citation, $prefix)) {
                                $matchedVolId = $target['volume_id'];
                                $matchedCatId = $target['cataloger_id'];
                                break;
                            }
                        }

                        // 2. Exact integer volume match: [ف: X-...] or [فهرست: X-...]
                        if (!$matchedVolId) {
                            if (preg_match('/(?:ف|فهرست):\s*(\d+)/u', $citation, $m)) {
                                $vNum = (int)$m[1];
                                if (isset($numberedRules[$vNum])) {
                                    $matchedVolId = $numberedRules[$vNum]['volume_id'];
                                    $matchedCatId = $numberedRules[$vNum]['cataloger_id'];
                                }
                            }
                        }

                        if ($matchedVolId && ($ms->catalog_volume_id != $matchedVolId || $ms->cataloger_id != $matchedCatId)) {
                            $updates[] = [
                                'id' => $ms->id,
                                'catalog_volume_id' => $matchedVolId,
                                'cataloger_id' => $matchedCatId,
                            ];
                        }
                    }

                    if (!empty($updates) && !$dryRun) {
                        $groupedUpdates = [];
                        foreach ($updates as $up) {
                            $key = "{$up['catalog_volume_id']}_{$up['cataloger_id']}";
                            $groupedUpdates[$key]['vol'] = $up['catalog_volume_id'];
                            $groupedUpdates[$key]['cat'] = $up['cataloger_id'];
                            $groupedUpdates[$key]['ids'][] = $up['id'];
                        }

                        foreach ($groupedUpdates as $g) {
                            DB::table('manuscripts')
                                ->whereIn('id', $g['ids'])
                                ->update([
                                    'catalog_volume_id' => $g['vol'],
                                    'cataloger_id' => $g['cat'],
                                ]);
                        }
                    }
                    $chunkUpdated += count($updates);
                });

            $this->info("Updated {$chunkUpdated} manuscripts for {$libName}.");
            $totalLinked += $chunkUpdated;
        }

        $this->info("Total manuscripts linked in this run: {$totalLinked}");
    }

    protected function remediatePeriodicalsAndCityScholars(array &$catalogerMap, bool $dryRun)
    {
        // 1. پرویز اذکائی و جواد مقصود همدانی (همدان: مدرسه غرب و مجموعه‌های خصوصی)
        $azkaei = $this->getOrCreateCataloger('پرویز اذکائی', $catalogerMap, $dryRun);
        $maqsood = $this->getOrCreateCataloger('جواد مقصود همدانی', $catalogerMap, $dryRun);
        if ($azkaei && $maqsood) {
            $volGharb = CatalogVolume::firstOrCreate(
                ['title' => 'فهرست نسخه‌های خطی کتابخانه مدرسه غرب همدان (آخوند)'],
                [
                    'library_id' => 29,
                    'catalog_id' => 1,
                    'volume_number' => 1,
                    'publisher' => 'دانشگاه بوعلی سینا / مجمع ذخائر اسلامی',
                    'publication_year' => 1374,
                    'citation_pattern' => 'مدرسه غرب',
                    'metadata' => ['series' => 'همدان', 'city' => 'همدان'],
                ]
            );
            if (!$dryRun) {
                $volGharb->catalogers()->syncWithoutDetaching([
                    $azkaei->id => ['role' => 'cataloger'],
                    $maqsood->id => ['role' => 'cataloger'],
                ]);
                $c = DB::table('manuscripts')->whereIn('library_id', [29, 201, 901])->update([
                    'catalog_volume_id' => $volGharb->id,
                    'cataloger_id' => $azkaei->id,
                ]);
                $this->info("Linked {$c} manuscripts of Hamedan to Azkaei & Maqsood.");
            }
        }

        // 2. محمد برکت (شیراز: کتابخانه علامه طباطبائی پزشکی)
        $barakat = $this->getOrCreateCataloger('محمد برکت', $catalogerMap, $dryRun);
        if ($barakat) {
            $volBarakat = CatalogVolume::firstOrCreate(
                ['title' => 'فهرست نسخه‌های خطی کتابخانه علامه طباطبائی شیراز (پزشکی)'],
                [
                    'library_id' => 64,
                    'catalog_id' => 1,
                    'volume_number' => 1,
                    'publisher' => 'خانه پژوهش (نسخه‌پژوهی)',
                    'publication_year' => 1383,
                    'citation_pattern' => 'نسخه‌پژوهی',
                    'metadata' => ['series' => 'نسخه‌پژوهی', 'city' => 'شیراز'],
                ]
            );
            if (!$dryRun) {
                $volBarakat->catalogers()->syncWithoutDetaching([
                    $barakat->id => ['role' => 'cataloger'],
                ]);
                $c = DB::table('manuscripts')->whereIn('library_id', [64, 885])->update([
                    'catalog_volume_id' => $volBarakat->id,
                    'cataloger_id' => $barakat->id,
                ]);
                $this->info("Linked {$c} manuscripts of Shiraz Pezeshki to Mohammad Barakat.");
            }
        }

        // 3. تقی بینش (مشهد: فرهنگ و هنر، مدرسه نواب)
        $binesh = $this->getOrCreateCataloger('تقی بینش', $catalogerMap, $dryRun);
        if ($binesh) {
            $volFarhang = CatalogVolume::firstOrCreate(
                ['title' => 'فهرست نسخه‌های خطی کتابخانه فرهنگ و هنر مشهد'],
                [
                    'library_id' => 75,
                    'catalog_id' => 1,
                    'volume_number' => 1,
                    'publisher' => 'فرهنگ ایران زمین',
                    'publication_year' => 1344,
                    'citation_pattern' => 'فرهنگ و هنر',
                    'metadata' => ['series' => 'مشهد', 'city' => 'مشهد'],
                ]
            );
            $volNavab = CatalogVolume::firstOrCreate(
                ['title' => 'فهرست نسخه‌های خطی کتابخانه مدرسه نواب مشهد'],
                [
                    'library_id' => 13,
                    'catalog_id' => 1,
                    'volume_number' => 1,
                    'publisher' => 'فرهنگ ایران زمین',
                    'publication_year' => 1344,
                    'citation_pattern' => 'نواب',
                    'metadata' => ['series' => 'مشهد', 'city' => 'مشهد'],
                ]
            );
            if (!$dryRun) {
                $volFarhang->catalogers()->syncWithoutDetaching([$binesh->id => ['role' => 'cataloger']]);
                $volNavab->catalogers()->syncWithoutDetaching([$binesh->id => ['role' => 'cataloger']]);
                $c1 = DB::table('manuscripts')->whereIn('library_id', [75, 990])->update([
                    'catalog_volume_id' => $volFarhang->id,
                    'cataloger_id' => $binesh->id,
                ]);
                $c2 = DB::table('manuscripts')->whereIn('library_id', [13, 878])->update([
                    'catalog_volume_id' => $volNavab->id,
                    'cataloger_id' => $binesh->id,
                ]);
                $this->info("Linked " . ($c1 + $c2) . " manuscripts of Farhang & Navab Mashhad to Taqi Binesh.");
            }
        }

        // 4. صادق حضرتی (زنجان: مدرسه امام جمعه)
        $hazrati = $this->getOrCreateCataloger('صادق حضرتی', $catalogerMap, $dryRun);
        if ($hazrati) {
            $volHazrati = CatalogVolume::firstOrCreate(
                ['title' => 'فهرست نسخه‌های خطی کتابخانه مدرسه امام جمعه زنجان'],
                [
                    'library_id' => 66,
                    'catalog_id' => 1,
                    'volume_number' => 1,
                    'publisher' => 'مجمع ذخائر اسلامی',
                    'publication_year' => 1384,
                    'citation_pattern' => 'امام جمعه',
                    'metadata' => ['series' => 'زنجان', 'city' => 'زنجان'],
                ]
            );
            if (!$dryRun) {
                $volHazrati->catalogers()->syncWithoutDetaching([$hazrati->id => ['role' => 'cataloger']]);
                $c = DB::table('manuscripts')->where('library_id', 66)->update([
                    'catalog_volume_id' => $volHazrati->id,
                    'cataloger_id' => $hazrati->id,
                ]);
                $this->info("Linked {$c} manuscripts of Emam Jomeh Zanjan to Sadeq Hazrati.");
            }
        }

        // 5. سید عبدالعزیز طباطبائی (تبریز: ثقة الاسلام و قاضی طباطبائی در نشریه دانشگاه تهران)
        $tabatabaei = $this->getOrCreateCataloger('سید عبدالعزیز طباطبائی', $catalogerMap, $dryRun);
        if ($tabatabaei) {
            $volSeqat = CatalogVolume::firstOrCreate(
                ['title' => 'فهرست نسخه‌های خطی کتابخانه ثقة الاسلام تبریز (نشریه دانشگاه تهران ۴)'],
                [
                    'library_id' => 119,
                    'catalog_id' => 1,
                    'volume_number' => 4,
                    'publisher' => 'انتشارات دانشگاه تهران',
                    'publication_year' => 1344,
                    'citation_pattern' => 'نشریه: 4',
                    'metadata' => ['series' => 'نشریه کتابخانه مرکزی دانشگاه تهران', 'city' => 'تبریز'],
                ]
            );
            $volGhazi = CatalogVolume::firstOrCreate(
                ['title' => 'فهرست نسخه‌های خطی کتابخانه محمدعلی قاضی طباطبائی تبریز (نشریه دانشگاه تهران ۷)'],
                [
                    'library_id' => 127,
                    'catalog_id' => 1,
                    'volume_number' => 7,
                    'publisher' => 'انتشارات دانشگاه تهران',
                    'publication_year' => 1353,
                    'citation_pattern' => 'نشریه: 7',
                    'metadata' => ['series' => 'نشریه کتابخانه مرکزی دانشگاه تهران', 'city' => 'تبریز'],
                ]
            );
            if (!$dryRun) {
                $volSeqat->catalogers()->syncWithoutDetaching([$tabatabaei->id => ['role' => 'cataloger']]);
                $volGhazi->catalogers()->syncWithoutDetaching([$tabatabaei->id => ['role' => 'cataloger']]);
                $c1 = DB::table('manuscripts')->where('library_id', 119)->update([
                    'catalog_volume_id' => $volSeqat->id,
                    'cataloger_id' => $tabatabaei->id,
                ]);
                $c2 = DB::table('manuscripts')->whereIn('library_id', [127, 330])->update([
                    'catalog_volume_id' => $volGhazi->id,
                    'cataloger_id' => $tabatabaei->id,
                ]);
                $this->info("Linked " . ($c1 + $c2) . " manuscripts of Tabriz (Seqat & Ghazi) to Seyyed Abdulaziz Tabatabaei.");
            }
        }

        // 6. سید محمدعلی روضاتی (اصفهان: نفایس مخطوطات و دو هزار نسخه)
        $rowzati = $this->getOrCreateCataloger('سید محمدعلی روضاتی', $catalogerMap, $dryRun);
        if ($rowzati) {
            $volRowzati = CatalogVolume::firstOrCreate(
                ['title' => 'فهرست کتب خطی کتابخانه‌های اصفهان (نفایس مخطوطات)'],
                [
                    'library_id' => 35,
                    'catalog_id' => 1,
                    'volume_number' => 1,
                    'publisher' => 'نشر نفایس مخطوطات اصفهان',
                    'publication_year' => 1340,
                    'citation_pattern' => 'نفایس مخطوطات',
                    'metadata' => ['series' => 'اصفهان', 'city' => 'اصفهان'],
                ]
            );
            if (!$dryRun) {
                $volRowzati->catalogers()->syncWithoutDetaching([$rowzati->id => ['role' => 'cataloger']]);
                $c = DB::table('manuscripts')->whereIn('library_id', [35, 100, 105, 175, 283])->update([
                    'catalog_volume_id' => $volRowzati->id,
                    'cataloger_id' => $rowzati->id,
                ]);
                $this->info("Linked {$c} manuscripts of Isfahan to Seyyed Mohammad Ali Rowzati.");
            }
        }

        // 7. سید حسین مدرسی طباطبائی (قم: آشنایی با چند نسخه خطی)
        $modarresi = $this->getOrCreateCataloger('سید حسین مدرسی طباطبائی', $catalogerMap, $dryRun);
        if ($modarresi) {
            $volModarresi = CatalogVolume::firstOrCreate(
                ['title' => 'آشنایی با چند نسخه خطی (مدارس و کتابخانه‌های قم)'],
                [
                    'library_id' => 95,
                    'catalog_id' => 1,
                    'volume_number' => 1,
                    'publisher' => 'قم',
                    'publication_year' => 1396,
                    'citation_pattern' => 'آشنایی با چند نسخه خطی',
                    'metadata' => ['series' => 'قم', 'city' => 'قم'],
                ]
            );
            if (!$dryRun) {
                $volModarresi->catalogers()->syncWithoutDetaching([$modarresi->id => ['role' => 'cataloger']]);
                $c = DB::table('manuscripts')->whereIn('library_id', [95, 131, 140, 282, 1011])->update([
                    'catalog_volume_id' => $volModarresi->id,
                    'cataloger_id' => $modarresi->id,
                ]);
                $this->info("Linked {$c} manuscripts of Qom libraries to Seyyed Hossein Modarresi Tabatabaei.");
            }
        }

        // 8. ابراهیم دیباجی (تهران: کتابخانه نوربخش خانقاه نعمت‌اللهی ج ۱ و ۲)
        $dibaji = $this->getOrCreateCataloger('ابراهیم دیباجی', $catalogerMap, $dryRun);
        if ($dibaji) {
            $volNurbakhsh1 = CatalogVolume::firstOrCreate(
                ['title' => 'فهرست نسخه‌های خطی کتابخانه نوربخش (خانقاه نعمت‌اللهی) - جلد اول'],
                [
                    'library_id' => 18,
                    'catalog_id' => 1,
                    'volume_number' => 1,
                    'publisher' => 'انتشارات خانقاه نعمت‌اللهی',
                    'publication_year' => 1352,
                    'citation_pattern' => 'ف: 1',
                    'metadata' => ['series' => 'نوربخش', 'city' => 'تهران'],
                ]
            );
            $volNurbakhsh2 = CatalogVolume::firstOrCreate(
                ['title' => 'فهرست نسخه‌های خطی کتابخانه نوربخش (خانقاه نعمت‌اللهی) - جلد دوم'],
                [
                    'library_id' => 18,
                    'catalog_id' => 1,
                    'volume_number' => 2,
                    'publisher' => 'انتشارات خانقاه نعمت‌اللهی',
                    'publication_year' => 1354,
                    'citation_pattern' => 'ف: 2',
                    'metadata' => ['series' => 'نوربخش', 'city' => 'تهران'],
                ]
            );
            if (!$dryRun) {
                $volNurbakhsh1->catalogers()->syncWithoutDetaching([$dibaji->id => ['role' => 'cataloger']]);
                $volNurbakhsh2->catalogers()->syncWithoutDetaching([$dibaji->id => ['role' => 'cataloger']]);

                $c1 = DB::table('manuscripts')
                    ->where('library_id', 18)
                    ->where('metadata->catalog_citation', 'like', '%[ف: 1-%')
                    ->update(['catalog_volume_id' => $volNurbakhsh1->id, 'cataloger_id' => $dibaji->id]);

                $c2 = DB::table('manuscripts')
                    ->where('library_id', 18)
                    ->where('metadata->catalog_citation', 'like', '%[ف: 2-%')
                    ->update(['catalog_volume_id' => $volNurbakhsh2->id, 'cataloger_id' => $dibaji->id]);

                // Fallback for remaining manuscripts in library 18
                $c3 = DB::table('manuscripts')
                    ->where('library_id', 18)
                    ->whereNull('cataloger_id')
                    ->update(['catalog_volume_id' => $volNurbakhsh1->id, 'cataloger_id' => $dibaji->id]);

                $this->info("Linked " . ($c1 + $c2 + $c3) . " manuscripts of Nurbakhsh to Ibrahim Dibaji.");
            }
        }

        // 9. محمدصادق فقیری (یزد: وزیری یزد جلد ۴ و ۵)
        $faqiri = $this->getOrCreateCataloger('محمدصادق فقیری', $catalogerMap, $dryRun);
        if ($faqiri) {
            $v4 = CatalogVolume::where('library_id', 32)->where('volume_number', 4)->first();
            $v5 = CatalogVolume::where('library_id', 32)->where('volume_number', 5)->first();
            if ($v4) {
                $v4->catalogers()->sync([$faqiri->id => ['role' => 'cataloger']]);
            }
            if ($v5) {
                $v5->catalogers()->sync([$faqiri->id => ['role' => 'cataloger']]);
            }
            if (!$dryRun && $v4 && $v5) {
                $c = DB::table('manuscripts')
                    ->where('library_id', 32)
                    ->whereIn('catalog_volume_id', [$v4->id, $v5->id])
                    ->update(['cataloger_id' => $faqiri->id]);
                $this->info("Linked {$c} manuscripts of Vaziri Yazd (Vols 4 & 5) to Mohammad Sadeq Faqiri.");
            }
        }

        // 10. محمد آصف فکرت (مشهد: فهرست الفبایی رضوی)
        $fekrat = $this->getOrCreateCataloger('محمد آصف فکرت', $catalogerMap, $dryRun);
        if ($fekrat) {
            $volFekrat = CatalogVolume::firstOrCreate(
                ['title' => 'فهرست الفبایی کتب خطی کتابخانه آستان قدس رضوی'],
                [
                    'library_id' => 1,
                    'catalog_id' => 1,
                    'volume_number' => 1,
                    'publisher' => 'آستان قدس رضوی',
                    'publication_year' => 1369,
                    'citation_pattern' => 'الفبائی',
                    'metadata' => ['series' => 'رضوی', 'city' => 'مشهد'],
                ]
            );
            if (!$dryRun) {
                $volFekrat->catalogers()->syncWithoutDetaching([$fekrat->id => ['role' => 'cataloger']]);
                $c = DB::table('manuscripts')
                    ->where('library_id', 1)
                    ->where(function($q) {
                        $q->where('metadata->catalog_citation', 'like', '%الفبائی%')
                          ->orWhere('metadata->catalog_citation', 'like', '%الفبایی%');
                    })
                    ->update(['catalog_volume_id' => $volFekrat->id, 'cataloger_id' => $fekrat->id]);
                $this->info("Linked {$c} manuscripts of Astan Quds Alphabetical to Mohammad Asef Fekrat.");
            }
        }

        // 11. یوسف بیگ باباپور (مراغه و عجب‌شیر)
        $babapour = $this->getOrCreateCataloger('یوسف بیگ باباپور', $catalogerMap, $dryRun);
        if ($babapour) {
            $volMaragheh = CatalogVolume::firstOrCreate(
                ['title' => 'فهرست نسخه‌های خطی مراغه و عجب‌شیر'],
                [
                    'library_id' => 347,
                    'catalog_id' => 1,
                    'volume_number' => 1,
                    'publisher' => 'مجمع ذخائر اسلامی',
                    'publication_year' => 1386,
                    'citation_pattern' => 'مراغه',
                    'metadata' => ['series' => 'مراغه', 'city' => 'مراغه'],
                ]
            );
            if (!$dryRun) {
                $volMaragheh->catalogers()->syncWithoutDetaching([$babapour->id => ['role' => 'cataloger']]);
                $c = DB::table('manuscripts')->whereIn('library_id', [347, 899, 1009])->update([
                    'catalog_volume_id' => $volMaragheh->id,
                    'cataloger_id' => $babapour->id,
                ]);
                $this->info("Linked {$c} manuscripts of Maragheh to Yousef Beig Babapour.");
            }
        }

        // 12. توفیق هاشم‌پور سبحانی (قزوین: امام صادق)
        $sobhani = $this->getOrCreateCataloger('توفیق هاشم‌پور سبحانی', $catalogerMap, $dryRun);
        if ($sobhani) {
            $volQazvin = CatalogVolume::firstOrCreate(
                ['title' => 'فهرست نسخه‌های خطی مدرسه امام صادق قزوین'],
                [
                    'library_id' => 54,
                    'catalog_id' => 1,
                    'volume_number' => 1,
                    'publisher' => 'قزوین',
                    'publication_year' => 1353,
                    'citation_pattern' => 'امام صادق',
                    'metadata' => ['series' => 'قزوین', 'city' => 'قزوین'],
                ]
            );
            if (!$dryRun) {
                $volQazvin->catalogers()->syncWithoutDetaching([$sobhani->id => ['role' => 'cataloger']]);
                $c = DB::table('manuscripts')->whereIn('library_id', [54, 319])->update([
                    'catalog_volume_id' => $volQazvin->id,
                    'cataloger_id' => $sobhani->id,
                ]);
                $this->info("Linked {$c} manuscripts of Qazvin to Towfiq Hashempour Sobhani.");
            }
        }

        // 13. رحیم قاسمی (اصفهان: سید احمد روضاتی)
        $qasemi = $this->getOrCreateCataloger('رحیم قاسمی', $catalogerMap, $dryRun);
        if ($qasemi) {
            $volAhmadRowzati = CatalogVolume::firstOrCreate(
                ['title' => 'فهرست نسخه‌های خطی کتابخانه سید احمد روضاتی'],
                [
                    'library_id' => 292,
                    'catalog_id' => 1,
                    'volume_number' => 1,
                    'publisher' => 'مجمع ذخائر اسلامی',
                    'publication_year' => 1385,
                    'citation_pattern' => 'سید احمد روضاتی',
                    'metadata' => ['series' => 'اصفهان', 'city' => 'اصفهان'],
                ]
            );
            if (!$dryRun) {
                $volAhmadRowzati->catalogers()->syncWithoutDetaching([$qasemi->id => ['role' => 'cataloger']]);
                $c = DB::table('manuscripts')->where('library_id', 292)->update([
                    'catalog_volume_id' => $volAhmadRowzati->id,
                    'cataloger_id' => $qasemi->id,
                ]);
                $this->info("Linked {$c} manuscripts of Seyyed Ahmad Rowzati to Rahim Qasemi.");
            }
        }

        // 14. رضا خانی‌پور (تهران: کتابخانه ملی ایران جلد ۲۲ - قرآن‌ها)
        $khanipour = $this->getOrCreateCataloger('رضا خانی‌پور', $catalogerMap, $dryRun);
        if ($khanipour) {
            $v22 = CatalogVolume::where('library_id', 11)
                ->where(function ($q) {
                    $q->where('title', 'like', '%بیست و دوم%')
                      ->orWhere('title', 'like', '%قرآن%')
                      ->orWhere('volume_number', 22);
                })
                ->first();

            if ($v22) {
                $v22->update(['volume_number' => 22, 'citation_pattern' => 'ف: 22']);
                $v22->catalogers()->syncWithoutDetaching([$khanipour->id => ['role' => 'cataloger']]);
                if (!$dryRun) {
                    $c = DB::table('manuscripts')
                        ->where('library_id', 11)
                        ->where('metadata->catalog_citation', 'like', '%[ف: 22-%')
                        ->update(['catalog_volume_id' => $v22->id, 'cataloger_id' => $khanipour->id]);
                    $this->info("Linked {$c} manuscripts of Melli Vol 22 to Reza Khanipour.");
                }
            }
        }
    }

    protected function purgeInvalidAndForeignCatalogers(bool $dryRun)
    {
        // 1. گروه فهرست‌نگاران (حذف کامل بر اساس دستور صریح)
        $groupCat = Cataloger::where('name', 'گروه فهرست‌نگاران')->first();
        if ($groupCat) {
            $this->line("Detaching and deleting [{$groupCat->name}] (ID: {$groupCat->id})");
            if (!$dryRun) {
                $groupCat->catalogVolumes()->detach();
                DB::table('manuscripts')->where('cataloger_id', $groupCat->id)->update(['cataloger_id' => null]);
                $groupCat->delete();
            }
        }

        // 2. ادغام شناسه ۳۰ در شناسه ۸۴ (سید محمد طباطبایی بهبهانی منصور)
        $dup30 = Cataloger::find(30);
        $main84 = Cataloger::find(84);
        if ($dup30 && $main84) {
            $this->line("Merging duplicate cataloger ID 30 into ID 84...");
            if (!$dryRun) {
                foreach ($dup30->catalogVolumes as $v) {
                    $main84->catalogVolumes()->syncWithoutDetaching([$v->id => ['role' => 'cataloger']]);
                }
                $dup30->catalogVolumes()->detach();
                DB::table('manuscripts')->where('cataloger_id', 30)->update(['cataloger_id' => 84]);
                $dup30->delete();
            }
        }

        // 3. حذف فهرست‌نگاران خارجی و رکوردهای نامعتبر با کارنامه صفر در فنخا
        $purgeNames = [
            'تصدیق حسین کنتوری',
            'عبدالله محمد حبشی',
            'خضر عباسی نوشاهی',
            'سید عارف نوشاهی',
            'محمد حسین تسبیحی',
            'عابدرضا عرفانی',
            'فاطمه علیبکوا',
            'حسام الدین آق سو',
            'محمدرضا نصیری',
            'سیف الله مدبر',
            'محمود احمد محمد',
            'عبدالله حمود درهم عزی',
            'احمد یحیی',
            'سید محمدحسین حسینی جلالی',
            'یوسف قوجق',
            'حامد مزرجی',
            'حجة الإسلام والمسلمین شیخ محمد مهدی طه نجف',
            'سید مهدی غروی',
            'علی بهرامیان',
            'مهدی مشکوه الدینی',
            'ابو الفضل عرب زاده',
            'سید علی طباطبائی یزدی',
            'شیخ محمود ارگانی بهبهانی حائری',
            'علی‌اکبر صفری',
            'کاظم استادی',
            'جواد بشری',
            'پریسا کرم رضایی',
            'علامه اوحدی',
            'محمد صادق پور وجدی',
            'منصوره وثیق',
            'حسین شفیعی',
            'رضا کوچک زاده',
            'محمد نخجوانی',
        ];

        foreach ($purgeNames as $pName) {
            $c = Cataloger::where('name', $pName)->first();
            if ($c) {
                $mssCount = DB::table('manuscripts')->where('cataloger_id', $c->id)->count();
                if ($mssCount === 0) {
                    $this->line("Purging zero-manuscript record: [{$c->name}] (ID: {$c->id})");
                    if (!$dryRun) {
                        $c->catalogVolumes()->detach();
                        $c->delete();
                    }
                }
            }
        }

        // 4. حذف مجلدات خالی خارجی بدون کتابخانه
        if (!$dryRun) {
            $deletedVols = CatalogVolume::whereNull('library_id')->where('manuscripts_count', 0)->delete();
            if ($deletedVols > 0) {
                $this->info("Deleted {$deletedVols} empty external catalog volumes without library.");
            }
        }
    }

    protected function recalculateCounts()
    {
        // 1. Recalculate manuscripts_count on catalog_volumes
        $volCounts = DB::table('manuscripts')
            ->whereNotNull('catalog_volume_id')
            ->select('catalog_volume_id', DB::raw('count(*) as mss_count'))
            ->groupBy('catalog_volume_id')
            ->pluck('mss_count', 'catalog_volume_id');

        foreach (CatalogVolume::all() as $v) {
            $cnt = $volCounts[$v->id] ?? 0;
            $v->update(['manuscripts_count' => $cnt]);
        }

        // 2. Recalculate volumes_count and manuscripts_count on catalogers
        // Handles both direct cataloger_id and volumes authored by this cataloger
        foreach (Cataloger::all() as $cat) {
            $vIds = $cat->catalogVolumes()->pluck('catalog_volumes.id');
            $mCount = DB::table('manuscripts')
                ->where(function ($q) use ($cat, $vIds) {
                    $q->where('cataloger_id', $cat->id)
                      ->orWhereIn('catalog_volume_id', $vIds);
                })
                ->distinct()
                ->count();

            $cat->update([
                'volumes_count' => $vIds->count(),
                'manuscripts_count' => $mCount,
            ]);
        }
    }

    protected function normalizeName(string $name): string
    {
        $name = str_replace(["\xE2\x80\x8C", ' ', 'ي', 'ك', 'ة'], ['', '', 'ی', 'ک', 'ه'], $name);
        return trim($name);
    }
}
