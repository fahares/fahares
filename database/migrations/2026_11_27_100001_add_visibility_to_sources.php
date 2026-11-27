<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who sees a source on the record pages (step 14.426, App\Support\SourceVisibility): `visibility` is always, fallback
 * (only where the reader sees no first-hand source) or hidden; `audience` lists those who see it always, whatever its
 * visibility: {"roles": [...], "scholars": bool, "users": [ids]}. The union catalogues (Fankha and Monzavi) keep the
 * rule of step 14.376 as fallback, and editors and verified scholars see them always.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            if (! Schema::hasColumn('sources', 'visibility')) {
                $table->string('visibility', 20)->default('always')->after('rank');
            }
            if (! Schema::hasColumn('sources', 'audience')) {
                $table->json('audience')->nullable()->after('visibility');
            }
        });

        DB::table('sources')->where('kind', 'union_catalogue')->update([
            'visibility' => 'fallback',
            'audience' => json_encode(['roles' => ['editor'], 'scholars' => true, 'users' => []]),
        ]);
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['visibility', 'audience']);
        });
    }
};
