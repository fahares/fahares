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
        Schema::create('entity_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 50)->index();
            $table->unsignedBigInteger('source_id')->index();
            $table->unsignedBigInteger('target_id')->index();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['entity_type', 'source_id'], 'uq_entity_type_source_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entity_redirects');
    }
};
