<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manuscripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained('works')->cascadeOnDelete();
            $table->foreignId('library_id')->nullable()->constrained('libraries')->nullOnDelete();
            $table->foreignId('scribe_id')->nullable()->constrained('people')->nullOnDelete();

            $table->unsignedSmallInteger('sequence_number')->default(1);
            $table->unsignedTinyInteger('volume_number')->index();
            $table->unsignedSmallInteger('page_start')->index();
            $table->unsignedSmallInteger('page_end')->nullable()->index();

            // Retrieval & Shelfmark
            $table->string('city', 255)->nullable()->index();
            $table->string('library', 255)->nullable()->index();
            $table->text('shelfmark')->nullable();
            $table->string('shelfmark_key', 100)->nullable()->index();

            // Scribe & Date
            $table->string('scribe_name', 600)->nullable()->index();
            $table->boolean('is_bika')->default(false)->index();
            $table->boolean('is_bita')->default(false)->index();
            $table->boolean('is_autograph')->default(false)->index();
            $table->text('copy_date_raw')->nullable();
            $table->smallInteger('copy_date_hijri_year')->nullable()->index();
            $table->text('copy_place')->nullable();

            // Script & Codicology
            $table->text('script_names')->nullable(); // Denormalized e.g. "نسخ و نستعلیق"
            $table->text('script_style')->nullable(); // e.g. "خوش", "خفی", "چلیپا"
            $table->unsignedSmallInteger('folios')->nullable();
            $table->unsignedSmallInteger('lines')->nullable();
            $table->string('dimensions', 255)->nullable();
            $table->text('paper')->nullable();
            $table->text('binding')->nullable();

            // Incipit & Explicit
            $table->text('incipit_text')->nullable();
            $table->text('explicit_text')->nullable();

            // 9 Codicological Flags
            $table->boolean('is_corrected')->default(false)->index();
            $table->boolean('has_marginal_notes')->default(false)->index();
            $table->boolean('is_ruled')->default(false)->index();
            $table->boolean('has_catchwords')->default(false)->index();
            $table->boolean('is_facsimile')->default(false)->index();
            $table->boolean('is_collated')->default(false)->index();
            $table->boolean('is_illuminated')->default(false)->index();
            $table->boolean('is_illustrated')->default(false)->index();
            $table->boolean('has_author_marginalia')->default(false)->index();

            // Dense Metadata & Raw Text
            $table->json('metadata')->nullable(); // incipits, explicits, editorial_notes, seals, etc.
            $table->text('raw_text')->nullable();

            $table->timestamps();

            // Composite indexes for common queries
            $table->index(['work_id', 'sequence_number']);
            $table->index(['library_id', 'shelfmark_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manuscripts');
    }
};
