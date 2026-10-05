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
        // STEP 3: Link Manuscripts
        // -------------------------------------------------------------
        $this->info("Step 3: Linking manuscripts to catalogers and volumes...");
        $this->linkManuscripts($masterData, $volumeMap, $dryRun);

        // -------------------------------------------------------------
        // STEP 4: Recalculate Manuscript & Volume Counts
        // -------------------------------------------------------------
        if (!$dryRun) {
            $this->info("Step 4: Recalculating volumes and manuscripts counts...");
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
        $catCounts = DB::table('manuscripts')
            ->whereNotNull('cataloger_id')
            ->select('cataloger_id', DB::raw('count(*) as mss_count'))
            ->groupBy('cataloger_id')
            ->pluck('mss_count', 'cataloger_id');

        foreach (Cataloger::all() as $cat) {
            $vCount = $cat->catalogVolumes()->count();
            $mCount = $catCounts[$cat->id] ?? 0;
            $cat->update([
                'volumes_count' => $vCount,
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
