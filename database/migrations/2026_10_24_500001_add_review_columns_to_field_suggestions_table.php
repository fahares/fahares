<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An answer given in «تصحیح جمعی» (backlog item 80) is an ordinary suggestion; these columns say which queue item it
 * answers (review_queue, review_item_id) and how (review_answer), so the opinions on one item can be counted together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_suggestions', function (Blueprint $table) {
            $table->string('review_queue', 30)->nullable()->after('kind');
            $table->unsignedBigInteger('review_item_id')->nullable()->after('review_queue');
            $table->string('review_answer', 120)->nullable()->after('review_item_id');
            $table->index(['review_queue', 'review_item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('field_suggestions', function (Blueprint $table) {
            $table->dropIndex(['review_queue', 'review_item_id', 'status']);
            $table->dropColumn(['review_queue', 'review_item_id', 'review_answer']);
        });
    }
};
