<?php

namespace App\Console\Commands;

use App\Models\Person;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mends the people whose life dates name only a century (step 14.213). Two kinds of row, both with Hijri life dates:
 *   - life: a century with no dash before it («ق 13 قمری», «قرن 9»), the century the person lived in. It was stored as
 *     the range of the death (1201-1300), which made a conflict of every work or copy after the century. The range is
 *     emptied and the century stays in century_hijri, where the lists and the date-estimate engine read it;
 *   - middle: a death year that is the middle of the century the life dates name (1250 for «ق 13», 1050 for
 *     «-قرن 11ق»), the number the corpus makes to sort by. The site showed it as «د. ۱۲۵۰ ق». It is emptied; the death
 *     century after a dash keeps its range.
 * A real death year beside such a text is kept and is a range of its own. Life dates of another calendar are left to
 * fahares:fix-calendar-deaths. People are found by their life dates, never by id; a run only writes what differs and
 * can be repeated.
 */
class FixLifeCenturiesCommand extends Command
{
    protected $signature = 'fahares:fix-life-centuries
        {--apply : Write the columns; without it the command only counts}
        {--show=8 : How many examples to list per kind}';

    protected $description = 'Empty the death range of people whose life dates name the century they lived in, and a death year that is the middle of a century';

    public const COLUMNS = ['death_year_hijri', 'century_hijri', 'death_from', 'death_to', 'death_certainty', 'death_hedge_raw', 'death_kind'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $counts = ['life' => 0, 'middle' => 0, 'unchanged' => 0];
        $shown = ['life' => 0, 'middle' => 0];
        DB::table('people')->whereNotNull('life_dates_raw')->where('life_dates_raw', '!=', '')->orderBy('id')
            ->select(array_merge(['id', 'name', 'life_dates_raw'], self::COLUMNS))
            ->chunkById(5000, function ($people) use ($apply, &$counts, &$shown) {
                $updates = [];
                foreach ($people as $p) {
                    if (Person::lifeCalendar($p->life_dates_raw) !== null) {
                        continue;
                    }
                    $old = self::stored($p);
                    $life = Person::lifeCentury($p->life_dates_raw);
                    $middle = $old['death_year_hijri'] !== null && Person::isCenturyMiddle($p->life_dates_raw, $old['death_year_hijri']);
                    if ($life === null && ! $middle) {
                        continue;
                    }
                    $year = $middle ? null : $old['death_year_hijri'];
                    $new = ['death_year_hijri' => $year, 'century_hijri' => $old['century_hijri'] ?? $life] + Person::deathColumns($p->life_dates_raw, $year);
                    $new += Person::deathKind($new);
                    $changed = array_filter($new, fn ($value, $column) => $old[$column] !== $value, ARRAY_FILTER_USE_BOTH);
                    if (! $changed) {
                        $counts['unchanged']++;
                        continue;
                    }
                    $kind = $life !== null ? 'life' : 'middle';
                    $counts[$kind]++;
                    $updates[$p->id] = $changed;
                    if ($shown[$kind]++ < (int) $this->option('show')) {
                        $this->line(sprintf('  %-6s #%d «%s» [%s]: %s', $kind, $p->id, $p->name, $p->life_dates_raw, self::describe($old, $changed)));
                    }
                }
                if ($apply && $updates) {
                    DB::transaction(function () use ($updates) {
                        foreach ($updates as $id => $cols) {
                            DB::table('people')->where('id', $id)->update($cols);
                        }
                    });
                }
            });
        $this->info(($apply ? 'Written' : 'Dry run, nothing written') . ": {$counts['life']} centuries of a life, {$counts['middle']} mid-century death years, {$counts['unchanged']} already right.");

        return self::SUCCESS;
    }

    /** @return array<string, mixed> the row's columns, numbers as integers */
    public static function stored(object $row, array $columns = self::COLUMNS): array
    {
        $old = [];
        foreach ($columns as $column) {
            $old[$column] = is_numeric($row->{$column}) ? (int) $row->{$column} : $row->{$column};
        }

        return $old;
    }

    public static function describe(array $old, array $changed): string
    {
        return implode(', ', array_map(fn ($column) => "{$column} " . ($old[$column] ?? 'null') . ' → ' . ($changed[$column] ?? 'null'), array_keys($changed)));
    }
}
