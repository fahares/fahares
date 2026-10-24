<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The admin queue «بازبینی اشخاص» (backlog item 78): groups of person records that may be one
     * person (type duplicate, key = the name key of DuplicatePeopleFinder) and person records that
     * may hold several people (type mixed, key = person:<id>). A decision stays with the key, so a
     * group judged «چند نفرند» does not come back unless its records change.
     */
    public function up(): void
    {
        Schema::create('person_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('key', 191);
            $table->json('person_ids');
            $table->string('tier', 20)->nullable();
            $table->string('reason', 500)->nullable();
            // A reviewer's or an automatic judgement offered as the starting point: merge_ids, target_id, comment, source
            $table->json('suggestion')->nullable();
            // open | merged | distinct | deferred | split | dismissed | gone
            $table->string('status', 20)->default('open')->index();
            $table->json('decision')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['type', 'key']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_reviews');
    }
};
