<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The claims layer (backlog items 99 and 147, step 14.249): what a first-hand source says about a field of a record
     * where it differs from the stored value, or fills a field the record lacks. The record's column stays the accepted
     * value; a claim is a candidate until someone accepts or rejects it. A first-hand reading that agrees with the
     * record is not a claim: its entry lists the field in source_entries.confirmed_fields.
     */
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');
            $table->string('field', 60);
            $table->text('value_text')->nullable();
            $table->json('value_json')->nullable();
            // the record's value when the claim was made, for the reviewer
            $table->text('current_text')->nullable();
            $table->foreignId('source_entry_id')->nullable()->constrained('source_entries')->nullOnDelete();
            $table->text('raw_text')->nullable();
            $table->string('method', 20)->default('rule');
            $table->unsignedTinyInteger('certainty')->nullable();
            $table->string('status', 20)->default('candidate');
            $table->boolean('is_negative')->default(false);
            $table->foreignId('suggestion_id')->nullable()->constrained('field_suggestions')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'field']);
            $table->index(['status', 'field']);
        });

        Schema::table('source_entries', function (Blueprint $table) {
            // the record's fields this entry's text was read for and agrees with: ["shelfmark","folios","copy_date"]
            $table->json('confirmed_fields')->nullable()->after('text_status');
            $table->timestamp('fields_read_at')->nullable()->after('confirmed_fields');
        });
    }

    public function down(): void
    {
        Schema::table('source_entries', function (Blueprint $table) {
            $table->dropColumn(['confirmed_fields', 'fields_read_at']);
        });
        Schema::dropIfExists('claims');
    }
};
