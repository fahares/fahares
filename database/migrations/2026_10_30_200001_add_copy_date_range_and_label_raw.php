<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 3 of the hybrid data-structure roadmap (backlog item 96). A copy's date as a range of Hijri years read
     * from copy_date_raw («قرن 13» is 1201-1300, «اواخر قرن 12» 1167-1200, «1246ق» 1246-1246), with how sure the
     * catalogue is and its own hedge word («محتملاً»), and a note on the date («در حیات مؤلف»). copy_date_raw stays
     * the catalogue's wording and copy_date_hijri_year the sortable year.
     * label_raw on the people rows keeps the catalogue's phrase before a name («حسب الامر», «به نام», «برای»).
     */
    public function up(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->smallInteger('copy_date_from')->nullable()->after('copy_date_hijri_year');
            $table->smallInteger('copy_date_to')->nullable()->after('copy_date_from');
            $table->string('copy_date_certainty', 10)->nullable()->after('copy_date_to');
            $table->string('copy_date_hedge_raw', 60)->nullable()->after('copy_date_certainty');
            $table->string('copy_date_note', 500)->nullable()->after('copy_date_hedge_raw');
            $table->index(['copy_date_from', 'copy_date_to']);
        });
        foreach (['manuscript_people', 'work_people'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('label_raw', 60)->nullable()->after('role');
            });
        }
    }

    public function down(): void
    {
        foreach (['manuscript_people', 'work_people'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('label_raw'));
        }
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->dropIndex(['copy_date_from', 'copy_date_to']);
            $table->dropColumn(['copy_date_from', 'copy_date_to', 'copy_date_certainty', 'copy_date_hedge_raw', 'copy_date_note']);
        });
    }
};
