<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each treatise reads from its «مجموعه», a row for each (backlog item 163, step 14.329): the scribe it reads
 * (kind «scribe», the person's id), each script its volume's hand stands for («script», the script's id) and each
 * type of provenance note its volume's other treatises have and it has not («note», the type). Derived data, made
 * again by App\Support\Collections::refreshReadings; the filters asked of the database read it
 * (App\Support\CollectionIndex), as asking the collections themselves took seconds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->foreignId('manuscript_id')->constrained('manuscripts')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->string('ref', 30);
            $table->index(['kind', 'ref', 'manuscript_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_readings');
    }
};
