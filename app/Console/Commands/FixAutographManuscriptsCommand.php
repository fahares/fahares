<?php

namespace App\Console\Commands;

use App\Models\Manuscript;
use App\Models\Person;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixAutographManuscriptsCommand extends Command
{
    protected $signature = 'fahares:fix-autograph-manuscripts {--sync-search : Sync updated records to Meilisearch}';

    protected $description = 'Clean up false positive autograph manuscripts (collations, margins, transcriptions, external scribes)';

    public function handle(): int
    {
        $this->info('Finding false positive autograph manuscripts...');

        $allAutograph = Manuscript::where('is_autograph', 1)->get(['id', 'raw_text', 'scribe_name', 'scribe_id']);

        $negPatterns = [
            '/(?:از روی|از رو|منقول از|نقل از|رونویسی از|استنساخ از|مطابق|بر اساس)\s+(?:نسخه|نسخه‌ای|نسخه ای|نسخه ی|اصل)?\s*(?:که\s+)?(?:به\s*خط|بخط)\s*(?:خود\s*)?(?:مؤلف|مصنف|شاعر)/u',
            '/(?:مقابله|تصحیح|تطبیق)\s*(?:شده\s*)?(?:با|توسط|از روی)?\s*(?:نسخه|نسخه‌ای|نسخه ای|نسخه ی|اصل)?\s*(?:که\s+)?(?:به\s*خط|بخط)\s*(?:خود\s*)?(?:مؤلف|مصنف|شاعر)/u',
            '/با\s*نسخه(?:\s+ای|\s+ی)?\s*(?:که\s+)?(?:به\s*خط|بخط)\s*(?:خود\s*)?(?:مؤلف|مصنف|شاعر)\s*(?:مقابله|تصحیح|سنجیده)/u',
            '/نسخه\s*(?:به\s*خط|بخط)\s*(?:خود\s*)?(?:مؤلف|مصنف|شاعر)\s*تصحیح\s*شده/u',
            '/(?:حواشی|حاشیه|حواشی و تصحیحات|تصحیحات|تعلیقات|یادداشت|یادداشت‌ها|علامت‌های بلاغی|علامت بلاغ|بلاغ|اجازه|اجازات|ملحقات|پنج خط|چند سطر|ظهر ورق|پشت برگ|پشت صفحه|روی برگ اول)\s*(?:[^\n؛،]+?)?(?:به\s*خط|بخط)\s*(?:خود\s*)?(?:مؤلف|مصنف|شاعر)/u',
            '/نسخه\s*اصل\s*(?:آن|این|کتاب)?\s*(?:به\s*خط|بخط)\s*مؤلف/u',
        ];

        $invalidIds = [];
        $revertScribeIds = [];
        $affectedPersonIds = [];

        foreach ($allAutograph as $m) {
            $isInvalid = false;
            if ($m->scribe_name !== null && !preg_match('/^=?\s*مؤلف/u', $m->scribe_name)) {
                $isInvalid = true;
            } elseif (!str_contains($m->raw_text, 'کاتب = مؤلف') && !str_contains($m->raw_text, 'کاتب=مؤلف') && !str_contains($m->raw_text, 'محرر = مؤلف') && !str_contains($m->raw_text, 'محرر=مؤلف')) {
                foreach ($negPatterns as $pat) {
                    if (preg_match($pat, $m->raw_text)) {
                        $isInvalid = true;
                        break;
                    }
                }
            }

            if ($isInvalid) {
                $invalidIds[] = $m->id;
                if ($m->scribe_id !== null) {
                    $affectedPersonIds[] = $m->scribe_id;
                }
                if ($m->scribe_name === null && $m->scribe_id !== null) {
                    $revertScribeIds[] = $m->id;
                }
            }
        }

        $this->info("Found " . count($invalidIds) . " manuscripts to unset is_autograph.");
        $this->info("Found " . count($revertScribeIds) . " manuscripts to revert scribe_id back to NULL.");

        if (count($invalidIds) > 0) {
            // Update in chunks
            foreach (array_chunk($invalidIds, 500) as $chunk) {
                DB::table('manuscripts')
                    ->whereIn('id', $chunk)
                    ->update(['is_autograph' => 0]);
            }
        }

        if (count($revertScribeIds) > 0) {
            foreach (array_chunk($revertScribeIds, 500) as $chunk) {
                DB::table('manuscripts')
                    ->whereIn('id', $chunk)
                    ->update(['scribe_id' => null]);
            }
        }

        $this->info('Recalculating scribe statistics on people table...');
        DB::statement("
            UPDATE people SET manuscripts_count = 0, is_scribe = 0 WHERE is_scribe = 1
        ");
        DB::statement("
            UPDATE people p
            JOIN (
                SELECT scribe_id, COUNT(*) as cnt
                FROM manuscripts
                WHERE scribe_id IS NOT NULL
                GROUP BY scribe_id
            ) m_counts ON p.id = m_counts.scribe_id
            SET p.is_scribe = 1,
                p.manuscripts_count = m_counts.cnt
        ");

        if ($this->option('sync-search')) {
            $this->info('Syncing updated manuscripts to Meilisearch...');
            if (count($invalidIds) > 0) {
                Manuscript::whereIn('id', $invalidIds)->searchable();
            }

            $affectedPersonIds = array_unique($affectedPersonIds);
            if (count($affectedPersonIds) > 0) {
                $this->info('Syncing affected people to Meilisearch...');
                Person::whereIn('id', $affectedPersonIds)->searchable();
            }
        }

        $this->info('Done! False positive autograph manuscripts have been corrected.');

        return 0;
    }
}
