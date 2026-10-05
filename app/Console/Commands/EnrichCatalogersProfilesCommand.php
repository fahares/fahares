<?php

namespace App\Console\Commands;

use App\Models\Cataloger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class EnrichCatalogersProfilesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catalogers:enrich-profiles 
                            {--file= : Path to specific profiles JSON file}
                            {--dry-run : Run without saving changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enrich catalogers profiles with biography, dates, places, institutions, and avatars';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $specificFile = $this->option('file');
        $dryRun = (bool) $this->option('dry-run');

        $files = [];
        if ($specificFile) {
            $path = base_path($specificFile);
            if (!File::exists($path)) {
                $this->error("Specified file not found: {$path}");
                return self::FAILURE;
            }
            $files[] = $path;
        } else {
            $files = glob(database_path('data/catalogers_profiles_*.json'));
            if (empty($files)) {
                $this->warn('No catalogers_profiles_*.json files found in database/data/.');
                return self::SUCCESS;
            }
        }

        $totalProfiles = 0;
        $matchedCount = 0;
        $updatedCount = 0;
        $notFound = [];

        foreach ($files as $filePath) {
            $fileName = basename($filePath);
            $this->info("Processing profiles file: {$fileName}...");

            $content = File::get($filePath);
            $profiles = json_decode($content, true);

            if (!is_array($profiles)) {
                $this->error("Invalid JSON format in {$fileName}");
                continue;
            }

            foreach ($profiles as $item) {
                $totalProfiles++;
                $name = trim($item['name'] ?? '');

                if (!$name) {
                    continue;
                }

                $cataloger = Cataloger::where('name', $name)->first();

                if (!$cataloger) {
                    // Try without parentheses if exists
                    $nameClean = preg_replace('/\s*\(.*?\)\s*/', '', $name);
                    $cataloger = Cataloger::where('name', 'like', "%{$nameClean}%")->first();
                }

                if (!$cataloger) {
                    $notFound[] = $name;
                    $this->warn("[-] Cataloger not found in database: {$name}");
                    continue;
                }

                $matchedCount++;

                $fieldsToUpdate = [];

                if (!empty($item['title_prefix'])) {
                    $fieldsToUpdate['title_prefix'] = $item['title_prefix'];
                }

                if (!empty($item['nickname'])) {
                    $fieldsToUpdate['nickname'] = $item['nickname'];
                }

                if (!empty($item['bio'])) {
                    $fieldsToUpdate['bio'] = $item['bio'];
                }

                if (array_key_exists('birth_year_solar', $item)) {
                    $fieldsToUpdate['birth_year_solar'] = $item['birth_year_solar'];
                }

                if (array_key_exists('death_year_solar', $item)) {
                    $fieldsToUpdate['death_year_solar'] = $item['death_year_solar'];
                }

                if (array_key_exists('birth_year_hijri', $item)) {
                    $fieldsToUpdate['birth_year_hijri'] = $item['birth_year_hijri'];
                }

                if (array_key_exists('death_year_hijri', $item)) {
                    $fieldsToUpdate['death_year_hijri'] = $item['death_year_hijri'];
                }

                if (array_key_exists('is_alive', $item)) {
                    $fieldsToUpdate['is_alive'] = (bool) $item['is_alive'];
                }

                if (!empty($item['avatar_path'])) {
                    $fieldsToUpdate['avatar_path'] = $item['avatar_path'];
                }

                if (!empty($item['metadata']) && is_array($item['metadata'])) {
                    $existingMetadata = $cataloger->metadata ?? [];
                    $fieldsToUpdate['metadata'] = array_merge($existingMetadata, $item['metadata']);
                }

                if (!empty($fieldsToUpdate)) {
                    if (!$dryRun) {
                        $cataloger->update($fieldsToUpdate);
                    }
                    $updatedCount++;
                    $this->line("  [+] Updated: <info>{$cataloger->name}</info> (ID: {$cataloger->id})");
                }
            }
        }

        $this->newLine();
        $this->info("=== Enrichment Summary ===");
        $this->info("Total profiles in JSON: {$totalProfiles}");
        $this->info("Matched in database: {$matchedCount}");
        $this->info("Updated records: {$updatedCount}");

        if (!empty($notFound)) {
            $this->warn("Unmatched profiles (" . count($notFound) . "): " . implode(', ', $notFound));
        }

        if ($dryRun) {
            $this->warn("[DRY RUN] No database changes were saved.");
        }

        return self::SUCCESS;
    }
}
