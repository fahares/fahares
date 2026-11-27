<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The years a scribe's copies surely lie apart (step 14.430, backlog item 125): the latest start of a copy's date less
 * the earliest end of one, however a copy dated only by its century is read. Over PersonReviewQueue::MIXED_SPAN it
 * marks a record that holds several namesake scribes. scribed_to - scribed_from cannot tell that: one copy of «قرن ۱۱»
 * alone spans 99 years. Filled by Person::recountContributions; the people already there by the nightly
 * fahares:sync-person-roles --apply, not here, since a migration over every person would hold the deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->smallInteger('scribed_gap')->nullable()->after('scribed_to');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('scribed_gap');
        });
    }
};
