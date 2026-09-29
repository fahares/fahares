<?php

namespace App\Console\Commands;

use App\Models\Language;
use App\Models\Subject;
use App\Models\Work;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemediateSubjectsCommand extends Command
{
    protected $signature = 'fahares:remediate-subjects {--sync-search : Sync updated works to Meilisearch}';

    protected $description = 'Comprehensive remediation of subjects: build taxonomy tree, split compound subjects, resolve typos, and detach leaked languages.';

    public function handle(): int
    {
        $this->info('Starting subjects taxonomy remediation and cleanup...');
        $syncSearch = (bool) $this->option('sync-search');
        $affectedWorkIds = [];

        DB::beginTransaction();

        try {
            // ========================================================
            // 1. RESOLVE PURE DASH AND GARBAGE SUBJECTS
            // ========================================================
            $this->info('--- 1. Detaching Pure Dash Subjects ---');
            $pureDashNames = ['-', 'ـ', '—'];
            foreach ($pureDashNames as $dName) {
                $subj = Subject::where('name', $dName)->first();
                if ($subj) {
                    $wIds = DB::table('subject_work')->where('subject_id', $subj->id)->pluck('work_id')->all();
                    DB::table('subject_work')->where('subject_id', $subj->id)->delete();
                    $subj->delete();
                    $affectedWorkIds = array_merge($affectedWorkIds, $wIds);
                    $this->line("Detached pure dash '{$dName}' from " . count($wIds) . " works.");
                }
            }

            // ========================================================
            // 2. RESOLVE LEAKED PURE LANGUAGES IN SUBJECTS
            // ========================================================
            $this->info('--- 2. Detaching Pure Languages from Subjects ---');
            $pureLangMap = [
                'عربی' => 'عربی',
                'فارسی' => 'فارسی',
                'اردو' => 'اردو',
                'پهلوی' => 'پهلوی',
                'اوستایی' => 'اوستایی',
                'فرانسوی' => 'فرانسوی',
                'فرانسه' => 'فرانسوی',
                'زبان فرانسه' => 'فرانسوی',
                '- اردو' => 'اردو',
                '– اردو' => 'اردو',
                '- پهلوی' => 'پهلوی',
                'ـ فارسی و عربی' => ['فارسی', 'عربی'],
                '— عربی' => 'عربی',
                'ـ ترکی' => 'ترکی',
            ];

            foreach ($pureLangMap as $subjName => $targetLangs) {
                $targetLangs = (array) $targetLangs;
                $subj = Subject::where('name', $subjName)->first();
                if ($subj) {
                    $wIds = DB::table('subject_work')->where('subject_id', $subj->id)->pluck('work_id')->all();
                    foreach ($wIds as $wId) {
                        foreach ($targetLangs as $tLangName) {
                            $langObj = $this->getOrCreateLanguage($tLangName);
                            $hasLang = DB::table('language_work')
                                ->where('work_id', $wId)
                                ->where('language_id', $langObj->id)
                                ->exists();
                            if (!$hasLang) {
                                DB::table('language_work')->insert([
                                    'work_id' => $wId,
                                    'language_id' => $langObj->id,
                                ]);
                            }
                        }
                        // Update work language summary
                        $work = Work::find($wId);
                        if ($work) {
                            $work->language_summary = $work->languages()->pluck('name')->join('، ');
                            $work->save();
                        }
                    }

                    DB::table('subject_work')->where('subject_id', $subj->id)->delete();
                    $subj->delete();
                    $affectedWorkIds = array_merge($affectedWorkIds, $wIds);
                    $this->line("Detached language '{$subjName}' from subjects and attached to " . count($wIds) . " works.");
                }
            }

            // ========================================================
            // 3. RESOLVE SUBJECT + LANGUAGE COMBINATIONS
            // ========================================================
            $this->info('--- 3. Resolving Subject + Language Combinations ---');
            $subjectLangMap = [
                'فقه عربی' => ['subject' => 'فقه', 'languages' => ['عربی']],
                'فقه – عربی' => ['subject' => 'فقه', 'languages' => ['عربی']],
                'فقه – فارسی' => ['subject' => 'فقه', 'languages' => ['فارسی']],
                'ادبیات فارسی' => ['subject' => 'ادبیات', 'languages' => ['فارسی']],
                'ادبیات: فارسی' => ['subject' => 'ادبیات', 'languages' => ['فارسی']],
                'شعر – عربی' => ['subject' => 'شعر', 'languages' => ['عربی']],
                'تراجم-اردو' => ['subject' => 'تراجم', 'languages' => ['اردو']],
                'تجوید عربی' => ['subject' => 'تجوید', 'languages' => ['عربی']],
                'هندسه . عربی' => ['subject' => 'هندسه', 'languages' => ['عربی']],
                'جغرافیا . فارسی' => ['subject' => 'جغرافیا', 'languages' => ['فارسی']],
                'فلسفه – عربی' => ['subject' => 'فلسفه', 'languages' => ['عربی']],
                'طب فارسی' => ['subject' => 'طب', 'languages' => ['فارسی']],
                'تاریخ ترکی' => ['subject' => 'تاریخ', 'languages' => ['ترکی']],
                'صنعت . عربی' => ['subject' => 'صنعت', 'languages' => ['عربی']],
                'عرفان و تصوف . فارسی' => ['subject' => 'عرفان و تصوف', 'languages' => ['فارسی']],
                'تاریخ معصومین - عربی - فارسی' => ['subject' => 'تاریخ معصومین', 'languages' => ['عربی', 'فارسی']],
                'لغت – عربی' => ['subject' => 'لغت', 'languages' => ['عربی']],
                'تاریخ ایران – فارسی' => ['subject' => 'تاریخ ایران', 'languages' => ['فارسی']],
                'لغت عربی به فارسی' => ['subject' => 'لغت', 'languages' => ['عربی', 'فارسی']],
                'اخلاق . فارسی' => ['subject' => 'اخلاق', 'languages' => ['فارسی']],
                'نامه‌نگاری فارسی' => ['subject' => 'نامه‌نگاری', 'languages' => ['فارسی']],
                'منطق . عربی' => ['subject' => 'منطق', 'languages' => ['عربی']],
                'حدیث - عربی - فارسی' => ['subject' => 'حدیث', 'languages' => ['عربی', 'فارسی']],
                'اسناد فارسی' => ['subject' => 'اسناد', 'languages' => ['فارسی']],
                'مواعظ . فارسی' => ['subject' => 'مواعظ', 'languages' => ['فارسی']],
                'حکومت و سیاست فارسی' => ['subject' => 'حکومت و سیاست', 'languages' => ['فارسی']],
                'تاریخ پادشاهان . فارسی و عربی' => ['subject' => 'تاریخ پادشاهان', 'languages' => ['فارسی', 'عربی']],
            ];

            foreach ($subjectLangMap as $origName => $meta) {
                $origSubj = Subject::where('name', $origName)->first();
                if ($origSubj) {
                    $canonSubj = $this->getOrCreateSubject($meta['subject']);
                    $wIds = DB::table('subject_work')->where('subject_id', $origSubj->id)->pluck('work_id')->all();

                    foreach ($wIds as $wId) {
                        // Attach canonical subject
                        $hasSubj = DB::table('subject_work')
                            ->where('work_id', $wId)
                            ->where('subject_id', $canonSubj->id)
                            ->exists();
                        if (!$hasSubj) {
                            DB::table('subject_work')->insert([
                                'work_id' => $wId,
                                'subject_id' => $canonSubj->id,
                            ]);
                        }

                        // Attach languages
                        foreach ($meta['languages'] as $lName) {
                            $langObj = $this->getOrCreateLanguage($lName);
                            $hasLang = DB::table('language_work')
                                ->where('work_id', $wId)
                                ->where('language_id', $langObj->id)
                                ->exists();
                            if (!$hasLang) {
                                DB::table('language_work')->insert([
                                    'work_id' => $wId,
                                    'language_id' => $langObj->id,
                                ]);
                            }
                        }

                        $work = Work::find($wId);
                        if ($work) {
                            $work->language_summary = $work->languages()->pluck('name')->join('، ');
                            $work->save();
                        }
                    }

                    DB::table('subject_work')->where('subject_id', $origSubj->id)->delete();
                    $origSubj->delete();
                    $affectedWorkIds = array_merge($affectedWorkIds, $wIds);
                    $this->line("Resolved combo '{$origName}' -> Subject: {$meta['subject']}");
                }
            }

            // ========================================================
            // 4. SPLIT COMPOUND SUBJECTS
            // ========================================================
            $this->info('--- 4. Splitting Compound Subjects ---');
            $compoundSplits = [
                'فلسفه و کلام' => ['فلسفه', 'کلام و اعتقادات'],
                'فقه و اصول' => ['فقه', 'اصول فقه'],
                'فقه و اصول فقه' => ['فقه', 'اصول فقه'],
                'صرف و نحو' => ['صرف', 'نحو'],
                'تاریخ و جغرافیا' => ['تاریخ', 'جغرافیا'],
                'دعا و فقه' => ['دعا', 'فقه'],
            ];

            foreach ($compoundSplits as $compoundName => $targetNames) {
                $compSubj = Subject::where('name', $compoundName)->first();
                if ($compSubj) {
                    $wIds = DB::table('subject_work')->where('subject_id', $compSubj->id)->pluck('work_id')->all();
                    foreach ($wIds as $wId) {
                        foreach ($targetNames as $tName) {
                            $targetSubj = $this->getOrCreateSubject($tName);
                            $hasLink = DB::table('subject_work')
                                ->where('work_id', $wId)
                                ->where('subject_id', $targetSubj->id)
                                ->exists();
                            if (!$hasLink) {
                                DB::table('subject_work')->insert([
                                    'work_id' => $wId,
                                    'subject_id' => $targetSubj->id,
                                ]);
                            }
                        }
                    }

                    DB::table('subject_work')->where('subject_id', $compSubj->id)->delete();
                    $compSubj->delete();
                    $affectedWorkIds = array_merge($affectedWorkIds, $wIds);
                    $this->line("Split compound '{$compoundName}' into: " . implode(' + ', $targetNames));
                }
            }

            // ========================================================
            // 5. MERGE TYPOS, SPACING & TRAILING HYPHENS INTO CANONICAL
            // ========================================================
            $this->info('--- 5. Merging Aliases, Typos & Trailing Hyphens ---');
            $aliasMap = [
                // Trailing hyphens
                'فقه -' => 'فقه',
                'پاسخ پرسشها -' => 'پاسخ پرسش‌ها',
                'کلام و اعتقادات -' => 'کلام و اعتقادات',
                'تاریخ -' => 'تاریخ',
                'علوم قرآن -' => 'علوم قرآن',
                'داستان -' => 'داستان',
                'فلسفه احکام -' => 'فلسفه احکام',
                'شعر -' => 'شعر',
                'عرفان و تصوف -' => 'عرفان و تصوف',
                'ریاضیات -' => 'ریاضیات',
                'متفرقه -' => 'متفرقه',
                'متفرقه-' => 'متفرقه',
                'خط -' => 'خط',
                'قرائت -' => 'قرائت',
                'موسیقی-' => 'موسیقی',
                'هیئت-' => 'هیئت',
                'تفسیر -' => 'تفسیر',
                'نامه‌نگاری -' => 'نامه‌نگاری',
                'حدیث -' => 'حدیث',
                'گوناگون -' => 'گوناگون',
                'کیمیا -' => 'کیمیا',
                'ادبیات -' => 'ادبیات',
                'تصویر -' => 'تصویر',
                'حیوان شناسی -' => 'حیوان‌شناسی',
                'سفرنامه -' => 'سفرنامه',
                'شرح حدیث -' => 'شرح حدیث',
                'درایه -' => 'درایه',
                'رمل -' => 'رمل',
                'اسناد -' => 'اسناد',
                'تاریخ معصومین -' => 'تاریخ معصومین',
                'جفر -' => 'جفر',
                'مقتل -' => 'مقتل',
                'انساب -' => 'انساب',

                // Typos & Orthography
                'داریه' => 'درایه',
                'علوم غریبیه' => 'علوم غریبه',
                'اخترینی' => 'اختربینی',
                'اختر بینی' => 'اختربینی',
                'كيمياء' => 'کیمیا',
                'صناعت' => 'صنعت',
                'اصطرلاب' => 'اسطرلاب',
                'اسطر لاب' => 'اسطرلاب',
                'پیشگوئی' => 'پیشگویی',
                'فضائل و مناقب' => 'فضایل و مناقب',
                'هیأت' => 'هیئت',
                'نامه نگاری' => 'نامه‌نگاری',
                'حیوان شناسی' => 'حیوان‌شناسی',
                'طالع بینی' => 'طالع‌بینی',
                'طالع نامه' => 'طالع‌بینی',
                'فالنامه' => 'فالگیری',
                'فالگیری' => 'فالگیری',
                'قیافه شناسی' => 'قیافه‌شناسی',
                'روان شناسی' => 'روان‌شناسی',
                'گیاه شناسی' => 'گیاه‌شناسی',
                'ستاره شناسی' => 'ستاره‌شناسی',
                'چند دانشی' => 'چنددانشی',
                'پاسخ پرسشها' => 'پاسخ پرسش‌ها',
                'پاسخ پرسش ها' => 'پاسخ پرسش‌ها',
                'دندان پزشکی' => 'دندان‌پزشکی',
                'اجازه' => 'اجازات',
                'ادعیه' => 'دعا',
                'عرفان' => 'عرفان و تصوف',
                'کلام' => 'کلام و اعتقادات',
                'سیاست و حکومت' => 'حکومت و سیاست',
                'سیاست' => 'حکومت و سیاست',
                'کلام و اعتقادات بهائی' => 'بهائیت',
                'علوم پایه' => 'علوم طبیعی',

                // Prophet variations
                'تاریخ پیامبراکرم(ص)' => 'تاریخ پیامبر اکرم (ص)',
                'تاریخ پیامبراکرم (ص)' => 'تاریخ پیامبر اکرم (ص)',
                'تاریخ پیامبر اکرم' => 'تاریخ پیامبر اکرم (ص)',
                'تاریخ پیامبر اکرم(ص)' => 'تاریخ پیامبر اکرم (ص)',
            ];

            foreach ($aliasMap as $alias => $canonical) {
                $source = Subject::where('name', $alias)->first();
                if (!$source) {
                    continue;
                }

                $target = $this->getOrCreateSubject($canonical);

                $wIds = DB::table('subject_work')->where('subject_id', $source->id)->pluck('work_id')->all();
                foreach ($wIds as $wId) {
                    $hasTarget = DB::table('subject_work')
                        ->where('work_id', $wId)
                        ->where('subject_id', $target->id)
                        ->exists();

                    if (!$hasTarget) {
                        DB::table('subject_work')
                            ->where('work_id', $wId)
                            ->where('subject_id', $source->id)
                            ->update(['subject_id' => $target->id]);
                    } else {
                        DB::table('subject_work')
                            ->where('work_id', $wId)
                            ->where('subject_id', $source->id)
                            ->delete();
                    }
                }

                $source->delete();
                $affectedWorkIds = array_merge($affectedWorkIds, $wIds);
                $this->line("Merged Subject '{$alias}' -> '{$canonical}' (" . count($wIds) . " works)");
            }

            // ========================================================
            // 6. BUILD HIERARCHICAL TAXONOMY TREE (parent_id)
            // ========================================================
            $this->info('--- 6. Setting Up Hierarchical Taxonomy Tree ---');
            $taxonomyTree = [
                'اصول فقه' => ['استصحاب', 'برائت', 'اصول عملیه', 'اجتهاد و تقلید'],
                'فقه' => ['فلسفه احکام'],
                'علوم قرآن' => ['تفسیر', 'تجوید', 'قرائت', 'فقه القرآن', 'محکم و متشابه', 'قصص قرآن', 'کتاب آسمانی'],
                'حدیث' => ['شرح حدیث', 'درایه', 'رجال', 'علوم حدیث', 'اجازات'],
                'کلام و اعتقادات' => ['امامت', 'پاسخ پرسش‌ها', 'ادیان و مذاهب', 'بهائیت', 'مسیحیت', 'آیین زرتشت'],
                'عرفان و تصوف' => ['اخلاق', 'سیر و سلوک', 'مواعظ', 'آداب و سنن'],
                'فلسفه' => ['منطق', 'طبیعیات'],
                'ادبیات' => ['شعر', 'داستان', 'صرف', 'نحو', 'لغت', 'دستور زبان', 'بلاغت', 'بدیع', 'معانی بیان', 'عروض و قافیه', 'نامه‌نگاری', 'انشاء', 'معما', 'طنز', 'فرهنگ اصطلاحات', 'تقریظ'],
                'تاریخ' => ['تاریخ معصومین', 'تاریخ پیامبر اکرم (ص)', 'تاریخ پیامبران', 'فضایل و مناقب', 'مقتل', 'مراثی', 'تاریخ ایران', 'تاریخ اسلام', 'تاریخ جهان', 'تاریخ پادشاهان', 'تاریخ هند', 'تاریخ فرانسه', 'تاریخ جنگ', 'تاریخ عمومی', 'تراجم', 'انساب', 'سفرنامه', 'جغرافیا'],
                'دعا' => ['شرح دعا', 'مناجات', 'زیارات', 'اسماء الله', 'استخاره'],
                'هیئت' => ['اسطرلاب', 'تقویم', 'ستاره‌شناسی'],
                'طب' => ['بهداشت', 'دندان‌پزشکی'],
                'علوم طبیعی' => ['حیوان‌شناسی', 'گیاه‌شناسی', 'زمین‌شناسی', 'کشاورزی', 'دامداری', 'فیزیک', 'شیمی'],
                'ریاضیات' => ['حساب', 'هندسه'],
                'علوم غریبه' => ['کیمیا', 'جفر', 'رمل', 'طالع‌بینی', 'اختربینی', 'خوابگزاری', 'قیافه‌شناسی', 'فالگیری', 'پیشگویی'],
                'هنر' => ['خط', 'موسیقی', 'مرقعات', 'تصویر', 'عکاسی', 'عکس'],
                'حکومت و سیاست' => ['قانون', 'حقوق', 'اقتصاد', 'فنون نظامی', 'جامعه‌شناسی', 'تعلیم و تربیت', 'روان‌شناسی'],
                'اسناد' => ['فهرست', 'روزنامه', 'مجله', 'دائرة المعارف', 'چنددانشی'],
            ];

            // Reset parent_id for all first
            DB::table('subjects')->update(['parent_id' => null]);

            foreach ($taxonomyTree as $parentName => $children) {
                $parentSubj = $this->getOrCreateSubject($parentName);
                foreach ($children as $childName) {
                    $childSubj = Subject::where('name', $childName)->first();
                    if ($childSubj && $childSubj->id !== $parentSubj->id) {
                        $childSubj->parent_id = $parentSubj->id;
                        $childSubj->save();
                        $this->line("Set '{$childName}' as child of '{$parentName}'");
                    }
                }
            }

            // ========================================================
            // 7. RECALCULATE WORKS_COUNT FOR ALL SUBJECTS & LANGUAGES
            // ========================================================
            $this->info('--- 7. Recalculating works_count (with cumulative parent counts) ---');
            foreach (Subject::all() as $s) {
                $s->works_count = DB::table('subject_work')->where('subject_id', $s->id)->count();
                $s->save();
            }

            // Calculate cumulative distinct works_count for parent subjects
            $parents = Subject::whereNull('parent_id')->with('children')->get();
            foreach ($parents as $p) {
                if ($p->children->isNotEmpty()) {
                    $allIds = array_merge([$p->id], $p->children->pluck('id')->all());
                    $p->works_count = DB::table('subject_work')
                        ->whereIn('subject_id', $allIds)
                        ->distinct('work_id')
                        ->count('work_id');
                    $p->save();
                    $this->line("Parent '{$p->name}' cumulative works_count: {$p->works_count}");
                }
            }

            foreach (Language::all() as $l) {
                $l->works_count = DB::table('language_work')->where('language_id', $l->id)->count();
                $l->save();
            }

            // Delete unused subjects (0 works and no children)
            $deletedCount = 0;
            foreach (Subject::where('works_count', 0)->get() as $s) {
                if ($s->children()->count() === 0) {
                    $s->delete();
                    $deletedCount++;
                }
            }
            $this->info("Pruned {$deletedCount} unused subject records.");

            DB::commit();
            $this->info('Database transaction committed successfully.');

            // ========================================================
            // 8. SYNC TO MEILISEARCH (OPTIONAL)
            // ========================================================
            if ($syncSearch) {
                $uniqueIds = array_values(array_unique($affectedWorkIds));
                $this->info("Re-indexing " . count($uniqueIds) . " affected works in Meilisearch...");
                foreach (array_chunk($uniqueIds, 100) as $chunk) {
                    Work::whereIn('id', $chunk)->searchable();
                }
                $this->info('Meilisearch re-indexing complete.');
            }

            $totalClean = Subject::count();
            $this->info("Subjects remediation completed successfully. Total clean subjects: {$totalClean}");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Failed to remediate subjects: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    protected function getOrCreateSubject(string $name): Subject
    {
        $existing = Subject::where('name', $name)->first();
        if ($existing) {
            return $existing;
        }

        $slug = \Illuminate\Support\Str::slug($name, '-', null) ?: \Illuminate\Support\Str::random(8);
        return Subject::create([
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    protected function getOrCreateLanguage(string $name): Language
    {
        $existing = Language::where('name', $name)->first();
        if ($existing) {
            return $existing;
        }

        $slug = \Illuminate\Support\Str::slug($name, '-', null) ?: \Illuminate\Support\Str::random(8);
        return Language::create([
            'name' => $name,
            'slug' => $slug,
        ]);
    }
}
