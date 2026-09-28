<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class SyncWorkIncipitsCommand extends Command
{
    protected $signature = 'fahares:sync-work-incipits
                            {--source= : Custom directory path containing fahares_vol_XX.json files}
                            {--volume= : Specific volume number to sync (1-34)}';

    protected $description = 'Synchronize incipit_text, explicit_text and description for works from the JSON corpus into MariaDB';

    public function handle(): int
    {
        $startTime = microtime(true);
        $this->info('====================================================');
        $this->info('Starting Synchronization of Work Incipits & Explicits');
        $this->info('====================================================');

        $jsonDir = $this->resolveJsonDirectory();
        if (!$jsonDir) {
            $this->error('Could not locate or download the 34 JSON corpus files.');
            return 1;
        }

        $this->info("Using JSON corpus directory: {$jsonDir}");

        $targetVol = $this->option('volume') ? (int) $this->option('volume') : null;
        $volsToProcess = $targetVol ? [$targetVol] : range(1, 34);

        $totalWorksUpdated = 0;
        $totalIncipitsSet = 0;
        $totalExplicitsSet = 0;
        $totalDescriptionsSet = 0;

        foreach ($volsToProcess as $volNum) {
            $volStr = sprintf('%02d', $volNum);
            $fileName = "fahares_vol_{$volStr}.json";
            $filePath = "{$jsonDir}/{$fileName}";

            if (!File::exists($filePath)) {
                $this->warn("File {$fileName} not found. Skipping volume {$volNum}.");
                continue;
            }

            $raw = file_get_contents($filePath);
            $data = json_decode($raw, true);
            if (!$data || !isset($data['works'])) {
                $this->warn("Invalid JSON in {$fileName}. Skipping.");
                continue;
            }

            $jsonWorks = $data['works'];
            $dbWorks = DB::table('works')
                ->where('volume_number', $volNum)
                ->orderBy('id')
                ->get(['id', 'slug', 'primary_title'])
                ->keyBy(function ($item, $key) {
                    return $key;
                });

            $batchUpdates = [];
            foreach ($jsonWorks as $idx => $jw) {
                $inc = !empty($jw['incipit']) ? trim($jw['incipit']) : null;
                $exp = !empty($jw['explicit']) ? trim($jw['explicit']) : null;
                $desc = !empty($jw['description']) ? trim($jw['description']) : null;

                if ($inc === null && $exp === null && $desc === null) {
                    continue;
                }

                $dbWork = $dbWorks->get($idx);
                if (!$dbWork) {
                    continue;
                }

                // Safety verification: verify slug has volume and index
                $expectedSuffix = "-v{$volNum}-" . ($idx + 1);
                if (!str_ends_with($dbWork->slug, $expectedSuffix)) {
                    // Try slug lookup as fallback
                    $fallback = DB::table('works')
                        ->where('volume_number', $volNum)
                        ->where('slug', 'like', "%{$expectedSuffix}")
                        ->first(['id']);
                    if ($fallback) {
                        $targetId = $fallback->id;
                    } else {
                        $targetId = $dbWork->id;
                    }
                } else {
                    $targetId = $dbWork->id;
                }

                $batchUpdates[] = [
                    'id' => $targetId,
                    'incipit_text' => $inc,
                    'explicit_text' => $exp,
                    'description' => $desc,
                ];

                if ($inc) $totalIncipitsSet++;
                if ($exp) $totalExplicitsSet++;
                if ($desc) $totalDescriptionsSet++;
                $totalWorksUpdated++;
            }

            // Perform batch update inside transaction
            if (!empty($batchUpdates)) {
                DB::transaction(function () use ($batchUpdates) {
                    foreach ($batchUpdates as $u) {
                        DB::table('works')
                            ->where('id', $u['id'])
                            ->update([
                                'incipit_text' => $u['incipit_text'],
                                'explicit_text' => $u['explicit_text'],
                                'description' => $u['description'],
                            ]);
                    }
                });
            }

            $this->line("--> Volume {$volNum}: Synced " . count($batchUpdates) . " works with text.");
        }

        $duration = round(microtime(true) - $startTime, 2);
        $this->newLine();
        $this->info('====================================================');
        $this->info("SYNC COMPLETE in {$duration}s");
        $this->info("Total Works Updated:        " . number_format($totalWorksUpdated));
        $this->info("Total Incipits Added:       " . number_format($totalIncipitsSet));
        $this->info("Total Explicits Added:      " . number_format($totalExplicitsSet));
        $this->info("Total Descriptions Added:   " . number_format($totalDescriptionsSet));
        $this->info('====================================================');

        return 0;
    }

    protected function resolveJsonDirectory(): ?string
    {
        if ($custom = $this->option('source')) {
            if (File::isDirectory($custom)) {
                return rtrim($custom, '/');
            }
        }

        $candidates = [
            base_path('../fahares-corpus/json'),
            base_path('../fahares-corpus/json'),
            base_path('sources/json'),
            storage_path('app/corpus_json'),
        ];

        foreach ($candidates as $cand) {
            if (File::isDirectory($cand) && File::exists("{$cand}/fahares_vol_01.json")) {
                return $cand;
            }
        }

        // If running in container without local corpus, download Release v1.0.1
        $this->info('Local corpus not found. Downloading fahares_json_v1.0.1.tar.gz from GitHub Releases...');
        $downloadDir = storage_path('app/corpus_json');
        File::makeDirectory($downloadDir, 0755, true, true);

        $archiveUrl = 'https://github.com/fahares/fahares-corpus/releases/download/v1.0.1/fahares_json_v1.0.1.tar.gz';
        $tempArchive = storage_path('app/fahares_json_v1.0.1.tar.gz');

        $ch = curl_init($archiveUrl);
        $fp = fopen($tempArchive, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$success || $httpCode >= 400 || !File::exists($tempArchive)) {
            $this->error("Failed to download release archive from {$archiveUrl} (HTTP {$httpCode})");
            return null;
        }

        $this->info('Extracting archive to ' . $downloadDir . '...');
        exec("tar -xzf " . escapeshellarg($tempArchive) . " -C " . escapeshellarg($downloadDir), $output, $exitCode);
        File::delete($tempArchive);

        if ($exitCode !== 0 || !File::exists("{$downloadDir}/fahares_vol_01.json")) {
            $this->error('Failed to extract corpus archive.');
            return null;
        }

        return $downloadDir;
    }
}
