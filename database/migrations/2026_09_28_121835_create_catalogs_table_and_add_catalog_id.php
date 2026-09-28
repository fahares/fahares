<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create catalogs table
        Schema::create('catalogs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 500);
            $table->string('short_name', 100);
            $table->string('compiler', 255)->nullable();
            $table->string('publisher', 255)->nullable();
            $table->unsignedSmallInteger('volumes_count')->default(1);
            $table->text('description')->nullable();
            $table->text('citation_format')->nullable();
            $table->timestamps();
        });

        // 2. Insert primary catalog record: Fankha (ID = 1)
        DB::table('catalogs')->insert([
            'id' => 1,
            'code' => 'fankha',
            'name' => 'فهرستگان نسخه‌های خطی ایران (فنخا)',
            'short_name' => 'فنخا',
            'compiler' => 'مصطفی درایتی',
            'publisher' => 'سازمان اسناد و کتابخانه ملی جمهوری اسلامی ایران',
            'volumes_count' => 34,
            'description' => 'فهرستگان جامع نسخه‌های خطی ایران مشتمل بر ۳۴ مجلد که به معرفی بیش از ۷۶ هزار اثر و ۴۳۸ هزار نسخه خطی در بیش از ۱۰۰۰ مرکز اسنادی ایران و جهان پرداخته است.',
            'citation_format' => 'فهرستگان نسخه‌های خطی ایران (فنخا)، به کوشش مصطفی درایتی، تهران، سازمان اسناد و کتابخانه ملی جمهوری اسلامی ایران.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Add catalog_id to works
        Schema::table('works', function (Blueprint $table) {
            $table->foreignId('catalog_id')->default(1)->after('id')->constrained('catalogs')->cascadeOnDelete();
        });

        // 4. Add catalog_id to manuscripts
        Schema::table('manuscripts', function (Blueprint $table) {
            $table->foreignId('catalog_id')->default(1)->after('work_id')->constrained('catalogs')->cascadeOnDelete();
        });

        // 5. Add catalog_id to referrals
        Schema::table('referrals', function (Blueprint $table) {
            $table->foreignId('catalog_id')->default(1)->after('id')->constrained('catalogs')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropForeign(['catalog_id']);
            $table->dropColumn('catalog_id');
        });

        Schema::table('manuscripts', function (Blueprint $table) {
            $table->dropForeign(['catalog_id']);
            $table->dropColumn('catalog_id');
        });

        Schema::table('works', function (Blueprint $table) {
            $table->dropForeign(['catalog_id']);
            $table->dropColumn('catalog_id');
        });

        Schema::dropIfExists('catalogs');
    }
};
