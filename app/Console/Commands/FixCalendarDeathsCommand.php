<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Support\FankhaWorkLocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Mends the people whose solar, Gregorian or pre-Christian years were stored as Hijri years (step 14.214): «1250 -
 * 1312 شمسی» with the death year 1312, «129 - 199 میلادی» with 199, «427؟ - 347 ق.م» with 347. The corpus gave the
 * number with no calendar, and the life dates of these people were left out of life_dates_raw, so the texts are read
 * again from the decision file of fahares:fill-death-ranges (the dates a work's author and translator carry in the
 * catalogue), beside the person's own life dates.
 *
 * A text of another calendar is taken for a person when it explains what is stored: the death year is the text's
 * death year, its lone year, its birth year, the middle of its century or a Hijri year before it; or the birth year
 * is the text's; or the person has no life dates and no death year at all; or the text is already the person's own
 * and the death year is empty or already the converted one. A person whose stored years say something else is left
 * alone and counted as «differs», and so is a «Gregorian» year from 1000 to 1450, which the catalogue writes for a
 * solar or a Hijri year («1265-1352 میلادی», «-1303 میلادی»). Then, by Person::otherCalendarLife:
 *   - life_dates_raw becomes the text, so that a later save reads the same calendar;
 *   - the death year is the first Hijri year the text's death year spans, the range both years, and the Gregorian
 *     death year that year (a solar year + 621); the birth year likewise;
 *   - a century or a lone year with no dash is the century of the life (Person::lifeCentury): no death year or range;
 *   - a year with no Hijri year (before Christ, before the Hijra) empties the Hijri columns and the century.
 * People are found by their works and names, never by id; a run only writes what differs and can be repeated.
 */
class FixCalendarDeathsCommand extends Command
{
    protected $signature = 'fahares:fix-calendar-deaths
        {--file= : Decision file (database/data/fankha_v2_deaths_20261031.json)}
        {--apply : Write the columns; without it the command only counts}
        {--show=8 : How many examples to list per kind}';

    protected $description = 'Convert solar and Gregorian life dates that were stored as Hijri years, and empty the Hijri years of dates before the Hijra';

    /** Gregorian years the catalogue does not mean as Gregorian. */
    private const UNCLEAR = [1000, 1450];

