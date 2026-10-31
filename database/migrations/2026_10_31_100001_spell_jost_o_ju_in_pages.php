<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * «جست‌وجو» with its zero-width non-joiner, as the site now writes it everywhere, in the pages' bodies and titles
     * (step 13.85). Words that only contain it («جستجوگر») follow along.
     */
    public function up(): void
    {
        $this->replace('جستجو', "جست\u{200C}وجو");
    }

    public function down(): void
    {
        $this->replace("جست\u{200C}وجو", 'جستجو');
    }

    private function replace(string $from, string $to): void
    {
        foreach (['title', 'body'] as $column) {
            DB::table('pages')->where($column, 'like', "%{$from}%")->update([$column => DB::raw("REPLACE({$column}, " . DB::getPdo()->quote($from) . ', ' . DB::getPdo()->quote($to) . ')')]);
        }
    }
};
