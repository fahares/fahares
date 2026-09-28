<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use App\Models\Manuscript;
use App\Models\Work;
use App\Models\Library;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::beginTransaction();

        try {
            // 1. Fix Manuscript 65200 (Entry 108)
            DB::table('manuscripts')->where('id', 65200)->update([
                'page_start' => 259,
                'page_end' => 259,
                'raw_text' => "108. تهران؛ ملی؛ شماره نسخه: 1049/4\nآغاز: برابر؛ انجام: صور الافلاک و الله اعلم.\nخط: نسخ و نستعلیق، بی‌کا، تا: قرن 13؛ جلد: تیماج مشکی، 42ص (168- 209)، 18 سطر (8×15)، اندازه: 15×20/5سم [ف: 3 - 60]",
                'metadata' => json_encode([
                    'incipits' => [],
                    'explicits' => [
                        ['label' => '', 'text' => 'صور الافلاک و الله اعلم']
                    ],
                    'editorial_notes' => [],
                    'ownership_and_seals' => [],
                    'catalog_citation' => '[ف: 3 - 60]',
                    'residual_notes' => null,
                ], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

            // 2. Fix Manuscript 65201 (Convert from mangled ghost record to genuine Entry 119)
            DB::table('manuscripts')->where('id', 65201)->update([
                'sequence_number' => 119,
                'volume_number' => 8,
                'page_start' => 259,
                'page_end' => 260,
                'city' => 'قم',
                'library' => 'گلپایگانی',
                'library_id' => 5,
                'shelfmark' => '8845/2-59/65',
                'shelfmark_key' => '8845/2-59/65',
                'scribe_name' => 'ابوطالب بن محمد تقی طباطبائی',
                'is_bika' => 0,
                'is_bita' => 0,
                'is_autograph' => 0,
                'copy_date_raw' => 'یک‌شنبه 23 ربیع الاول 1236ق',
                'copy_date_hijri_year' => 1236,
                'copy_place' => null,
                'script_names' => 'نسخ',
                'script_style' => null,
                'folios' => 8,
                'lines' => 18,
                'dimensions' => '15×20سم',
                'paper' => null,
                'binding' => null,
                'incipit_text' => null,
                'explicit_text' => null,
                'raw_text' => "119. قم؛ گلپایگانی؛ شماره نسخه: 8845/2-59/65\nآغاز: برابر\nخط: نسخ، کا: ابوطالب بن محمد تقی طباطبائی، تا: یک‌شنبه 23 ربیع الاول 1236ق؛ 8گ (82-89)، 18-20 سطر، اندازه: 15×20سم [ف: 2 - 935]",
                'metadata' => json_encode([
                    'incipits' => [],
                    'explicits' => [],
                    'editorial_notes' => [],
                    'ownership_and_seals' => [],
                    'catalog_citation' => '[ف: 2 - 935]',
                    'residual_notes' => null,
                ], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

            // 3. Insert Missing Entries 109 to 118
            $now = now()->toDateTimeString();
            $newEntries = [
                [
                    'work_id' => 15193,
                    'catalog_id' => 1,
                    'sequence_number' => 109,
                    'volume_number' => 8,
                    'page_start' => 259,
                    'page_end' => 259,
                    'city' => 'قم',
                    'library' => 'گلپایگانی',
                    'library_id' => 5,
                    'shelfmark' => '8971/2-59/191',
                    'shelfmark_key' => '8971/2-59/191',
                    'scribe_name' => null,
                    'is_bika' => 1,
                    'is_bita' => 0,
                    'is_autograph' => 0,
                    'copy_date_raw' => 'قرن 13',
                    'copy_date_hijri_year' => null,
                    'script_names' => 'نسخ',
                    'folios' => 3,
                    'lines' => 11,
                    'dimensions' => '11×17سم',
                    'raw_text' => "109. قم؛ گلپایگانی؛ شماره نسخه: 8971/2-59/191\nآغاز: برابر\nفقط سه برگ اول را دارد؛ خط: نسخ، بی‌کا، تا: قرن 13؛ افتادگی: انجام؛ 3گ (38-40)، 11 سطر، اندازه: 11×17سم [ف: 2 - 936]",
                    'metadata' => json_encode([
                        'catalog_citation' => '[ف: 2 - 936]',
                        'editorial_notes' => ['فقط سه برگ اول را دارد؛ افتادگی: انجام'],
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'work_id' => 15193,
                    'catalog_id' => 1,
                    'sequence_number' => 110,
                    'volume_number' => 8,
                    'page_start' => 259,
                    'page_end' => 259,
                    'city' => 'تهران',
                    'library' => 'مجلس',
                    'library_id' => 4,
                    'shelfmark' => '6679/1',
                    'shelfmark_key' => '6679/1',
                    'scribe_name' => null,
                    'is_bika' => 1,
                    'is_bita' => 0,
                    'is_autograph' => 0,
                    'copy_date_raw' => 'قرن 13',
                    'copy_date_hijri_year' => null,
                    'script_names' => 'نسخ',
                    'folios' => 14,
                    'lines' => null,
                    'dimensions' => '12×20/5سم',
                    'binding' => 'مقوایی، عطف پارچه‌ای خاکستری',
                    'has_marginal_notes' => 1,
                    'raw_text' => "110. تهران؛ مجلس؛ شماره نسخه: 6679/1\nخط: نسخ، بی‌کا، تا: قرن 13؛ محشی؛ جلد: مقوایی، عطف پارچه‌ای خاکستری، 14گ (1ر-14ر)، اندازه: 12×20/5سم [ف: 20 - 197]",
                    'metadata' => json_encode([
                        'catalog_citation' => '[ف: 20 - 197]',
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'work_id' => 15193,
                    'catalog_id' => 1,
                    'sequence_number' => 111,
                    'volume_number' => 8,
                    'page_start' => 259,
                    'page_end' => 259,
                    'city' => 'تهران',
                    'library' => 'دانشگاه',
                    'library_id' => 3,
                    'shelfmark' => '957/2',
                    'shelfmark_key' => '957/2',
                    'scribe_name' => null,
                    'is_bika' => 1,
                    'is_bita' => 0,
                    'is_autograph' => 0,
                    'copy_date_raw' => 'شنبه 18 ربیع الاول 1202ق',
                    'copy_date_hijri_year' => 1202,
                    'script_names' => 'نستعلیق',
                    'folios' => null,
                    'lines' => null,
                    'dimensions' => null,
                    'has_marginal_notes' => 1,
                    'raw_text' => "111. تهران؛ دانشگاه؛ شماره نسخه: 957/2\nآغاز و انجام: برابر\nخط: نستعلیق، بی‌کا، تا: شنبه 18 ربیع الاول 1202ق؛ محشی؛ [ف: 4 - 858]",
                    'metadata' => json_encode([
                        'catalog_citation' => '[ف: 4 - 858]',
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'work_id' => 15193,
                    'catalog_id' => 1,
                    'sequence_number' => 112,
                    'volume_number' => 8,
                    'page_start' => 259,
                    'page_end' => 259,
                    'city' => 'تهران',
                    'library' => 'مجلس',
                    'library_id' => 4,
                    'shelfmark' => '1835/1',
                    'shelfmark_key' => '1835/1',
                    'scribe_name' => 'محمد پسر ملا بنیاد علی مراغی',
                    'is_bika' => 0,
                    'is_bita' => 0,
                    'is_autograph' => 0,
                    'copy_date_raw' => '1205ق',
                    'copy_date_hijri_year' => 1205,
                    'script_names' => null,
                    'folios' => 224,
                    'lines' => null,
                    'dimensions' => '15×21سم',
                    'paper' => 'فرنگی',
                    'binding' => 'تیماج مشکی',
                    'raw_text' => "112. تهران؛ مجلس؛ شماره نسخه: 1835/1\nآغاز و انجام: برابر\nکا: محمد پسر ملا بنیاد علی مراغی، تا: 1205ق؛ کاغذ: فرنگی، جلد: تیماج مشکی، 224ص (1-224)، اندازه: 15×21سم [ف: 9 - 483]",
                    'metadata' => json_encode([
                        'catalog_citation' => '[ف: 9 - 483]',
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'work_id' => 15193,
                    'catalog_id' => 1,
                    'sequence_number' => 113,
                    'volume_number' => 8,
                    'page_start' => 259,
                    'page_end' => 259,
                    'city' => 'قم',
                    'library' => 'حجتیه',
                    'library_id' => 115,
                    'shelfmark' => '699/4',
                    'shelfmark_key' => '699/4',
                    'scribe_name' => 'محمد حسین موسوی',
                    'is_bika' => 0,
                    'is_bita' => 0,
                    'is_autograph' => 0,
                    'copy_date_raw' => '1208ق',
                    'copy_date_hijri_year' => 1208,
                    'script_names' => 'نسخ',
                    'folios' => null,
                    'lines' => null,
                    'dimensions' => null,
                    'raw_text' => "113. قم؛ حجتیه؛ شماره نسخه: 699/4\nخط: نسخ، کا: محمد حسین موسوی، تا: 1208ق [ف: 121]",
                    'metadata' => json_encode([
                        'catalog_citation' => '[ف: 121]',
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'work_id' => 15193,
                    'catalog_id' => 1,
                    'sequence_number' => 114,
                    'volume_number' => 8,
                    'page_start' => 259,
                    'page_end' => 259,
                    'city' => 'تهران',
                    'library' => 'مجلس',
                    'library_id' => 4,
                    'shelfmark' => '6163',
                    'shelfmark_key' => '6163',
                    'scribe_name' => 'محمد صادق خاتون آبادی منجم',
                    'is_bika' => 0,
                    'is_bita' => 0,
                    'is_autograph' => 0,
                    'copy_date_raw' => '1226ق',
                    'copy_date_hijri_year' => 1226,
                    'script_names' => 'نسخ',
                    'folios' => 22,
                    'lines' => null,
                    'dimensions' => '12×20سم',
                    'paper' => 'فرنگی سفید',
                    'binding' => 'تیماج مشکی',
                    'has_marginal_notes' => 1,
                    'raw_text' => "114. تهران؛ مجلس؛ شماره نسخه: 6163\nخط: نسخ، کا: محمد صادق خاتون آبادی منجم، تا: 1226ق؛ محشی با نشان «منه» و از کاتب و از «صدر»؛ کاغذ: فرنگی سفید، جلد: تیماج مشکی، 22ص، اندازه: 12×20سم [ف: 19 - 159]",
                    'metadata' => json_encode([
                        'catalog_citation' => '[ف: 19 - 159]',
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'work_id' => 15193,
                    'catalog_id' => 1,
                    'sequence_number' => 115,
                    'volume_number' => 8,
                    'page_start' => 259,
                    'page_end' => 259,
                    'city' => 'تهران',
                    'library' => 'مجلس',
                    'library_id' => 4,
                    'shelfmark' => '6340/5',
                    'shelfmark_key' => '6340/5',
                    'scribe_name' => 'محمد باقر بن قربانعلی مازندرانی',
                    'is_bika' => 0,
                    'is_bita' => 0,
                    'is_autograph' => 0,
                    'copy_date_raw' => '1228ق',
                    'copy_date_hijri_year' => 1228,
                    'script_names' => 'شکسته نستعلیق',
                    'folios' => 17,
                    'lines' => 17,
                    'dimensions' => '13/5×21سم',
                    'paper' => 'فرنگی آبی و زرد',
                    'binding' => 'تیماج مشکی',
                    'has_marginal_notes' => 1,
                    'raw_text' => "115. تهران؛ مجلس؛ شماره نسخه: 6340/5\nخط: شکسته نستعلیق، کا: محمد باقر بن قربانعلی مازندرانی، تا: 1228ق؛ محشی با نشان «میر صدرالدین» که ظاهراً از شرح صدرالدین محمد صادق موسوی بر تشریح الافلاک (= تفریح الادراک) است؛ کاغذ: فرنگی آبی و زرد، جلد: تیماج مشکی، 17ص (105-121)، 17 سطر، اندازه: 13/5×21سم [ف: 19 - 360]",
                    'metadata' => json_encode([
                        'catalog_citation' => '[ف: 19 - 360]',
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'work_id' => 15193,
                    'catalog_id' => 1,
                    'sequence_number' => 116,
                    'volume_number' => 8,
                    'page_start' => 259,
                    'page_end' => 259,
                    'city' => 'قم',
                    'library' => 'مرعشی',
                    'library_id' => 2,
                    'shelfmark' => '38/1',
                    'shelfmark_key' => '38/1',
                    'scribe_name' => 'محمد علی',
                    'is_bika' => 0,
                    'is_bita' => 0,
                    'is_autograph' => 0,
                    'copy_date_raw' => '1232ق',
                    'copy_date_hijri_year' => 1232,
                    'script_names' => 'نستعلیق نازیبا',
                    'folios' => 14,
                    'lines' => 12,
                    'dimensions' => '10×17سم',
                    'binding' => 'تیماج قهوه‌ای',
                    'has_marginal_notes' => 1,
                    'raw_text' => "116. قم؛ مرعشی؛ شماره نسخه: 38/1\nآغاز و انجام: برابر\nخط: نستعلیق نازیبا، کا: محمد علی، تا: 1232ق؛ محشی؛ جلد: تیماج قهوه‌ای، 14گ (2پ-15ر)، 12 سطر، اندازه: 10×17سم [ف: 1 - 48]",
                    'metadata' => json_encode([
                        'catalog_citation' => '[ف: 1 - 48]',
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'work_id' => 15193,
                    'catalog_id' => 1,
                    'sequence_number' => 117,
                    'volume_number' => 8,
                    'page_start' => 259,
                    'page_end' => 259,
                    'city' => 'تهران',
                    'library' => 'لغت نامه دهخدا',
                    'library_id' => 27,
                    'shelfmark' => '46/1',
                    'shelfmark_key' => '46/1',
                    'scribe_name' => null,
                    'is_bika' => 1,
                    'is_bita' => 0,
                    'is_autograph' => 0,
                    'copy_date_raw' => 'سه‌شنبه 25 ربیع الثانی 1233ق',
                    'copy_date_hijri_year' => 1233,
                    'script_names' => 'نستعلیق',
                    'folios' => null,
                    'lines' => null,
                    'dimensions' => null,
                    'binding' => 'تیماج سرخ، قطع: خشتی',
                    'raw_text' => "117. تهران؛ لغت نامه دهخدا؛ شماره نسخه: 46/1\nخط: نستعلیق، بی‌کا، تا: سه‌شنبه 25 ربیع الثانی 1233ق؛ جلد: تیماج سرخ، قطع: خشتی [نشریه: 3 - 35]",
                    'metadata' => json_encode([
                        'catalog_citation' => '[نشریه: 3 - 35]',
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'work_id' => 15193,
                    'catalog_id' => 1,
                    'sequence_number' => 118,
                    'volume_number' => 8,
                    'page_start' => 259,
                    'page_end' => 259,
                    'city' => 'مشهد',
                    'library' => 'شیخ علی حیدر',
                    'library_id' => 205,
                    'shelfmark' => '448/2',
                    'shelfmark_key' => '448/2',
                    'scribe_name' => 'محسن بن عبدالکریم تنکابنی جیلانی',
                    'is_bika' => 0,
                    'is_bita' => 0,
                    'is_autograph' => 0,
                    'copy_date_raw' => '1233ق',
                    'copy_date_hijri_year' => 1233,
                    'copy_place' => 'اصفهان مدرسه السلطانية',
                    'script_names' => 'نستعلیق',
                    'folios' => 13,
                    'lines' => 20,
                    'dimensions' => '10×21سم',
                    'binding' => 'عادی',
                    'raw_text' => "118. مشهد؛ شیخ علی حیدر؛ شماره نسخه: 448/2\nآغاز و انجام: برابر\nخط: نستعلیق، کا: محسن بن عبدالکریم تنکابنی جیلانی، تا: 1233ق، جا: اصفهان مدرسه السلطانية؛ جلد: عادی، 13گ (32پ-44ر)، 20 سطر، اندازه: 10×21سم [مؤید: 1 - 362]",
                    'metadata' => json_encode([
                        'catalog_citation' => '[مؤید: 1 - 362]',
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ];

            $insertedIds = [];
            $scriptMapping = [
                109 => [1], // نسخ
                110 => [1], // نسخ
                111 => [2], // نستعلیق
                112 => [],  // بدون خط
                113 => [1], // نسخ
                114 => [1], // نسخ
                115 => [5], // شکسته نستعلیق
                116 => [2], // نستعلیق
                117 => [2], // نستعلیق
                118 => [2], // نستعلیق
            ];

            foreach ($newEntries as $entry) {
                $id = DB::table('manuscripts')->insertGetId($entry);
                $insertedIds[] = $id;

                $seq = $entry['sequence_number'];
                if (!empty($scriptMapping[$seq])) {
                    foreach ($scriptMapping[$seq] as $scriptId) {
                        DB::table('manuscript_script')->insert([
                            'manuscript_id' => $id,
                            'script_id' => $scriptId,
                            'is_primary' => true,
                        ]);
                    }
                }
            }

            // 4. Update manuscripts_count for Work 15193 and affected Libraries
            DB::statement('UPDATE works SET manuscripts_count = (SELECT COUNT(*) FROM manuscripts WHERE manuscripts.work_id = works.id) WHERE id = 15193');
            DB::statement('UPDATE libraries SET manuscripts_count = (SELECT COUNT(*) FROM manuscripts WHERE manuscripts.library_id = libraries.id) WHERE id IN (2, 3, 4, 5, 11, 27, 115, 205)');

            DB::commit();

            // 5. Index into Meilisearch
            $allIdsToIndex = array_merge([65200, 65201], $insertedIds);
            Manuscript::whereIn('id', $allIdsToIndex)->searchable();

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
