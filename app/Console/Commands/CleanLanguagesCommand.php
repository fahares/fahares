<?php

namespace App\Console\Commands;

use App\Models\Language;
use App\Models\Subject;
use App\Models\Work;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanLanguagesCommand extends Command
{
    protected $signature = 'fahares:clean-languages {--sync-search : Sync updated works to Meilisearch}';

    protected $description = 'Clean and unify language variants (French, Arabic, Persian), fix leaked subjects, and attach dual languages.';

    public function handle(): int
    {
        $this->info('Starting languages remediation...');

        $syncSearch = (bool) $this->option('sync-search');
        $affectedWorkIds = [];

        DB::beginTransaction();

        try {
            // ==========================================
            // 1. UNIFY LANGUAGE VARIANTS
            // ==========================================
            $this->info('--- Merging Language Variants ---');
            $variantMap = [
                'فرانسه' => 'فرانسوی',
                'عرب' => 'عربی',
                'عربى' => 'عربی',
                'فارسى' => 'فارسی',
            ];

            foreach ($variantMap as $alias => $canonical) {
                $source = Language::where('name', $alias)->first();
                $target = Language::where('name', $canonical)->first();

                if (!$source) {
                    continue;
                }

                if (!$target) {
                    $this->warn("Canonical language '{$canonical}' not found for alias '{$alias}'!");
                    continue;
                }

                $this->line("Merging Language ID {$source->id} [{$source->name}] -> ID {$target->id} [{$target->name}]");

                $workIds = DB::table('language_work')->where('language_id', $source->id)->pluck('work_id')->all();

                foreach ($workIds as $wId) {
                    $hasTarget = DB::table('language_work')
                        ->where('work_id', $wId)
                        ->where('language_id', $target->id)
                        ->exists();

                    if (!$hasTarget) {
                        DB::table('language_work')
                            ->where('work_id', $wId)
                            ->where('language_id', $source->id)
                            ->update(['language_id' => $target->id]);
                    } else {
                        DB::table('language_work')
                            ->where('work_id', $wId)
                            ->where('language_id', $source->id)
                            ->delete();
                    }

                    // Update language_summary on work
                    $work = Work::find($wId);
                    if ($work) {
                        $work->language_summary = $work->languages()->pluck('name')->join('، ');
                        $work->save();
                    }

                    $affectedWorkIds[] = $wId;
                }

                $source->delete();
                $this->info("Deleted redundant language record: {$alias} (ID {$source->id})");
            }

            // ==========================================
            // 2. FIX WORK 9107 ("اولاد البنات") & "فقه" LANGUAGE LEAK
            // ==========================================
            $this->info('--- Remediating Work 9107 (اولاد البنات) ---');
            $feqhLang = Language::where('name', 'فقه')->first();
            $arabicLang = Language::where('name', 'عربی')->first();
            $feqhSubject = Subject::firstOrCreate(['name' => 'فقه']);

            $work9107 = Work::find(9107);
            if ($work9107) {
                // Detach feqh from languages
                if ($feqhLang) {
                    DB::table('language_work')
                        ->where('work_id', $work9107->id)
                        ->where('language_id', $feqhLang->id)
                        ->delete();
                }

                // Attach arabic to languages
                if ($arabicLang) {
                    $hasArabic = DB::table('language_work')
                        ->where('work_id', $work9107->id)
                        ->where('language_id', $arabicLang->id)
                        ->exists();

                    if (!$hasArabic) {
                        DB::table('language_work')->insert([
                            'work_id' => $work9107->id,
                            'language_id' => $arabicLang->id,
                        ]);
                    }
                }

                // Ensure subject 'فقه' is attached
                $hasFeqhSubj = DB::table('subject_work')
                    ->where('work_id', $work9107->id)
                    ->where('subject_id', $feqhSubject->id)
                    ->exists();

                if (!$hasFeqhSubj) {
                    DB::table('subject_work')->insert([
                        'work_id' => $work9107->id,
                        'subject_id' => $feqhSubject->id,
                    ]);
                }

                // Remove '؟' subject if attached
                $qSubj = Subject::where('name', '؟')->orWhere('name', '?')->first();
                if ($qSubj) {
                    DB::table('subject_work')
                        ->where('work_id', $work9107->id)
                        ->where('subject_id', $qSubj->id)
                        ->delete();
                }

                $work9107->language_summary = 'عربی';
                $work9107->subject_summary = 'فقه';
                $work9107->save();

                $affectedWorkIds[] = $work9107->id;
                $this->info("Work 9107 updated: Subject -> فقه, Language -> عربی");
            }

            // Delete 'فقه' from languages table if orphaned
            if ($feqhLang) {
                $feqhWorksRemaining = DB::table('language_work')->where('language_id', $feqhLang->id)->count();
                if ($feqhWorksRemaining === 0) {
                    $feqhLang->delete();
                    $this->info("Deleted orphaned language record: فقه (ID {$feqhLang->id})");
                }
            }

            // Delete '؟' from subjects table if orphaned
            if (isset($qSubj) && $qSubj) {
                $qRemaining = DB::table('subject_work')->where('subject_id', $qSubj->id)->count();
                if ($qRemaining === 0) {
                    $qSubj->delete();
                    $this->info("Deleted orphaned subject record: '؟'");
                }
            }

            // ==========================================
            // 3. ATTACH DUAL LANGUAGES TO 13 MULTI-LANGUAGE WORKS
            // ==========================================
            $this->info('--- Ensuring Dual Languages on Multi-Language Works ---');
            $farsiLang = Language::where('name', 'فارسی')->first();

            $dualLangWorkIds = [
                8886,  // انیس المتقین (vol 5)
                12573, // تجوید (vol 7)
                34376, // زبدة الاخبار (vol 17)
                35775, // سر المکنون (vol 18)
                45191, // عبقات الانوار (vol 22)
                51247, // قصه دیک الجن (vol 25)
                51481, // القصيدة السريانية (vol 25)
                51501, // القصيدة العشقية (vol 25)
                54687, // کیفیت زیارت عاشورا (vol 26)
                56242, // مائة کلمة (vol 27)
                56312, // ماده تاریخ الافق المبين (vol 27)
                57415, // مجموعة الادب (vol 28)
                64544, // منتخباتی از احادیث (vol 31)
            ];

            if ($arabicLang && $farsiLang) {
                foreach ($dualLangWorkIds as $wId) {
                    $work = Work::find($wId);
                    if (!$work) {
                        continue;
                    }

                    foreach ([$arabicLang->id, $farsiLang->id] as $lId) {
                        $hasL = DB::table('language_work')
                            ->where('work_id', $work->id)
                            ->where('language_id', $lId)
                            ->exists();

                        if (!$hasL) {
                            DB::table('language_work')->insert([
                                'work_id' => $work->id,
                                'language_id' => $lId,
                            ]);
                        }
                    }

                    $work->language_summary = 'عربی، فارسی';
                    $work->save();
                    $affectedWorkIds[] = $work->id;
                    $this->line("Attached Arabic + Persian to Work ID {$work->id} [{$work->primary_title}]");
                }
            }

            // ==========================================
            // 4. RECALCULATE WORKS_COUNT
            // ==========================================
            $this->info('--- Recalculating works_count for all languages & subjects ---');
            foreach (Language::all() as $l) {
                $l->works_count = DB::table('language_work')->where('language_id', $l->id)->count();
                $l->save();
            }

            if ($feqhSubject) {
                $feqhSubject->works_count = DB::table('subject_work')->where('subject_id', $feqhSubject->id)->count();
                $feqhSubject->save();
            }

            DB::commit();
            $this->info('Database transaction committed successfully.');

            // ==========================================
            // 5. SYNC TO MEILISEARCH
            // ==========================================
            if ($syncSearch) {
                $uniqueIds = array_values(array_unique($affectedWorkIds));
                $this->info("Re-indexing " . count($uniqueIds) . " affected works in Meilisearch...");
                foreach (array_chunk($uniqueIds, 50) as $chunk) {
                    Work::whereIn('id', $chunk)->searchable();
                }
                $this->info('Meilisearch re-indexing complete.');
            }

            $this->info('Languages remediation completed successfully.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Failed to clean languages: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}
