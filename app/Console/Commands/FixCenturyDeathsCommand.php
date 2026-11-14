<?php

namespace App\Console\Commands;

use App\Models\Person;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mends the people whose death was read as a year from life dates that give only a century's number («- 13 ؟ قمری»,
 * «13ق - 13 قمری»; step 14.173). Person::deathColumns now reads such a number, 15 or less, as the century unless the
 * death year says it is the year. So the people who did die in those years (YEARS, by their name on the site) get
 * that death year, and everyone else whose life dates end in such a number gets the century's range, and the century
 * where they have none. People are found by their life dates and names, never by id; a run only writes what differs
 * and can be repeated.
 */
class FixCenturyDeathsCommand extends Command
{
    protected $signature = 'fahares:fix-century-deaths
        {--apply : Write the columns; without it the command only lists}';

    protected $description = 'Read a lone number of 15 or less in a person\'s life dates as the century, or as the year for the people known to have died then';

    /** The people whose lone number is the year of their death. */
    public const YEARS = ['محمد (ص)، پیامبر اسلام' => 11, 'فاطمه زهرا (س)' => 11];

    private const COLUMNS = ['death_year_hijri', 'century_hijri', 'death_from', 'death_to', 'death_certainty', 'death_hedge_raw'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $counts = ['year' => 0, 'century' => 0, 'unchanged' => 0];
        $updates = [];
        $people = DB::table('people')->whereNotNull('life_dates_raw')->where('life_dates_raw', 'regexp', '[-–][^0-9]*[0-9۰-۹]{1,2}[^0-9۰-۹]*$')
            ->orderBy('id')->get(array_merge(['id', 'name', 'life_dates_raw'], self::COLUMNS));
        foreach ($people as $p) {
            // The number is a candidate when the text alone gives a whole century and the same number as a death year gives that year
            $asCentury = Person::deathColumns($p->life_dates_raw, null);
            $number = $asCentury['death_to'] !== null && $asCentury['death_to'] % 100 === 0 ? intdiv($asCentury['death_to'], 100) : null;
            if ($number === null || $asCentury['death_from'] !== $asCentury['death_to'] - 99 || Person::deathColumns($p->life_dates_raw, $number)['death_to'] !== $number) {
                continue;
            }
            $year = self::YEARS[$p->name] ?? null;
            $kind = $year === $number ? 'year' : 'century';
            $deathYear = $kind === 'year' ? $year : ($p->death_year_hijri === null ? null : (int) $p->death_year_hijri);
            $new = ['death_year_hijri' => $deathYear, 'century_hijri' => $kind === 'year' ? 1 : ($p->century_hijri === null ? $number : (int) $p->century_hijri)]
                + Person::deathColumns($p->life_dates_raw, $deathYear);
            $old = [];
            foreach (self::COLUMNS as $column) {
                $old[$column] = is_numeric($p->{$column}) ? (int) $p->{$column} : $p->{$column};
            }
            $changed = array_filter($new, fn ($value, $column) => $old[$column] !== $value, ARRAY_FILTER_USE_BOTH);
            if (! $changed) {
                $counts['unchanged']++;
                continue;
            }
            $counts[$kind]++;
            $updates[$p->id] = $changed;
            $this->line(sprintf('  %-7s #%d «%s» [%s]: %s', $kind, $p->id, $p->name, $p->life_dates_raw, implode(', ', array_map(
                fn ($column) => "{$column} " . ($old[$column] ?? 'null') . ' → ' . ($changed[$column] ?? 'null'), array_keys($changed)))));
        }
        if ($apply && $updates) {
            DB::transaction(function () use ($updates) {
                foreach ($updates as $id => $cols) {
                    DB::table('people')->where('id', $id)->update($cols);
                }
            });
        }
        $this->info(($apply ? 'Written' : 'Dry run, nothing written') . ": {$counts['century']} as a century, {$counts['year']} as a year, {$counts['unchanged']} already right.");

        return self::SUCCESS;
    }
}
