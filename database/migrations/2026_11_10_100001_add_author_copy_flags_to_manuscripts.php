<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 14.130: how near a copy stands to the author's own. A copy may be written from the author's copy
 * («از روی نسخه‌ی مؤلف»), collated with it («مقابله با نسخه‌ی مؤلف»), or collated and corrected by the author
 * himself («به خط مؤلف تصحیح شده»، «بلاغ مؤلف»). The general is_collated says only that a copy was collated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->boolean('copied_from_author_copy')->default(false)->after('has_author_marginalia');
            $table->boolean('collated_with_author_copy')->default(false)->after('copied_from_author_copy');
            $table->boolean('corrected_by_author')->default(false)->after('collated_with_author_copy');
        });
    }

    public function down(): void
    {
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->dropColumn(['copied_from_author_copy', 'collated_with_author_copy', 'corrected_by_author']);
        });
    }
};
