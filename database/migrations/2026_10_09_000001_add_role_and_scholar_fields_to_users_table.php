<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'role')) {
                $table->string('role', 30)->default('user')->index()->after('password');
            }
            if (!Schema::hasColumn('users', 'affiliation')) {
                $table->string('affiliation')->nullable()->after('role');
            }
            if (!Schema::hasColumn('users', 'is_verified_scholar')) {
                $table->boolean('is_verified_scholar')->default(false)->index()->after('affiliation');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('users', 'role')) {
                $columns[] = 'role';
            }
            if (Schema::hasColumn('users', 'affiliation')) {
                $columns[] = 'affiliation';
            }
            if (Schema::hasColumn('users', 'is_verified_scholar')) {
                $columns[] = 'is_verified_scholar';
            }
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
