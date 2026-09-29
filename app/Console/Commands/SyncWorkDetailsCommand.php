<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SyncWorkDetailsCommand extends Command
{
    protected $signature = 'fahares:sync-work-details
                            {--source= : Custom directory path containing fahares_vol_XX.json files}
                            {--volume= : Specific volume number to sync (1-34)}
                            {--skip-manuscripts : Skip updating manuscript page numbers}';

    protected $description = 'Synchronize work raw_text, bibliography, print_info, dedication, related_work, commentaries_and_glosses, and manuscript page numbers from JSON';

    public function handle(): int
    {
        $startTime = microtime(true);
        $this->info('================================================================');
        $this->info('Starting Synchronization of Work Details, Raw Text & Page Tracks');
        $this->info('================================================================');

        $jsonDir = $this->resolveJsonDirectory();
        if (!$jsonDir) {
            $this->error('Could not locate the 34 JSON corpus files.');
            return 1;
        }

        $this->info("Using JSON corpus directory: {$jsonDir}");

        $targetVol = $this->option('volume') ? (int) $this->option('volume') : null;
        $skipManuscripts = (bool) $this->option('skip-manuscripts');
        $volsToProcess = $targetVol ? [$targetVol] : range(1, 34);

        $totalWorksUpdated = 0;
        $totalRawTextSet = 0;
        $totalBibliographiesSet = 0;
        $totalPrintInfoSet = 0;
        $totalDedicationsSet = 0;
        $totalRelatedWorksSet = 0;
        $totalCommentariesSet = 0;
        $totalPlacesSet = 0;
        $totalWorkPagesUpdated = 0;
        $totalManuscriptPagesUpdated = 0;

        foreach ($volsToProcess as $volNum) {
            $volStartTime = microtime(true);
            $volStr = sprintf('%02d', $volNum);
            $fileName = "fankha_vol_{$volStr}.json";
            $filePath = "{$jsonDir}/{$fileName}";
            if (!File::exists($filePath)) {
                $fileName = "fahares_vol_{$volStr}.json";
                $filePath = "{$jsonDir}/{$fileName}";
            }

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
                ->get(['id', 'slug', 'primary_title', 'page_start', 'page_end'])
                ->keyBy(function ($item, $key) {
                    return $key;
                });

            $dbMssByWork = [];
            if (!$skipManuscripts) {
                $allMss = DB::table('manuscripts')
                    ->where('volume_number', $volNum)
                    ->orderBy('sequence_number')
                    ->orderBy('id')
                    ->get(['id', 'work_id', 'sequence_number', 'page_start', 'page_end']);
                foreach ($allMss as $m) {
                    $dbMssByWork[$m->work_id][] = $m;
                }
            }

            $workUpdates = [];
            $manuscriptUpdates = [];

            foreach ($jsonWorks as $idx => $jw) {
                $dbWork = $dbWorks->get($idx);
                if (!$dbWork) {
                    continue;
                }

                $expectedSuffix = "-v{$volNum}-" . ($idx + 1);
                if (!str_ends_with($dbWork->slug, $expectedSuffix)) {
                    $fallback = DB::table('works')
                        ->where('volume_number', $volNum)
                        ->where('slug', 'like', "%{$expectedSuffix}")
                        ->first(['id', 'page_start', 'page_end']);
                    $targetWorkId = $fallback ? $fallback->id : $dbWork->id;
                    $dbPageStart = $fallback ? $fallback->page_start : $dbWork->page_start;
                    $dbPageEnd = $fallback ? $fallback->page_end : $dbWork->page_end;
                } else {
                    $targetWorkId = $dbWork->id;
                    $dbPageStart = $dbWork->page_start;
                    $dbPageEnd = $dbWork->page_end;
                }

                $rawText = !empty($jw['raw_text']) ? $jw['raw_text'] : null;
                $bib = !empty($jw['bibliography']) ? json_encode($jw['bibliography'], JSON_UNESCAPED_UNICODE) : null;
                $print = !empty($jw['print_info']) ? $jw['print_info'] : null;
                $ded = !empty($jw['dedication']) ? $jw['dedication'] : null;
                $rel = !empty($jw['related_work']) ? $jw['related_work'] : null;
                $comm = !empty($jw['commentaries_and_glosses']) ? json_encode($jw['commentaries_and_glosses'], JSON_UNESCAPED_UNICODE) : null;
                $place = !empty($jw['composition_place']) ? $jw['composition_place'] : null;
                $pStart = (int) ($jw['page_start'] ?? $dbPageStart);
                $pEnd = !empty($jw['page_end']) ? (int) $jw['page_end'] : $pStart;

                if ($rawText) $totalRawTextSet++;
                if ($bib) $totalBibliographiesSet++;
                if ($print) $totalPrintInfoSet++;
                if ($ded) $totalDedicationsSet++;
                if ($rel) $totalRelatedWorksSet++;
                if ($comm) $totalCommentariesSet++;
                if ($place) $totalPlacesSet++;
                if ($pStart !== (int)$dbPageStart || $pEnd !== (int)$dbPageEnd) {
                    $totalWorkPagesUpdated++;
                }

                $workUpdates[] = [
                    'id' => $targetWorkId,
                    'raw_text' => $rawText,
                    'bibliography' => $bib,
                    'print_info' => $print,
                    'dedication' => $ded,
                    'related_work' => $rel,
                    'commentaries_and_glosses' => $comm,
                    'composition_place' => $place,
                    'page_start' => $pStart,
                    'page_end' => $pEnd,
                ];
                $totalWorksUpdated++;

                // Sync manuscript pages
                if (!$skipManuscripts && isset($dbMssByWork[$targetWorkId]) && !empty($jw['manuscripts'])) {
                    $mssList = $dbMssByWork[$targetWorkId];
                    foreach ($jw['manuscripts'] as $mIdx => $jm) {
                        if (isset($mssList[$mIdx])) {
                            $dbM = $mssList[$mIdx];
                            $newSp = (int) $jm['page_start'];
                            $newEp = (int) ($jm['page_end'] ?? $newSp);
                            if ((int)$dbM->page_start !== $newSp || (int)$dbM->page_end !== $newEp) {
                                $manuscriptUpdates[] = [
                                    'id' => $dbM->id,
                                    'page_start' => $newSp,
                                    'page_end' => $newEp,
                                ];
                                $totalManuscriptPagesUpdated++;
                            }
                        }
                    }
                }
            }

            // Perform updates inside database transaction
            DB::transaction(function () use ($workUpdates, $manuscriptUpdates) {
                foreach (array_chunk($workUpdates, 250) as $chunk) {
                    foreach ($chunk as $w) {
                        DB::table('works')->where('id', $w['id'])->update([
                            'raw_text' => $w['raw_text'],
                            'bibliography' => $w['bibliography'],
                            'print_info' => $w['print_info'],
                            'dedication' => $w['dedication'],
                            'related_work' => $w['related_work'],
                            'commentaries_and_glosses' => $w['commentaries_and_glosses'],
                            'composition_place' => $w['composition_place'],
                            'page_start' => $w['page_start'],
                            'page_end' => $w['page_end'],
                        ]);
                    }
                }

                foreach (array_chunk($manuscriptUpdates, 500) as $chunk) {
                    foreach ($chunk as $m) {
                        DB::table('manuscripts')->where('id', $m['id'])->update([
                            'page_start' => $m['page_start'],
                            'page_end' => $m['page_end'],
                        ]);
                    }
                }
            });

            $volDuration = round(microtime(true) - $volStartTime, 2);
            $this->line("--> Volume {$volNum}: Synced " . count($workUpdates) . " works, " . count($manuscriptUpdates) . " manuscript page corrections in {$volDuration}s.");
        }

        $duration = round(microtime(true) - $startTime, 2);
        $this->newLine();
        $this->info('================================================================');
        $this->info("SYNC COMPLETE in {$duration}s");
        $this->info("Total Works Processed:        " . number_format($totalWorksUpdated));
        $this->info("Total Raw Texts Added:        " . number_format($totalRawTextSet));
        $this->info("Total Bibliographies Added:   " . number_format($totalBibliographiesSet));
        $this->info("Total Print Infos Added:      " . number_format($totalPrintInfoSet));
        $this->info("Total Dedications Added:      " . number_format($totalDedicationsSet));
        $this->info("Total Related Works Added:    " . number_format($totalRelatedWorksSet));
        $this->info("Total Commentaries Added:     " . number_format($totalCommentariesSet));
        $this->info("Total Places Added:           " . number_format($totalPlacesSet));
        $this->info("Total Work Pages Adjusted:    " . number_format($totalWorkPagesUpdated));
        $this->info("Total MS Pages Corrected:     " . number_format($totalManuscriptPagesUpdated));
        $this->info('================================================================');

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
            base_path('../fahares-corpus/json/fankha'),
            base_path('../fahares-corpus/json'),
            base_path('sources/json/fankha'),
            base_path('sources/json'),
            storage_path('app/corpus_json/fankha'),
            storage_path('app/corpus_json'),
        ];

        foreach ($candidates as $cand) {
            if (File::isDirectory($cand) && (File::exists("{$cand}/fankha_vol_01.json") || File::exists("{$cand}/fahares_vol_01.json"))) {
                return $cand;
            }
        }

        // If running in container without local corpus, download Release v1.1.3
        $this->info('Local corpus not found. Downloading fankha_json_v1.1.3.tar.gz from GitHub Releases...');
        $downloadDir = storage_path('app/corpus_json');
        File::makeDirectory($downloadDir, 0755, true, true);

        $archiveUrl = 'https://github.com/fahares/fahares-corpus/releases/download/v1.1.3/fankha_json_v1.1.3.tar.gz';
        $tempArchive = storage_path('app/fankha_json_v1.1.3.tar.gz');

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

        if ($exitCode !== 0 || (!File::exists("{$downloadDir}/fankha_vol_01.json") && !File::exists("{$downloadDir}/fahares_vol_01.json"))) {
            $this->error('Failed to extract corpus archive.');
            return null;
        }

        return $downloadDir;
    }
}
