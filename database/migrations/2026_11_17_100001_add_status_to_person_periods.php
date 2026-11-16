<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A period may be a proposal (backlog item 139, step 14.220): `fahares:propose-life-periods` reads the biographies
     * in the sources and files what it finds as «proposed», with origin «machine». The page shows only «approved»
     * periods; a reviewer approves a proposal by saving it, or rejects it, and a rejected one stays so that the same
     * reading is not proposed again.
     */
    public function up(): void
    {
        Schema::table('person_periods', function (Blueprint $table) {
            $table->string('status', 12)->default('approved')->after('kind')->index();
            $table->string('origin', 12)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('person_periods', fn (Blueprint $table) => $table->dropColumn(['status', 'origin']));
    }
};
