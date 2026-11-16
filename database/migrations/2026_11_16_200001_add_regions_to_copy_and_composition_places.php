<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The country or region a place text names, beside its city (backlog item 139, step 14.211):
     * manuscripts.copy_region and works.composition_region, from App\Support\CopyPlaces::regionOf. «هندوستان» has a
     * region and no city, «آمل مازندران» both, «اصفهان» only a city. Filled by `fahares:fill-place-cities --apply`.
     */
    public function up(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->string('copy_region', 40)->nullable()->after('copy_city')->index();
        });
        Schema::table('works', function (Blueprint $table) {
            $table->string('composition_region', 40)->nullable()->after('composition_city')->index();
        });
    }

    public function down(): void
    {
        Schema::table('manuscripts', fn (Blueprint $table) => $table->dropColumn('copy_region'));
        Schema::table('works', fn (Blueprint $table) => $table->dropColumn('composition_region'));
    }
};
