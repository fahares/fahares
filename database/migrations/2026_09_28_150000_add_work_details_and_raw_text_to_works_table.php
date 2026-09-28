<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('works', function (Blueprint $table) {
            if (!Schema::hasColumn('works', 'raw_text')) {
                $table->mediumText('raw_text')->nullable()->after('description');
            }
            if (!Schema::hasColumn('works', 'bibliography')) {
                $table->json('bibliography')->nullable()->after('raw_text');
            }
            if (!Schema::hasColumn('works', 'print_info')) {
                $table->text('print_info')->nullable()->after('bibliography');
            }
            if (!Schema::hasColumn('works', 'dedication')) {
                $table->text('dedication')->nullable()->after('print_info');
            }
            if (!Schema::hasColumn('works', 'related_work')) {
                $table->text('related_work')->nullable()->after('dedication');
            }
            if (!Schema::hasColumn('works', 'commentaries_and_glosses')) {
                $table->json('commentaries_and_glosses')->nullable()->after('related_work');
            }
            if (!Schema::hasColumn('works', 'composition_place')) {
                $table->text('composition_place')->nullable()->after('commentaries_and_glosses');
            }
        });
    }

    public function down(): void
    {
        Schema::table('works', function (Blueprint $table) {
            $table->dropColumn([
                'raw_text',
                'bibliography',
                'print_info',
                'dedication',
                'related_work',
                'commentaries_and_glosses',
                'composition_place',
            ]);
        });
    }
};