    private const COLUMNS = ['life_dates_raw', 'birth_year_hijri', 'death_year_hijri', 'century_hijri', 'death_year_gregorian',
        'death_from', 'death_to', 'death_certainty', 'death_hedge_raw', 'death_kind'];

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path('data/fankha_v2_deaths_20261031.json');
        $plan = File::exists($path) ? json_decode(File::get($path), true) : null;
        if (! $plan || ! isset($plan['works'])) {
            $this->error("Decision file missing or invalid: {$path}");

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $texts = FillDeathRangesCommand::personTexts($plan, new FankhaWorkLocator(array_merge($plan['works'], $plan['peers'] ?? [])));
        $counts = [];
        $shown = [];
        DB::table('people')->orderBy('id')->select(array_merge(['id', 'name'], self::COLUMNS))
            ->chunkById(5000, function ($people) use ($apply, $texts, &$counts, &$shown) {
                $updates = [];
                foreach ($people as $p) {
                    $old = FixLifeCenturiesCommand::stored($p, self::COLUMNS);
                    $own = (string) $p->life_dates_raw;
                    $candidates = array_values(array_unique(array_filter(array_merge([$own], $texts[$p->id] ?? []), fn ($t) => Person::lifeCalendar($t) !== null)));
                    if (! $candidates) {
                        continue;
                    }
                    $new = null;
                    foreach ($candidates as $text) {
                        if (($new = self::columns($text, $old)) !== null) {
                            break;
                        }
                    }
                    if ($new === null) {
                        $counts['differs'] = ($counts['differs'] ?? 0) + 1;
                        if (($shown['differs'] = ($shown['differs'] ?? 0) + 1) <= (int) $this->option('show')) {
                            $this->line(sprintf('  %-9s #%d «%s» [%s] stored: %s, birth %s, death %s', 'differs', $p->id, $p->name, implode(' | ', $candidates),
                                $own === '' ? 'no life dates' : "«{$own}»", $old['birth_year_hijri'] ?? 'null', $old['death_year_hijri'] ?? 'null'));
                        }
                        continue;
                    }
                    $changed = array_filter($new, fn ($value, $column) => $old[$column] !== $value, ARRAY_FILTER_USE_BOTH);
                    $kind = $changed ? Person::lifeCalendar($new['life_dates_raw']) : 'unchanged';
                    $counts[$kind] = ($counts[$kind] ?? 0) + 1;
                    if (! $changed) {
                        continue;
                    }
                    $updates[$p->id] = $changed;
                    if (($shown[$kind] = ($shown[$kind] ?? 0) + 1) <= (int) $this->option('show')) {
                        $this->line(sprintf('  %-9s #%d «%s» [%s]: %s', $kind, $p->id, $p->name, $new['life_dates_raw'], FixLifeCenturiesCommand::describe($old, $changed)));
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
        $n = fn (string $kind) => $counts[$kind] ?? 0;
        $this->info(($apply ? 'Written' : 'Dry run, nothing written') . ": {$n(Person::SOLAR)} solar, {$n(Person::GREGORIAN)} Gregorian, {$n(Person::BEFORE)} before the Hijra, "
            . "{$n('unchanged')} already right, {$n('differs')} left alone (stored years differ from the text).");

        return self::SUCCESS;
    }

    /**
     * The columns a person should hold by a text of another calendar, or null when the text does not explain what is
     * stored (the class comment).
     *
     * @param  array<string, mixed>  $old
     * @return array<string, mixed>|null
     */
    public static function columns(string $text, array $old): ?array
    {
        $life = Person::otherCalendarLife($text);
        $numbers = $life['numbers'];
        if (! array_filter($numbers)) {
            return null;
        }
        // «1278 میلادی», «-1303 میلادی»: a «Gregorian» year of these centuries is a solar or a Hijri one mislabelled
        foreach (['birth', 'death', 'year'] as $part) {
            if ($life['calendar'] === Person::GREGORIAN && $numbers[$part] >= self::UNCLEAR[0] && $numbers[$part] <= self::UNCLEAR[1]) {
                return null;
            }
            // «- 1081 شمسی»: no catalogue's solar year, a Hijri one mislabelled
            if ($life['calendar'] === Person::SOLAR && $numbers[$part] !== null && \App\Support\HijriYear::otherCalendar($numbers[$part] . 'ش') === null) {
                return null;
            }
        }
        // «1260ق 14 میلادی»: a Hijri year later than the century the text goes on to name
        $range = $life['death'] ?? $life['life'];
        if ($numbers['hijri'] !== null && $range !== null && $numbers['hijri'] > $range[1]) {
            return null;
        }
        $year = $old['death_year_hijri'];
        $birth = $old['birth_year_hijri'];
        $deathYear = $life['death'][0] ?? null;
        $wrongDeath = $year !== null && (in_array($year, [$numbers['death'], $numbers['year'], $numbers['birth'], $numbers['hijri']], true)
            || ($numbers['century'] !== null && $year === $numbers['century'] * 100 - 50));
        $wrongBirth = $birth !== null && $birth === $numbers['birth'];
        $empty = $year === null && $old['life_dates_raw'] === null;
        $own = $old['life_dates_raw'] === $text && ($year === null || $year === $deathYear);
        if (! $wrongDeath && ! $empty && ! $own && ! ($wrongBirth && ($year === null || $year === $deathYear))) {
            return null;
        }
        $century = match (true) {
            $life['death'] !== null => (int) ceil(intdiv($life['death'][0] + $life['death'][1], 2) / 100),
            default => Person::lifeCentury($text) ?? ($life['birth'] ? (int) ceil($life['birth'][0] / 100) : null),
        };
        // a birth year that is the text's own number goes with it; any other stays
        $newBirth = $life['birth'][0] ?? ($wrongBirth || ($numbers['birth'] !== null && $wrongDeath && $birth === $numbers['birth']) ? null : $birth);
        // the range of a death century is not a year
        $deathYear = $numbers['century'] === null ? $deathYear : null;
        $new = ['life_dates_raw' => mb_substr($text, 0, 120), 'birth_year_hijri' => $newBirth, 'death_year_hijri' => $deathYear, 'century_hijri' => $century,
            'death_year_gregorian' => $life['gregorian']] + Person::deathColumns($text, $deathYear);

        return $new + Person::deathKind($new);
    }
}
