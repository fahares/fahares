<?php

namespace App\Console\Commands;

use App\Models\Manuscript;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixManuscriptFoliosCommand extends Command
{
    protected $signature = 'fahares:fix-folios 
                            {--id= : Fix a single specific manuscript ID}
                            {--limit= : Limit the number of candidates to process}
                            {--execute : Persist changes to the database (default is dry-run mode)}
                            {--report= : Optional file path to output JSON report of changes}';

    protected $description = 'Safely detect and fix folios discrepancies caused by partial page notes (e.g. نونویس, افتادگی)';

    public function handle(): int
    {
        $isExecute = $this->option('execute');
        $targetId = $this->option('id');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $reportPath = $this->option('report');

        if (!$isExecute) {
            $this->warn('========================================================');
            $this->warn('  [حالت اجرای آزمایشی / DRY-RUN MODE]');
            $this->warn('  هیچ تغییری در دیتابیس اعمال نخواهد شد.');
            $this->warn('  برای ثبت نهایی، از پرچم --execute استفاده کنید.');
            $this->warn('========================================================');
        } else {
            $this->alert('حالت اعمال قطعی در دیتابیس فعال است (--execute)');
        }

        $query = Manuscript::query()->whereNotNull('raw_text');

        if ($targetId) {
            $query->where('id', $targetId);
        } else {
            // Target entries that have leaf markers in raw text and keywords of defects / multi-matches
            $query->whereRaw("raw_text REGEXP '[0-9]+[\\\\s]*(?:گ|برگ)'")
                  ->where(function ($q) {
                      $q->where('raw_text', 'like', '%نونویس%')
                        ->orWhere('raw_text', 'like', '%نو نویس%')
                        ->orWhere('raw_text', 'like', '%افتادگی%')
                        ->orWhere('raw_text', 'like', '%افتاده%')
                        ->orWhere('raw_text', 'like', '%برگ اول%')
                        ->orWhere('raw_text', 'like', '%برگ آخر%')
                        ->orWhere('raw_text', 'like', '%صفحه اول%')
                        ->orWhere('raw_text', 'like', '%صفحه آخر%')
                        ->orWhere('raw_text', 'like', '%برگ اضافی%')
                        ->orWhere('raw_text', 'like', '%نانوشته%')
                        ->orWhere('raw_text', 'like', '%برگ‌شمار%')
                        ->orWhere('raw_text', 'like', '%برگ شمار%');
                  });
        }

        if ($limit) {
            $query->limit($limit);
        }

        $candidates = $query->get(['id', 'folios', 'raw_text', 'metadata']);
        $this->info("تعداد نسخه‌های واجد شرایط جهت بررسی: " . count($candidates));

        $discrepancies = [];

        foreach ($candidates as $ms) {
            $best = self::extractTrueFolios($ms->raw_text);
            if (!$best) {
                continue;
            }

            $detectedNum = $best['num'];
            $currentFolios = $ms->folios;

            // Only report if there is a real discrepancy and confidence score is high
            if ($currentFolios != $detectedNum && $best['score'] >= 50) {
                // Check if residual_notes contains the raw folios match
                $rawSpan = $best['full'];
                $meta = $ms->metadata ?? [];
                $oldResidual = $meta['residual_notes'] ?? null;
                $cleanedResidual = $oldResidual;

                if (!empty($oldResidual) && is_string($oldResidual)) {
                    $cleanedResidual = preg_replace('/[،؛\.\s]*' . preg_quote($detectedNum, '/') . '\s*(?:گ|برگ)[،؛\.\s]*/u', ' ', $oldResidual);
                    $cleanedResidual = trim(preg_replace('/[،؛\s\.]+/u', ' ', $cleanedResidual));
                    if ($cleanedResidual === '') {
                        $cleanedResidual = null;
                    }
                }

                $discrepancies[] = [
                    'id' => $ms->id,
                    'current_folios' => $currentFolios,
                    'detected_folios' => $detectedNum,
                    'score' => $best['score'],
                    'matched_span' => $rawSpan,
                    'old_residual' => $oldResidual,
                    'new_residual' => $cleanedResidual,
                    'model' => $ms,
                ];
            }
        }

        $this->info("تعداد مغایرت‌های کشف‌شده با ضریب اطمینان بالا: " . count($discrepancies));

        if (empty($discrepancies)) {
            $this->info("هیچ مغایرتی در این جامعه آماری یافت نشد.");
            return Command::SUCCESS;
        }

        // Display sample table (first 25 items)
        $tableRows = [];
        foreach (array_slice($discrepancies, 0, 25) as $d) {
            $tableRows[] = [
                $d['id'],
                $d['current_folios'] ?? 'نامشخص',
                $d['detected_folios'],
                $d['score'],
                $d['matched_span'],
                mb_substr($d['new_residual'] ?? '-', 0, 40),
            ];
        }

        $this->table(['شناسه', 'برگ فعلی', 'برگ تصحیح‌شده', 'امتیاز', 'قطعه متن', 'یادداشت باقیمانده جدید'], $tableRows);

        if (count($discrepancies) > 25) {
            $this->comment("... و " . (count($discrepancies) - 25) . " مورد دیگر.");
        }

        // Save JSON report if requested
        if ($reportPath) {
            $reportData = array_map(function ($item) {
                unset($item['model']);
                return $item;
            }, $discrepancies);
            file_put_contents($reportPath, json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("گزارش کامل در فایل ذخیره شد: {$reportPath}");
        }

        // Apply changes if --execute is explicitly set
        if ($isExecute) {
            $this->warn("در حال اعمال تغییرات درون تراکنش دیتابیس...");
            
            DB::transaction(function () use ($discrepancies) {
                $updatedModels = [];

                foreach ($discrepancies as $d) {
                    /** @var Manuscript $model */
                    $model = $d['model'];
                    $model->folios = $d['detected_folios'];

                    $meta = $model->metadata ?? [];
                    if ($d['new_residual'] !== $d['old_residual']) {
                        $meta['residual_notes'] = $d['new_residual'];
                        $model->metadata = $meta;
                    }

                    $model->save();
                    $updatedModels[] = $model;
                }

                // Batch sync to Meilisearch
                if (method_exists(Manuscript::class, 'makeSearchable')) {
                    collect($updatedModels)->makeSearchable();
                }
            });

            $this->info("با موفقیت " . count($discrepancies) . " نسخه در دیتابیس و میلی‌سرچ اصلاح شدند.");
        }

        return Command::SUCCESS;
    }

    /**
     * Highly resilient and context-aware extractor for manuscript leaf count.
     */
    public static function extractTrueFolios(string $rawText): ?array
    {
        // Matches e.g. "295گ", "181گ ( 6پ-187ر)", "4 برگ"
        if (!preg_match_all('/(?:^|[؛،\s])(?<!\bدر\s)(?<!\bاز\s)(?<!\bبه\s)(?<!\bتا\s)(\d+)[\s]*(?:گ|برگ)(?!\w)(?:\s*\([^)]+\))?/u', $rawText, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $candidates = [];
        $len = mb_strlen($rawText);

        foreach ($matches[1] as $idx => $match) {
            $num = (int) $match[0];
            $byteOffset = $match[1];
            $charOffset = mb_strlen(substr($rawText, 0, $byteOffset));
            $fullMatch = trim($matches[0][$idx][0]);

            $postContext = mb_substr($rawText, $charOffset, 60);
            $preContext = mb_substr($rawText, max(0, $charOffset - 30), 30);

            // Guard against unrealistic book folios
            if ($num <= 0 || $num > 6000) {
                continue;
            }

            $score = 0;

            // Negative context: partial conditions, defects, or internal markers
            if (preg_match('/(?:نونویس|نو نویس|افتادگی|افتاده|ناقص|نانوشته|سفید|الحاق|اضافی|برگ\s*شمار)/u', $postContext)) {
                $score -= 100;
            }
            if (preg_match('/(?:از|تا|در|حدود|فقط|شامل|دارای)\s*$/u', $preContext)) {
                $score -= 50;
            }

            // Positive context: proximity to standard physical codicological elements
            if (preg_match('/سطر|سطور/u', $postContext)) {
                $score += 45;
            }
            if (preg_match('/اندازه|سم|\[ف:/u', $postContext)) {
                $score += 35;
            }
            if (preg_match('/(?:کاغذ|جلد):/u', $preContext)) {
                $score += 30;
            }
            if (str_contains($fullMatch, 'گ')) {
                $score += 15;
            }

            // Positional weighting: physical description is in the last 40% of the catalog entry
            $ratio = $charOffset / max(1, $len);
            $score += (int) ($ratio * 30);

            $candidates[] = [
                'num' => $num,
                'score' => $score,
                'full' => $fullMatch,
                'offset' => $charOffset,
            ];
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return $candidates[0];
    }
}
