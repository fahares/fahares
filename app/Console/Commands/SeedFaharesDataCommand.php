<?php

namespace App\Console\Commands;

use App\Models\Language;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Referral;
use App\Models\Script;
use App\Models\Subject;
use App\Models\Work;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeedFaharesDataCommand extends Command
{
    protected $signature = 'fahares:seed 
                            {--volume= : Specific volume number to seed (1-34)}
                            {--fresh : Wipe existing data before seeding}
                            {--limit= : Limit number of works to process (for rapid testing)}';

    protected $description = 'High-performance seeding of Fahares structured JSON corpus into MariaDB';

    // In-memory dictionaries for fast ID resolution
    protected array $librariesCache = [];
    protected array $subjectsCache = [];
    protected array $languagesCache = [];
    protected array $scriptsCache = [];
    protected array $peopleCache = [];

    public function handle(): int
    {
        ini_set('memory_limit', '-1');
        $startTime = microtime(true);
        $volumeOption = $this->option('volume');
        $fresh = $this->option('fresh');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        if ($fresh) {
            $this->warn('Wiping existing Fahares tables...');
            $this->wipeTables();
        }

        // Preload existing taxonomies into memory
        $this->preloadCaches();

        $volumes = $volumeOption ? [(int) $volumeOption] : range(1, 34);

        $this->info(sprintf('Starting Fahares database ingestion for %d volume(s)...', count($volumes)));

        // Optimize database for bulk insertion
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::statement('SET UNIQUE_CHECKS=0;');

        $totalWorks = 0;
        $totalManuscripts = 0;
        $totalReferrals = 0;

        foreach ($volumes as $volNum) {
            $volStr = str_pad((string) $volNum, 2, '0', STR_PAD_LEFT);
            $jsonPath = base_path("sources/json/fahares_vol_{$volStr}.json");

            if (!file_exists($jsonPath)) {
                $this->error("JSON file not found: {$jsonPath}");
                continue;
            }

            $volStart = microtime(true);
            $this->info("--> Processing Volume {$volNum} ({$jsonPath})...");

            $raw = file_get_contents($jsonPath);
            $data = json_decode($raw, true);

            if (!$data) {
                $this->error("Invalid JSON in {$jsonPath}");
                continue;
            }

            $worksData = $data['works'] ?? [];
            if ($limit && $limit > 0) {
                $worksData = array_slice($worksData, 0, $limit);
            }

            $referralsData = $data['referrals'] ?? [];

            DB::beginTransaction();
            try {
                // 1. Ingest Works & Manuscripts
                $counts = $this->ingestWorksAndManuscripts($volNum, $worksData);
                $totalWorks += $counts['works'];
                $totalManuscripts += $counts['manuscripts'];

                // 2. Ingest Referrals
                $refCount = $this->ingestReferrals($volNum, $referralsData);
                $totalReferrals += $refCount;

                DB::commit();

                $volDuration = round(microtime(true) - $volStart, 2);
                $this->info(sprintf(
                    '    [DONE] Volume %d in %s sec | Works: %s | Manuscripts: %s | Referrals: %s',
                    $volNum,
                    $volDuration,
                    number_format($counts['works']),
                    number_format($counts['manuscripts']),
                    number_format($refCount)
                ));
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->error("Failed processing Volume {$volNum}: " . $e->getMessage());
                $this->error($e->getTraceAsString());
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
                DB::statement('SET UNIQUE_CHECKS=1;');
                return 1;
            }
        }

        // Restore database safety checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        DB::statement('SET UNIQUE_CHECKS=1;');

        // Update taxonomy counters
        $this->updateTaxonomyCounts();

        $totalDuration = round(microtime(true) - $startTime, 2);
        $this->newLine();
        $this->info('====================================================');
        $this->info(sprintf('SUCCESS! Total Ingestion Duration: %s sec', $totalDuration));
        $this->info(sprintf('Total Works Seeded:       %s', number_format($totalWorks)));
        $this->info(sprintf('Total Manuscripts Seeded: %s', number_format($totalManuscripts)));
        $this->info(sprintf('Total Referrals Seeded:   %s', number_format($totalReferrals)));
        $this->info(sprintf('Total Unique People:      %s', number_format(count($this->peopleCache))));
        $this->info(sprintf('Total Libraries:          %s', number_format(count($this->librariesCache))));
        $this->info('====================================================');

        return 0;
    }

    protected function wipeTables(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('manuscript_script')->truncate();
        DB::table('subject_work')->truncate();
        DB::table('language_work')->truncate();
        DB::table('manuscripts')->truncate();
        DB::table('referrals')->truncate();
        DB::table('works')->truncate();
        DB::table('people')->truncate();
        DB::table('libraries')->truncate();
        DB::table('subjects')->truncate();
        DB::table('languages')->truncate();
        DB::table('scripts')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->librariesCache = [];
        $this->subjectsCache = [];
        $this->languagesCache = [];
        $this->scriptsCache = [];
        $this->peopleCache = [];
    }

    protected function preloadCaches(): void
    {
        foreach (Library::all(['id', 'city', 'name']) as $l) {
            $this->librariesCache[$this->normalizePersianText($l->city) . '||' . $this->normalizePersianText($l->name)] = $l->id;
        }
        foreach (Subject::all(['id', 'name']) as $s) {
            $this->subjectsCache[$this->normalizePersianText($s->name)] = $s->id;
        }
        foreach (Language::all(['id', 'name']) as $lan) {
            $this->languagesCache[$this->normalizePersianText($lan->name)] = $lan->id;
        }
        foreach (Script::all(['id', 'name']) as $sc) {
            $this->scriptsCache[$this->normalizePersianText($sc->name)] = $sc->id;
        }
        foreach (Person::all(['id', 'normalized_name']) as $p) {
            $this->peopleCache[$p->normalized_name] = $p->id;
        }
    }

    protected function ingestWorksAndManuscripts(int $volNum, array $works): array
    {
        $worksCount = 0;
        $msCount = 0;

        $subjectPivot = [];
        $languagePivot = [];
        $now = now()->toDateTimeString();

        foreach ($works as $w) {
            // 1. Resolve Author
            $authorId = null;
            $authorName = $w['author_name'] ?? null;
            if ($authorName && $this->isQualifiedAuthor($w)) {
                $authorId = $this->resolvePerson($authorName, $w, true);
            }

            // 2. Prepare Structured Alternative Titles
            $altTitles = [];
            $rawAlts = $w['alternative_titles'] ?? [];
            $rawAltTrans = $w['alternative_transliterations'] ?? [];
            foreach ($rawAlts as $idx => $altTitle) {
                $altTitles[] = [
                    'title' => $altTitle,
                    'transliteration' => $rawAltTrans[$idx] ?? null,
                ];
            }

            // 3. Resolve Work Page Span
            $manuscripts = $w['manuscripts'] ?? [];
            $pageStart = (int) ($w['page_start'] ?? 0);
            $pageEnd = (int) ($w['page_end'] ?? 0);
            if ($pageEnd === 0 && count($manuscripts) > 0) {
                $lastMs = end($manuscripts);
                $pageEnd = (int) ($lastMs['page_end'] ?? $pageStart);
            }
            if ($pageEnd === 0) {
                $pageEnd = $pageStart;
            }

            // 4. Create Work Record
            $slugBase = $w['transliteration'] ?? $w['clean_title'] ?? $w['primary_title'] ?? 'work';
            $slug = Str::slug($slugBase);
            if (empty($slug)) {
                $slug = 'work';
            }
            $slug = mb_substr($slug, 0, 180) . '-v' . $volNum . '-' . ($worksCount + 1);

            $workId = DB::table('works')->insertGetId([
                'author_id' => $authorId,
                'author_name' => !empty($authorName) ? mb_substr($authorName, 0, 500) : null,
                'primary_title' => mb_substr($w['primary_title'] ?? 'بدون عنوان', 0, 255),
                'clean_title' => mb_substr($w['clean_title'] ?? $w['primary_title'] ?? 'بدون عنوان', 0, 255),
                'slug' => $slug,
                'alternative_titles' => !empty($altTitles) ? json_encode($altTitles, JSON_UNESCAPED_UNICODE) : null,
                'transliteration' => $w['transliteration'] ?? null,
                'composition_date_raw' => $w['composition_date'] ?? null,
                'composition_year_hijri' => $this->parseHijriYear($w['composition_date'] ?? null),
                'volume_number' => $volNum,
                'page_start' => $pageStart,
                'page_end' => $pageEnd,
                'work_form' => $w['work_form'] ?? null,
                'subject_summary' => $w['subject'] ?? null,
                'language_summary' => $w['language'] ?? null,
                'incipit_text' => $w['incipit'] ?? null,
                'explicit_text' => $w['explicit'] ?? null,
                'description' => $w['description'] ?? null,
                'manuscripts_count' => count($manuscripts),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $worksCount++;

            // 5. Collect Taxonomy Pivots
            foreach ($w['subjects'] ?? [] as $subName) {
                $subId = $this->resolveSubject($subName);
                if ($subId) {
                    $subjectPivot[] = ['subject_id' => $subId, 'work_id' => $workId];
                }
            }
            foreach ($w['languages'] ?? [] as $langName) {
                $langId = $this->resolveLanguage($langName);
                if ($langId) {
                    $languagePivot[] = ['language_id' => $langId, 'work_id' => $workId];
                }
            }

            // 6. Ingest Manuscripts for this Work
            if (!empty($manuscripts)) {
                $msCount += $this->ingestWorkManuscripts($workId, $volNum, $manuscripts, $now);
            }
        }

        // Batch insert taxonomy pivots
        if (!empty($subjectPivot)) {
            DB::table('subject_work')->insertOrIgnore($subjectPivot);
        }
        if (!empty($languagePivot)) {
            DB::table('language_work')->insertOrIgnore($languagePivot);
        }

        return ['works' => $worksCount, 'manuscripts' => $msCount];
    }

    protected function ingestWorkManuscripts(int $workId, int $volNum, array $manuscripts, string $now): int
    {
        $msRows = [];
        $scriptPivots = [];
        $msInsertedCount = 0;

        foreach ($manuscripts as $idx => $m) {
            // Resolve Library
            $city = $m['city'] ?? null;
            $lib = $m['library'] ?? null;
            $libId = null;
            if ($city && $lib) {
                $libId = $this->resolveLibrary($city, $lib);
            }

            // Resolve Scribe
            $scribeId = null;
            $scribeName = $m['scribe_name'] ?? null;
            if ($scribeName && !($m['is_bika'] ?? false) && $this->isQualifiedScribe($scribeName)) {
                $scribeId = $this->resolvePerson($scribeName, [], false);
            }

            // Script String & Quality
            $scriptStr = null;
            $scriptList = $m['scripts'] ?? [];
            if (!empty($scriptList)) {
                $scriptStr = implode('، ', $scriptList);
            } elseif (!empty($m['script'])) {
                $scriptStr = $m['script'];
            }

            $styleStr = null;
            $styleList = $m['script_styles'] ?? [];
            if (!empty($styleList)) {
                $styleStr = implode('، ', $styleList);
            }

            // Dense Metadata Payload
            $metadata = [
                'incipits' => $m['incipits'] ?? [],
                'explicits' => $m['explicits'] ?? [],
                'editorial_notes' => $m['editorial_notes'] ?? [],
                'ownership_and_seals' => $m['ownership_and_seals'] ?? [],
                'catalog_citation' => $m['catalog_citation'] ?? null,
                'residual_notes' => $m['residual_notes'] ?? null,
            ];

            $copyYear = (int) ($m['copy_date_sort_year'] ?? 0);
            if ($copyYear === 0) {
                $copyYear = $this->parseHijriYear($m['copy_date_raw'] ?? null);
            }

            $msId = DB::table('manuscripts')->insertGetId([
                'work_id' => $workId,
                'library_id' => $libId,
                'scribe_id' => $scribeId,
                'sequence_number' => (int) ($m['sequence_number'] ?? ($idx + 1)),
                'volume_number' => $volNum,
                'page_start' => (int) ($m['page_start'] ?? 0),
                'page_end' => (int) ($m['page_end'] ?? 0),
                'city' => !empty($city) ? mb_substr($city, 0, 255) : null,
                'library' => !empty($lib) ? mb_substr($lib, 0, 255) : null,
                'shelfmark' => $m['shelfmark'] ?? null,
                'shelfmark_key' => !empty($m['shelfmark']) ? mb_substr($m['shelfmark'], 0, 100) : null,
                'scribe_name' => !empty($scribeName) ? mb_substr($scribeName, 0, 600) : null,
                'is_bika' => (bool) ($m['is_bika'] ?? false),
                'is_bita' => (bool) ($m['is_bita'] ?? false),
                'is_autograph' => (bool) ($m['is_autograph'] ?? false),
                'copy_date_raw' => $m['copy_date_raw'] ?? null,
                'copy_date_hijri_year' => $copyYear ?: null,
                'copy_place' => $m['copy_place'] ?? null,
                'script_names' => $scriptStr,
                'script_style' => $styleStr,
                'folios' => !empty($m['folios']) ? (int) $m['folios'] : null,
                'lines' => !empty($m['lines']) ? (int) $m['lines'] : null,
                'dimensions' => $m['dimensions'] ?? null,
                'paper' => $m['paper'] ?? null,
                'binding' => $m['binding'] ?? null,
                'incipit_text' => $m['incipit_text'] ?? null,
                'explicit_text' => $m['explicit_text'] ?? null,
                'is_corrected' => (bool) ($m['is_corrected'] ?? false),
                'has_marginal_notes' => (bool) ($m['has_marginal_notes'] ?? false),
                'is_ruled' => (bool) ($m['is_ruled'] ?? false),
                'has_catchwords' => (bool) ($m['has_catchwords'] ?? false),
                'is_facsimile' => (bool) ($m['is_facsimile'] ?? false),
                'is_collated' => (bool) ($m['is_collated'] ?? false),
                'is_illuminated' => (bool) ($m['is_illuminated'] ?? false),
                'is_illustrated' => (bool) ($m['is_illustrated'] ?? false),
                'has_author_marginalia' => (bool) ($m['has_author_marginalia'] ?? false),
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                'raw_text' => $m['raw_text'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $msInsertedCount++;

            // Collect Script Pivots
            foreach ($scriptList as $sIndex => $sName) {
                $scriptId = $this->resolveScript($sName);
                if ($scriptId) {
                    $scriptPivots[] = [
                        'manuscript_id' => $msId,
                        'script_id' => $scriptId,
                        'is_primary' => ($sIndex === 0),
                    ];
                }
            }
        }

        if (!empty($scriptPivots)) {
            DB::table('manuscript_script')->insertOrIgnore($scriptPivots);
        }

        return $msInsertedCount;
    }

    protected function ingestReferrals(int $volNum, array $referrals): int
    {
        if (empty($referrals)) {
            return 0;
        }

        $now = now()->toDateTimeString();
        $batch = [];

        foreach ($referrals as $ref) {
            $batch[] = [
                'source_title' => $ref['source_title'],
                'target_title' => $ref['target_title'],
                'target_work_id' => null,
                'volume_number' => $volNum,
                'page' => (int) ($ref['page'] ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 1000) {
                DB::table('referrals')->insert($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            DB::table('referrals')->insert($batch);
        }

        return count($referrals);
    }

    // --- Authority Disambiguation & Resolution Helpers ---

    protected function isQualifiedAuthor(array $work): bool
    {
        $name = trim($work['author_name'] ?? '');
        if (empty($name)) return false;

        // Disqualify generic / unknown placeholders
        if (in_array($name, ['ناشناس', 'مجهول', 'بی‌نام', 'مؤلف نامعلوم'])) {
            return false;
        }

        // Qualified if has explicit death date / century
        if (!empty($work['author_death_date_sort_year']) || !empty($work['author_death_date_century'])) {
            return true;
        }

        // Qualified if compound name (3+ words or contains patronymic / title)
        $words = preg_split('/\s+/', $name);
        if (count($words) >= 3 || str_contains($name, ' بن ') || str_contains($name, 'الدین') || str_contains($name, 'شیخ') || str_contains($name, 'میرزا')) {
            return true;
        }

        return false;
    }

    protected function isQualifiedScribe(string $name): bool
    {
        $name = trim($name);
        if (empty($name) || in_array($name, ['بی‌کا', 'ناشناس', 'مجهول'])) {
            return false;
        }

        $words = preg_split('/\s+/', $name);
        // Scribe must have 3+ words or clear patronymic (بن) or regional/familial suffix
        if (count($words) >= 3 || str_contains($name, ' بن ') || str_contains($name, 'الدین')) {
            return true;
        }

        return false;
    }

    protected function resolvePerson(string $name, array $workData, bool $isAuthor): int
    {
        $norm = $this->normalizePersianText($name);
        if (isset($this->peopleCache[$norm])) {
            return $this->peopleCache[$norm];
        }

        $now = now()->toDateTimeString();
        $slugBase = Str::slug($workData['author_transliteration'] ?? $norm);
        if (empty($slugBase)) {
            $slugBase = 'person';
        }
        $slug = mb_substr($slugBase, 0, 180) . '-' . (count($this->peopleCache) + 1);

        $id = DB::table('people')->insertGetId([
            'name' => mb_substr($name, 0, 600),
            'normalized_name' => mb_substr($norm, 0, 600),
            'slug' => $slug,
            'death_year_hijri' => !empty($workData['author_death_date_sort_year']) ? (int) $workData['author_death_date_sort_year'] : null,
            'century_hijri' => !empty($workData['author_death_date_century']) ? (int) $workData['author_death_date_century'] : null,
            'death_year_gregorian' => !empty($workData['author_death_date_gregorian_calculated']) ? (int) $workData['author_death_date_gregorian_calculated'] : null,
            'transliteration' => $workData['author_transliteration'] ?? null,
            'is_author' => $isAuthor,
            'is_scribe' => !$isAuthor,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->peopleCache[$norm] = $id;
        return $id;
    }

    protected function resolveLibrary(string $city, string $name): int
    {
        $cityNorm = $this->normalizePersianText($city);
        $nameNorm = $this->normalizePersianText($name);
        $key = $cityNorm . '||' . $nameNorm;

        if (isset($this->librariesCache[$key])) {
            return $this->librariesCache[$key];
        }

        // Check if MariaDB already has this library under its collation rules
        $existingId = DB::table('libraries')
            ->where('city', $city)
            ->where('name', $name)
            ->value('id');

        if ($existingId) {
            $this->librariesCache[$key] = $existingId;
            return $existingId;
        }

        $now = now()->toDateTimeString();
        $slugBase = Str::slug($cityNorm . '-' . $nameNorm);
        if (empty($slugBase)) {
            $slugBase = 'lib';
        }
        $slug = mb_substr($slugBase, 0, 180) . '-' . (count($this->librariesCache) + 1);

        try {
            $id = DB::table('libraries')->insertGetId([
                'city' => mb_substr($city, 0, 255),
                'name' => mb_substr($name, 0, 255),
                'full_name' => "کتابخانه {$name} ({$city})",
                'country' => 'ایران',
                'slug' => $slug,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            $existing = DB::table('libraries')->where('city', $city)->where('name', $name)->first();
            if ($existing) {
                $id = $existing->id;
            } else {
                throw $e;
            }
        }

        $this->librariesCache[$key] = $id;
        return $id;
    }

    protected function resolveSubject(string $name): ?int
    {
        $name = trim($name);
        if (empty($name)) return null;

        $norm = $this->normalizePersianText($name);
        if (isset($this->subjectsCache[$norm])) {
            return $this->subjectsCache[$norm];
        }

        // Check if MariaDB already has this subject under its collation rules
        $existingId = DB::table('subjects')->where('name', $name)->value('id');
        if ($existingId) {
            $this->subjectsCache[$norm] = $existingId;
            return $existingId;
        }

        $now = now()->toDateTimeString();
        $slugBase = Str::slug($norm);
        if (empty($slugBase)) {
            $slugBase = 'subject';
        }
        $slug = mb_substr($slugBase, 0, 180) . '-' . (count($this->subjectsCache) + 1);

        try {
            $id = DB::table('subjects')->insertGetId([
                'name' => mb_substr($name, 0, 255),
                'slug' => $slug,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            $existing = DB::table('subjects')->where('name', $name)->first();
            if ($existing) {
                $id = $existing->id;
            } else {
                throw $e;
            }
        }

        $this->subjectsCache[$norm] = $id;
        return $id;
    }

    protected function resolveLanguage(string $name): ?int
    {
        $name = trim($name);
        if (empty($name)) return null;

        $norm = $this->normalizePersianText($name);
        if (isset($this->languagesCache[$norm])) {
            return $this->languagesCache[$norm];
        }

        // Check if MariaDB already has this language under its collation rules
        $existingId = DB::table('languages')->where('name', $name)->value('id');
        if ($existingId) {
            $this->languagesCache[$norm] = $existingId;
            return $existingId;
        }

        $now = now()->toDateTimeString();
        $slugBase = Str::slug($norm);
        if (empty($slugBase)) {
            $slugBase = 'lang';
        }
        $slug = mb_substr($slugBase, 0, 180) . '-' . (count($this->languagesCache) + 1);

        $codeMap = [
            'فارسی' => 'fa',
            'عربی' => 'ar',
            'ترکی' => 'tr',
            'اردو' => 'ur',
            'فرانسوی' => 'fr',
            'انگلیسی' => 'en',
            'عبری' => 'he',
        ];

        try {
            $id = DB::table('languages')->insertGetId([
                'name' => mb_substr($name, 0, 255),
                'code' => $codeMap[$name] ?? null,
                'slug' => $slug,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            $existing = DB::table('languages')->where('name', $name)->first();
            if ($existing) {
                $id = $existing->id;
            } else {
                throw $e;
            }
        }

        $this->languagesCache[$norm] = $id;
        return $id;
    }

    protected function resolveScript(string $name): ?int
    {
        $name = trim($name);
        if (empty($name)) return null;

        // If composite note erroneously parsed as script (e.g., "نستعلیق، کا: ..."), extract actual script name
        if (mb_strlen($name) > 40 && str_contains($name, '،')) {
            $parts = explode('،', $name);
            $candidate = trim($parts[0]);
            if (mb_strlen($candidate) <= 30) {
                $name = $candidate;
            }
        }
        $name = mb_substr($name, 0, 255);

        $norm = $this->normalizePersianText($name);
        if (isset($this->scriptsCache[$norm])) {
            return $this->scriptsCache[$norm];
        }

        // Check if MariaDB already has this script under its collation rules
        $existingId = DB::table('scripts')->where('name', $name)->value('id');
        if ($existingId) {
            $this->scriptsCache[$norm] = $existingId;
            return $existingId;
        }

        $now = now()->toDateTimeString();
        $slugBase = Str::slug($norm);
        if (empty($slugBase)) {
            $slugBase = 'script';
        }
        $slug = mb_substr($slugBase, 0, 180) . '-' . (count($this->scriptsCache) + 1);

        try {
            $id = DB::table('scripts')->insertGetId([
                'name' => $name,
                'slug' => $slug,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            $existing = DB::table('scripts')->where('name', $name)->first();
            if ($existing) {
                $id = $existing->id;
            } else {
                throw $e;
            }
        }

        $this->scriptsCache[$norm] = $id;
        return $id;
    }

    protected function updateTaxonomyCounts(): void
    {
        $this->info('Updating cached counts for taxonomies...');

        DB::statement('
            UPDATE subjects s
            SET works_count = (SELECT COUNT(*) FROM subject_work sw WHERE sw.subject_id = s.id)
        ');

        DB::statement('
            UPDATE languages l
            SET works_count = (SELECT COUNT(*) FROM language_work lw WHERE lw.language_id = l.id)
        ');

        DB::statement('
            UPDATE scripts sc
            SET manuscripts_count = (SELECT COUNT(*) FROM manuscript_script ms WHERE ms.script_id = sc.id)
        ');

        DB::statement('
            UPDATE libraries lib
            SET manuscripts_count = (SELECT COUNT(*) FROM manuscripts m WHERE m.library_id = lib.id)
        ');

        DB::statement('
            UPDATE people p
            SET works_count = (SELECT COUNT(*) FROM works w WHERE w.author_id = p.id),
                manuscripts_count = (SELECT COUNT(*) FROM manuscripts m WHERE m.scribe_id = p.id)
        ');
    }

    protected function parseHijriYear(?string $raw): ?int
    {
        if (empty($raw)) return null;
        if (preg_match('/(\d{3,4})/', $raw, $matches)) {
            $year = (int) $matches[1];
            if ($year >= 100 && $year <= 1500) {
                return $year;
            }
        }
        return null;
    }

    protected function normalizePersianText(string $text): string
    {
        // Replace Arabic forms with Persian standards, strip ZWNJ and ZWJ
        $text = str_replace(['ي', 'ك', 'ة', "\u{200c}", "\u{200d}"], ['ی', 'ک', 'ه', '', ''], $text);
        // Strip Arabic diacritics / tashkeel and tatweel
        $text = preg_replace('/[ًٌٍَُِّْـ]/u', '', $text);
        // Collapse whitespace
        return trim(preg_replace('/\s+/', ' ', $text));
    }
}
