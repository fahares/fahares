<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The city a place text names, stored beside the text (backlog item 139, step 14.209): manuscripts.copy_city for
     * copy_place and works.composition_city for composition_place. The text stays as the catalogue wrote it
     * («دارالخلافه طهران», «اصفهان مدرسه جده»); the city is the name App\Support\CopyPlaces gives it («تهران»,
     * «اصفهان»), or null when the text names no city of its list («ایران», «مدرسه صدر»). Saving a copy or a work
     * keeps it in step; `fahares:fill-place-cities --apply` fills the records there are.
     */
    public function up(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->string('copy_city', 40)->nullable()->after('copy_place')->index();
        });
        Schema::table('works', function (Blueprint $table) {
            $table->string('composition_city', 40)->nullable()->after('composition_place')->index();
        });
    }

    public function down(): void
    {
        Schema::table('manuscripts', fn (Blueprint $table) => $table->dropColumn('copy_city'));
        Schema::table('works', fn (Blueprint $table) => $table->dropColumn('composition_city'));
    }
};
