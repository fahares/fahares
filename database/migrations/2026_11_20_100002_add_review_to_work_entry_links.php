<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * work_entry_links also holds the links a reviewer is to decide (backlog item 6): «status» is «linked» (the page
     * links the entry), «proposed» (waiting in the panel's queue) or «rejected»; «why» is the reason the proposal gives.
     */
    public function up(): void
    {
        Schema::table('work_entry_links', function (Blueprint $table) {
            $table->string('status', 12)->default('linked')->index();
            $table->string('why', 300)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_entry_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['status', 'why', 'decided_at']);
        });
    }
};
