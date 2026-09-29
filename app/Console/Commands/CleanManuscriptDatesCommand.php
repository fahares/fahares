<?php

namespace App\Console\Commands;

use App\Models\Manuscript;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanManuscriptDatesCommand extends Command
{
    protected $signature = 'fahares:clean-manuscript-dates {--dry-run : Run without saving changes} {--sync-search : Sync updated records to Meilisearch} {--force : Recompute commissioned_by from raw_text}';

    protected $description = 'Clean copy_date_raw from leaked patronage and codicological notes, and extract commissioned_by from raw_text';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $syncSearch = (bool) $this->option('sync-search');
        $force = (bool) $this->option('force');

        $this->info('Starting Manuscript Date Cleaning & Patronage Extraction...');

        $commPrefixes = '(?:(?:بنا\s*به|به)\s*(?:دستور|فرموده|فرمایش|امر|فرمان|خواهش|درخواست|تقاضای|استدعای|اشاره|اشارت|طلب|نام|اهتمام|سعی|رسم|قلم)|حسب\s*(?:الامر|فرموده|خواهش|فرمایش|الطلب)|برای\s+|جهت\s+|به\s*جهت\s+|به‌جهت\s+|برسم\s+)';
        $codicologyLeaks = '(?:اهدایی[:\s]|خریداری(?:\s+از)?|وقف(?:\s+بر|:\s*)?|تملک[:\s]|افتادگی[:\s]|با\s+سرلوح|محشی(?:\s+با|\s+به|[؛،\s]|$)|الذریعه|از\s+روی|پس\s+از)';

        $splitRegex = '/^(.*?)[,،]\s*(' . $commPrefixes . '|' . $codicologyLeaks . ')(.*)$/u';
        $commExtractRegex = '/(?:^|[؛،\n])\s*([^؛،\n]*?' . $commPrefixes . '[:\s]*([^؛\n\[]+))/u';

        $totalCleanedDates = 0;
        $totalCommissioned = 0;
        $updatedIds = [];

        $total = Manuscript::count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Manuscript::select(['id', 'copy_date_raw', 'raw_text', 'commissioned_by'])
            ->chunkById(2000, function ($mss) use (&$totalCleanedDates, &$totalCommissioned, &$updatedIds, $splitRegex, $commExtractRegex, $commPrefixes, $isDryRun, $syncSearch, $force, $bar) {
                $batchUpdates = [];

                foreach ($mss as $m) {
                    $newDate = $m->copy_date_raw;
                    $newComm = $force ? null : $m->commissioned_by;
                    $changed = false;

                    // 1. Clean copy_date_raw if leaked
                    if (!empty($m->copy_date_raw) && preg_match($splitRegex, $m->copy_date_raw, $matches)) {
                        $cleanCandidate = preg_replace('/^[\s،,]+|[\s،,]+$/u', '', $matches[1]);
                        if (!empty($cleanCandidate) && $cleanCandidate !== $m->copy_date_raw) {
                            $newDate = $cleanCandidate;
                            $changed = true;
                            $totalCleanedDates++;

                            // If commissioned_by is empty and prefix is patronage, extract it
                            $leakedPrefix = $matches[2];
                            $leakedRest = $matches[3];
                            $leakedPart = trim($leakedPrefix . $leakedRest);

                            if (empty($newComm) && preg_match('/^' . $commPrefixes . '/u', $leakedPrefix)) {
                                if (preg_match('/' . $commPrefixes . '[:\s]*(.*)$/u', $leakedPart, $cm)) {
                                    $cVal = trim($cm[1]);
                                    $cVal = preg_replace('/\s*(?:کتابت شده|نوشته شده|تحریر شده|نگاشته شده|انجام شده).*$/u', '', $cVal);
                                    $cVal = preg_replace('/^[\s؛،,\[\].]+|[\s؛،,\[\].]+$/u', '', $cVal);
                                    if (mb_strlen($cVal) >= 2 && mb_strlen($cVal) <= 150) {
                                        $newComm = $cVal;
                                    }
                                }
                            }
                        }
                    }

                    // 2. Extract commissioned_by from raw_text if still empty
                    if (empty($newComm) && !empty($m->raw_text)) {
                        $raw = preg_replace('/<!--.*?-->/', ' ', $m->raw_text);
                        if (preg_match('/(?:خط|کا|تا)\s*:(.*)$/us', $raw, $metaMatch)) {
                            $searchIn = $metaMatch[1];
                        } else {
                            $searchIn = preg_replace('/(?:آغاز|انجام)\s*:[^\n]+/u', '', $raw);
                        }

                        if (preg_match($commExtractRegex, $searchIn, $cmm)) {
                            $innerTarget = $cmm[1];
                            if (preg_match('/' . $commPrefixes . '[:\s]*([^؛\n\[]+)/u', $innerTarget, $im)) {
                                $cVal = trim($im[1]);
                                $cVal = preg_replace('/\s*(?:کتابت شده|نوشته شده|تحریر شده|نگاشته شده|انجام شده).*$/u', '', $cVal);
                                $cVal = preg_replace('/^[\s؛،,\[\].]+|[\s؛،,\[\].]+$/u', '', $cVal);
                                if (mb_strlen($cVal) >= 2 && mb_strlen($cVal) <= 150) {
                                    $newComm = $cVal;
                                }
                            }
                        }
                    }

                    if (!empty($newComm)) {
                        $totalCommissioned++;
                    }

                    if ($newComm !== $m->commissioned_by) {
                        $changed = true;
                    }

                    if ($changed) {
                        $batchUpdates[] = [
                            'id' => $m->id,
                            'copy_date_raw' => $newDate,
                            'commissioned_by' => $newComm,
                        ];
                        if ($syncSearch) {
                            $updatedIds[] = $m->id;
                        }
                    }

                    $bar->advance();
                }

                if (!$isDryRun && !empty($batchUpdates)) {
                    DB::transaction(function () use ($batchUpdates) {
                        foreach ($batchUpdates as $up) {
                            DB::table('manuscripts')->where('id', $up['id'])->update([
                                'copy_date_raw' => $up['copy_date_raw'],
                                'commissioned_by' => $up['commissioned_by'],
                            ]);
                        }
                    });
                }
            });

        $bar->finish();
        $this->newLine(2);

        $this->info("==================================================");
        $this->info("Dates cleaned: " . number_format($totalCleanedDates));
        $this->info("Commissioned by extracted: " . number_format($totalCommissioned));
        $this->info("==================================================");

        if ($syncSearch && !$isDryRun && !empty($updatedIds)) {
            $this->info("Syncing " . count($updatedIds) . " records to Meilisearch...");
            foreach (array_chunk($updatedIds, 500) as $chunk) {
                Manuscript::whereIn('id', $chunk)->searchable();
            }
            $this->info("Meilisearch sync complete.");
        }

        return 0;
    }
}
