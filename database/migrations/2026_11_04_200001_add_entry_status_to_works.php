<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the catalogue says of a work's entry as a whole (item 87, step 14.7; App\Models\Work::ENTRY_STATUSES):
     * «غیر همانند» (its copies are different texts under one title), «ظاهرا غیر همانند», «ناشناخته» (the work is not
     * identified) or «همانندی غیر معلوم». v1 put the label in the description, where it stood alone.
     */
    public function up(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->string('entry_status', 30)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->dropColumn('entry_status');
        });
    }
};
