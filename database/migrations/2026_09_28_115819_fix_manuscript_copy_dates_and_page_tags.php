<?php

use App\Models\Manuscript;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Fix manuscripts with HTML/OCR comment page tags in copy_date_raw
        Manuscript::where('copy_date_raw', 'LIKE', '%<!--%')
            ->chunkById(200, function ($manuscripts) {
                foreach ($manuscripts as $ms) {
                    $cleaned = preg_replace('/<!--.*?-->/su', '', $ms->copy_date_raw);
                    $cleaned = trim(preg_replace('/\s+/u', ' ', $cleaned));

                    $year = $this->extractHijriYear($cleaned);

                    DB::table('manuscripts')
                        ->where('id', $ms->id)
                        ->update([
                            'copy_date_raw' => $cleaned,
                            'copy_date_hijri_year' => $year,
                        ]);
                }
            });

        // 2. Fix manuscripts with invalid copy_date_hijri_year < 300
        Manuscript::whereNotNull('copy_date_hijri_year')
            ->where('copy_date_hijri_year', '<', 300)
            ->chunkById(200, function ($manuscripts) {
                foreach ($manuscripts as $ms) {
                    $cleaned = preg_replace('/<!--.*?-->/su', '', $ms->copy_date_raw ?? '');
                    $cleaned = trim(preg_replace('/\s+/u', ' ', $cleaned));

                    $year = $this->extractHijriYear($cleaned);

                    DB::table('manuscripts')
                        ->where('id', $ms->id)
                        ->update([
                            'copy_date_raw' => $cleaned ?: $ms->copy_date_raw,
                            'copy_date_hijri_year' => $year,
                        ]);
                }
            });
    }

    private function extractHijriYear(string $raw): ?int
    {
        // Priority 1: Parenthetical or equated 3-4 digit years like (=1104), =1030, (1140ق)
        if (preg_match('/(?:\(=|=|\()\s*([3-9]\d{2}|1[0-4]\d{2})\s*ق?\b/u', $raw, $m)) {
            $val = (int) $m[1];
            if ($val >= 300 && $val <= 1500) {
                return $val;
            }
        }

        // Priority 2: Explicit hijri year with suffix ق or هـ like 1240ق or 951 هـ
        if (preg_match('/([3-9]\d{2}|1[0-4]\d{2})\s*(?:ق|هـ|هـ\.ق)/u', $raw, $m)) {
            $val = (int) $m[1];
            if ($val >= 300 && $val <= 1500) {
                return $val;
            }
        }

        // Priority 3: 4-digit years 1000..1499 standalone (avoiding page or day numbers)
        if (preg_match('/\b(1[0-4]\d{2})\b/u', $raw, $m)) {
            return (int) $m[1];
        }

        // Priority 4: 3-digit years 400..999 standalone
        if (preg_match('/\b([4-9]\d{2})\b/u', $raw, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data cleanup migration is one-way
    }
};
