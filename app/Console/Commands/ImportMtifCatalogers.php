<?php

namespace App\Console\Commands;

use App\Models\Catalog;
use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ImportMtifCatalogers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:mtif-catalogers {--json= : Path to extracted JSON file} {--link-manuscripts : Link manuscripts to catalogers and volumes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import catalogers, catalog volumes, and map citations from mtif.org data';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $jsonPath = $this->option('json') ?: storage_path('mtif_extracted_data.json');

        if (!File::exists($jsonPath)) {
            $this->error("JSON file not found at: {$jsonPath}");
            return 1;
        }

        $this->info("Loading data from: {$jsonPath}...");
        $data = json_decode(File::get($jsonPath), true);

        $catalogersData = $data['catalogers'] ?? [];
        $volumesData = $data['catalog_volumes'] ?? [];

        $this->info("Found " . count($catalogersData) . " catalogers and " . count($volumesData) . " catalog volumes.");

        // 1. Import Catalogers
        $this->info("Importing catalogers...");
        $catalogerMap = []; // mtif_id -> Cataloger model

        $bar = $this->output->createProgressBar(count($catalogersData));
        foreach ($catalogersData as $c) {
            $name = trim($c['name']);
            if (empty($name)) {
                $bar->advance();
                continue;
            }

            // Check if exists by mtif_entry_id or name
            $cataloger = Cataloger::where('mtif_entry_id', $c['mtif_id'])
                ->orWhere('name', $name)
                ->first();

            $avatarPath = null;
            $localAvatar = "catalogers/avatars/{$c['mtif_id']}.jpg";
            if (File::exists(storage_path("app/public/{$localAvatar}"))) {
                $avatarPath = $localAvatar;
            } elseif (!empty($c['avatar_url'])) {
                $avatarPath = $c['avatar_url'];
            }

            // Match with Person if exists
            $person = Person::where('name', $name)
                ->orWhere('name', 'like', "%{$name}%")
                ->first();

            $slug = Str::slug($name, '-', null);
            if (empty($slug)) {
                $slug = 'cataloger-' . $c['mtif_id'];
            }

            // Ensure unique slug
            $existingSlugCount = Cataloger::where('slug', $slug)
                ->when($cataloger, fn($q) => $q->where('id', '!=', $cataloger->id))
                ->count();
            if ($existingSlugCount > 0) {
                $slug .= '-' . $c['mtif_id'];
            }

            $attributes = [
                'name' => $name,
                'slug' => $slug,
                'title_prefix' => !empty($c['prefix']) ? trim($c['prefix']) : null,
                'nickname' => !empty($c['nickname']) ? trim($c['nickname']) : null,
                'birth_year_solar' => $c['birth_year_solar'] ?? null,
                'death_year_solar' => $c['death_year_solar'] ?? null,
                'birth_year_hijri' => $c['birth_year_hijri'] ?? null,
                'death_year_hijri' => $c['death_year_hijri'] ?? null,
                'is_alive' => (bool) ($c['is_alive'] ?? false),
                'bio' => !empty($c['bio']) ? trim($c['bio']) : null,
                'avatar_path' => $avatarPath,
                'mtif_entry_id' => $c['mtif_id'],
                'person_id' => $person?->id,
            ];

            if ($cataloger) {
                $cataloger->update($attributes);
            } else {
                $cataloger = Cataloger::create($attributes);
            }

            $catalogerMap[$c['mtif_id']] = $cataloger;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info("Catalogers imported successfully.");

        // 2. Map Libraries
        $libraryKeywords = [
            'مجلس' => 4,
            'مرعشی' => 2,
            'دانشگاه' => 3,
            'رضوی' => 1,
            'ملک' => 15,
            'گلپایگانی' => 5,
            'وزیری' => 32,
            'سپهسالار' => 22,
            'ملی' => 11,
            'الهیات' => 38,
            'فیضیه' => 8,
            'مسجد اعظم' => 21,
            'مرکز احیاء' => 10,
        ];

        // 3. Import Catalog Volumes
        $this->info("Importing catalog volumes...");
        $bar = $this->output->createProgressBar(count($volumesData));
        $volumeMap = []; // mtif_id -> CatalogVolume model

        foreach ($volumesData as $v) {
            $libraryId = null;
            if (!empty($v['library_name']) && isset($libraryKeywords[$v['library_name']])) {
                $libraryId = $libraryKeywords[$v['library_name']];
            }

            $title = trim($v['title']);
            if (empty($title)) {
                $title = trim($v['series_title']) . ($v['volume_number'] ? " - جلد {$v['volume_number']}" : '');
            }

            $volume = CatalogVolume::updateOrCreate(
                ['mtif_entry_id' => $v['mtif_id']],
                [
                    'title' => $title,
                    'volume_number' => $v['volume_number'] ?? null,
                    'library_id' => $libraryId,
                    'catalog_id' => 1, // Fankha
                    'pages_count' => $v['pages_count'] ?? null,
                    'manuscripts_range' => $v['manuscripts_range'] ?? null,
                    'publication_year' => $v['publication_year'] ?? null,
                    'publisher' => $v['publisher'] ?? null,
                    'citation_pattern' => $v['volume_number'] ? "ف: {$v['volume_number']}" : null,
                ]
            );

            // Attach authors
            if (!empty($v['author_ids'])) {
                $catIds = [];
                foreach ($v['author_ids'] as $aid) {
                    if (isset($catalogerMap[$aid])) {
                        $catIds[$catalogerMap[$aid]->id] = ['role' => 'cataloger'];
                    }
                }
                if (!empty($catIds)) {
                    $volume->catalogers()->syncWithoutDetaching($catIds);
                }
            }

            $volumeMap[$v['mtif_id']] = $volume;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info("Catalog volumes imported successfully.");

        // 4. Update Catalogers volume counts
        $this->info("Updating catalogers volume counts...");
        foreach (Cataloger::all() as $cat) {
            $vCount = $cat->catalogVolumes()->count();
            $cat->update(['volumes_count' => $vCount]);
        }

        // 5. Link Manuscripts
        if ($this->option('link-manuscripts')) {
            $this->info("Linking manuscripts to catalogers and catalog volumes based on citations...");
            $this->linkManuscripts();
        }

        $this->info("MTIF Catalogers Import Completed Successfully!");
        return 0;
    }

    /**
     * Link manuscripts to catalogers and catalog volumes based on library and citation text.
     */
    protected function linkManuscripts()
    {
        $libraries = [
            4  => 'مجلس',
            2  => 'مرعشی',
            3  => 'دانشگاه',
            5  => 'گلپایگانی',
            32 => 'وزیری',
            10 => 'مرکز احیاء',
            21 => 'مسجد اعظم',
            8  => 'فیضیه',
        ];

        foreach ($libraries as $libId => $libName) {
            $this->info("Processing Library: {$libName} (ID {$libId})...");

            // Get all volumes for this library
            $volumes = CatalogVolume::where('library_id', $libId)
                ->whereNotNull('volume_number')
                ->with('catalogers')
                ->get();

            if ($volumes->isEmpty()) {
                $this->warn("No catalog volumes registered for library {$libName}.");
                continue;
            }

            $volMap = [];
            foreach ($volumes as $vol) {
                $primaryCataloger = $vol->catalogers->first();
                $volMap[$vol->volume_number] = [
                    'volume_id' => $vol->id,
                    'cataloger_id' => $primaryCataloger?->id,
                ];
            }

            $this->info("Found " . count($volMap) . " volume mappings for {$libName}. Updating manuscripts...");

            // Process manuscripts for this library in chunks
            $updated = 0;
            Manuscript::where('library_id', $libId)
                ->whereNotNull('raw_text')
                ->chunkById(2000, function ($manuscripts) use ($volMap, &$updated) {
                    foreach ($manuscripts as $m) {
                        // Extract citation from brackets: e.g. [ف: 16-326] or [سنا - ف: 1-61] or [فیلم‌ها - ف: 1-560]
                        if (preg_match('/\[(?:[^\:\]]+[:\-])?\s*ف[:\s]+(\d+)[-ـ]/u', $m->raw_text, $match)) {
                            $volNum = (int) $match[1];
                            if (isset($volMap[$volNum])) {
                                DB::table('manuscripts')
                                    ->where('id', $m->id)
                                    ->update([
                                        'catalog_volume_id' => $volMap[$volNum]['volume_id'],
                                        'cataloger_id' => $volMap[$volNum]['cataloger_id'],
                                    ]);
                                $updated++;
                            }
                        }
                    }
                });

            $this->info("Updated {$updated} manuscripts for {$libName}.");
        }

        // Recalculate manuscripts_count for all catalogers
        $this->info("Recalculating manuscripts_count for catalogers...");
        foreach (Cataloger::all() as $cat) {
            $mCount = Manuscript::where('cataloger_id', $cat->id)->count();
            $cat->update(['manuscripts_count' => $mCount]);
        }

        // Recalculate manuscripts_count for all catalog_volumes
        $this->info("Recalculating manuscripts_count for catalog volumes...");
        foreach (CatalogVolume::all() as $vol) {
            $mCount = Manuscript::where('catalog_volume_id', $vol->id)->count();
            $vol->update(['manuscripts_count' => $mCount]);
        }
    }
}
