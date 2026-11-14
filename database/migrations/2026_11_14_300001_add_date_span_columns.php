<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DATES = ['manuscripts' => 'copy_date', 'works' => 'composition_date', 'people' => 'death'];

    /**
     * The date of a copy, of a composition and of a death as App\Support\DateSpan (item 134, step 14.188): beside the
     * outer years `<prefix>_from` and `<prefix>_to` they have, what the range means (`<prefix>_kind`: span, between,
     * or null when it is no range or the text does not settle it) and, for a span whose ends are uncertain, the
     * latest year of its start and the earliest of its end. The parsers fill the kind; nothing fills the other two
     * yet. A new table takes the whole set with `$table->dateSpan('<prefix>')`.
     *
     * A death is one event, so every range of it is «between»: set here, in one statement. The kinds of copy and
     * composition dates come from their texts: `fahares:fill-copy-date-ranges --apply`, and the same with `--works`.
     */
    public function up(): void
    {
        foreach (self::DATES as $name => $prefix) {
            Schema::table($name, fn (Blueprint $table) => $table->dateSpanKind($prefix));
        }
        DB::table('people')->whereRaw('NOT (death_from <=> death_to)')->update(['death_kind' => 'between']);
    }

    public function down(): void
    {
        foreach (self::DATES as $name => $prefix) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(["{$prefix}_kind", "{$prefix}_start_to", "{$prefix}_end_from"]));
        }
    }
};
