<?php

namespace App\Console\Commands;

use App\Models\Person;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings people's death year in step with their life dates (step 14.179). Three kinds of row:
 *   - alive: the death is a century and death_year_hijri is the year before the dash («1107ق- 12 قمری», alive in
 *     1107). That year is not the death: death_year_hijri is emptied and the century kept;
 *   - range: the death range does not start at that year yet (Person::deathColumns, 1107-1200);
 *   - year: the life dates give one year of death and death_year_hijri is empty («- 40 قمری»). It takes that year,
 *     with its century, and birth_year_hijri the plain number before the dash («3 - 50 قمری») when it is empty.
 * People are found by their life dates, never by id; a run only writes what differs and can be repeated.
 */
class FillDeathYearsCommand extends Command
{
    protected $signature = 'fahares:fill-death-years
        {--apply : Write the columns; without it the command only lists}';

    protected $description = 'Empty a death year that is the year a person was alive in, and fill an empty one from life dates that give the year';

    private const COLUMNS = ['birth_year_hijri', 'death_year_hijri', 'century_hijri', 'death_from', 'death_to', 'death_certainty', 'death_hedge_raw'];

    /** A birth further than this from the death is not taken as one. */
    private const LONGEST_LIFE = 120;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $counts = ['alive' => 0, 'range' => 0, 'year' => 0];
        DB::table('people')->whereNotNull('life_dates_raw')->where('life_dates_raw', '!=', '')->orderBy('id')
            ->select(array_merge(['id', 'name', 'life_dates_raw'], self::COLUMNS))
            ->chunkById(5000, function ($people) use ($apply, &$counts) {
                $updates = [];
                foreach ($people as $p) {
                    $old = [];
                    foreach (self::COLUMNS as $column) {
                        $old[$column] = is_numeric($p->{$column}) ? (int) $p->{$column} : $p->{$column};
                    }
                    [$kind, $new] = $this->change($p->life_dates_raw, $old);
                    $changed = array_filter($new, fn ($value, $column) => $old[$column] !== $value, ARRAY_FILTER_USE_BOTH);
                    if (! $changed) {
                        continue;
                    }
                    $counts[$kind]++;
                    $updates[$p->id] = $changed;
                    $this->line(sprintf('  %-5s #%d «%s» [%s]: %s', $kind, $p->id, $p->name, $p->life_dates_raw, implode(', ', array_map(
                        fn ($column) => "{$column} " . ($old[$column] ?? 'null') . ' → ' . ($changed[$column] ?? 'null'), array_keys($changed)))));
                }
                if ($apply && $updates) {
                    DB::transaction(function () use ($updates) {
                        foreach ($updates as $id => $cols) {
                            DB::table('people')->where('id', $id)->update($cols);
                        }
                    });
                }
            });
        $this->info(($apply ? 'Written' : 'Dry run, nothing written') . ": {$counts['alive']} death years emptied, {$counts['range']} ranges, {$counts['year']} death years filled.");

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: array<string, mixed>} the kind of the row and the columns it should hold */
    private function change(string $raw, array $old): array
    {
        $deathYear = $old['death_year_hijri'];
        $text = Person::deathColumns($raw, null);
        $span = $text['death_from'] !== null && $text['death_to'] !== null && $text['death_from'] < $text['death_to'];
        if ($span && $deathYear !== null && $deathYear === Person::yearBeforeDash($raw)) {
            return ['alive', ['death_year_hijri' => null] + $text];
        }
        if ($deathYear === null && $text['death_from'] !== null && $text['death_from'] === $text['death_to']) {
            $year = $text['death_from'];
            $new = ['death_year_hijri' => $year, 'century_hijri' => (int) ceil($year / 100)] + Person::deathColumns($raw, $year);
            $before = preg_split('/\s*[-–]\s*/u', strtr($raw, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']));
            if ($old['birth_year_hijri'] === null && count($before) > 1 && preg_match('/^\s*(\d{1,4})\s*$/u', $before[0], $m)
                && (int) $m[1] > 0 && (int) $m[1] < $year && $year - (int) $m[1] <= self::LONGEST_LIFE) {
                $new['birth_year_hijri'] = (int) $m[1];
            }

            return ['year', $new];
        }

        return ['range', Person::deathColumns($raw, $deathYear)];
    }
}
