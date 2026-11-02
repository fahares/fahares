<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The parts of a catalogue volume (step 13.150, docs/plans/catalog-volume-parts.md): a volume that holds the
     * catalogues of several libraries («نشریه» 5, «نسخه‌پژوهی» 1) gets a part for each, with its library, pages and
     * catalogers. A copy of such a volume is of one part (CatalogVolumePart::assign) and reaches its catalogers through
     * it; the volume's own people are its editors, counted apart (catalogers.supervised_count).
     */
    public function up(): void
    {
        Schema::create('catalog_volume_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_volume_id')->constrained('catalog_volumes')->cascadeOnDelete();
            $table->foreignId('library_id')->nullable()->constrained('libraries')->nullOnDelete();
            $table->string('title', 500);
            $table->unsignedInteger('page_first')->nullable();
            $table->unsignedInteger('page_last')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unsignedInteger('manuscripts_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['catalog_volume_id', 'sort']);
            $table->index(['catalog_volume_id', 'library_id']);
        });

        Schema::create('catalog_volume_part_cataloger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_volume_part_id')->constrained('catalog_volume_parts')->cascadeOnDelete();
            $table->foreignId('cataloger_id')->constrained('catalogers')->cascadeOnDelete();
            $table->string('role', 100)->default('cataloger');
            $table->timestamps();

            $table->unique(['catalog_volume_part_id', 'cataloger_id', 'role'], 'cvp_cat_unique');
        });

        Schema::table('manuscripts', function (Blueprint $table) {
            $table->foreignId('catalog_volume_part_id')->nullable()->after('catalog_volume_id')
                ->constrained('catalog_volume_parts')->nullOnDelete();
        });

        Schema::table('catalogers', function (Blueprint $table) {
            $table->unsignedInteger('supervised_count')->default(0)->after('manuscripts_count');
        });
    }

    public function down(): void
    {
        Schema::table('catalogers', function (Blueprint $table) {
            $table->dropColumn('supervised_count');
        });
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catalog_volume_part_id');
        });
        Schema::dropIfExists('catalog_volume_part_cataloger');
        Schema::dropIfExists('catalog_volume_parts');
    }
};
