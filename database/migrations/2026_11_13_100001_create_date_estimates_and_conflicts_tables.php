<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What fahares:estimate-dates makes of the documented dates and the ties between people, works and copies (step
 * 14.166, docs/plans/date-estimates.md). date_estimates holds a range of Hijri years for a date nothing documents,
 * each bound with the rule and the record that gave it; it is rebuilt whole on every run. date_conflicts holds the
 * pairs of documented dates that cannot both be right; a run keeps a row it finds again, so a reviewer's «not an
 * error» stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('date_estimates', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 12);
            $table->unsignedBigInteger('subject_id');
            $table->string('field', 12);
            $table->smallInteger('year_from')->nullable();
            $table->smallInteger('year_to')->nullable();
            foreach (['from', 'to'] as $side) {
                // null rule: the bound is the documented one
                $table->string("{$side}_rule", 20)->nullable();
                $table->string("{$side}_evidence_type", 12)->nullable();
                $table->unsignedBigInteger("{$side}_evidence_id")->nullable();
                $table->string("{$side}_evidence_field", 12)->nullable();
                $table->unsignedTinyInteger("{$side}_depth")->nullable();
            }
            $table->unique(['subject_type', 'subject_id', 'field']);
        });

        Schema::create('date_conflicts', function (Blueprint $table) {
            $table->id();
            $table->char('conflict_key', 40)->unique();
            $table->string('rule', 20);
            $table->boolean('direct')->default(true);
            // a's earliest year is later than b's latest year allows
            $table->string('a_type', 12);
            $table->unsignedBigInteger('a_id');
            $table->string('a_field', 12);
            $table->smallInteger('a_from');
            $table->string('b_type', 12);
            $table->unsignedBigInteger('b_id');
            $table->string('b_field', 12);
            $table->smallInteger('b_to');
            $table->smallInteger('gap');
            // an indirect conflict: the date a and b leave no year for; a_from and b_to are then the bounds they give it
            $table->string('via_type', 12)->nullable();
            $table->unsignedBigInteger('via_id')->nullable();
            $table->string('via_field', 12)->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dismiss_note', 500)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->index(['a_type', 'a_id']);
            $table->index(['b_type', 'b_id']);
            $table->index(['rule', 'gap']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('date_conflicts');
        Schema::dropIfExists('date_estimates');
    }
};
