<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('libraries', function (Blueprint $table) {
            $table->string('logo_path', 500)->nullable()->after('description');
            // White or very light logos are drawn on a dark plate instead of the light one
            $table->boolean('logo_on_dark')->default(false)->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('libraries', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'logo_on_dark']);
        });
    }
};
