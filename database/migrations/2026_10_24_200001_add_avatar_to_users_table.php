<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A user's picture (backlog item 66): an uploaded one, or a local copy of the Google one; none means the initial. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('affiliation');
            $table->string('avatar_source', 10)->nullable()->after('avatar_path'); // upload | google
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'avatar_source']);
        });
    }
};
