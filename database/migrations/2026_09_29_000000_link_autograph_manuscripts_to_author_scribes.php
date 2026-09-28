<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Link autograph manuscripts to their respective author as scribe_id
        DB::statement("
            UPDATE manuscripts m
            JOIN works w ON m.work_id = w.id
            SET m.scribe_id = w.author_id
            WHERE m.is_autograph = 1
              AND m.scribe_id IS NULL
              AND w.author_id IS NOT NULL
        ");

        // 2. Update people is_scribe flag and manuscripts_count
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("
            UPDATE manuscripts
            SET scribe_id = NULL
            WHERE is_autograph = 1
              AND (scribe_name IS NULL OR scribe_name = '')
        ");

        DB::statement("
            UPDATE people p
            LEFT JOIN (
                SELECT scribe_id, COUNT(*) as cnt
                FROM manuscripts
                WHERE scribe_id IS NOT NULL
                GROUP BY scribe_id
            ) m_counts ON p.id = m_counts.scribe_id
            SET p.is_scribe = IF(COALESCE(m_counts.cnt, 0) > 0, 1, 0),
                p.manuscripts_count = COALESCE(m_counts.cnt, 0)
        ");
    }
};
