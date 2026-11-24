<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\PersonPeriod;
use Illuminate\Console\Command;

/**
 * Carries the year of an approved «birth» or «death» row of a person's life (person_periods, steps 14.291 and 14.359)
 * to the person's own record when the record has no such year (step 14.363, backlog item 139). The rows were read from
 * biographical dictionaries and judged one by one; the record's years came from the catalogues, which often give only
 * a century («ق 13 قمری») or nothing.
 *
 * A year is carried only when it is one year, every approved row of that kind of the person gives the same one, and it
 * fits what the record already says:
 *   - a birth is not carried against another birth year in the record's life dates («131؟ - 235 قمری»);
 *   - a birth stands 10 to 120 years before the record's death (or the death carried in the same run), and with no
 *     death known it falls in the record's century or the one before;
 *   - a death falls in the record's century or the one after (a man of «ق 13» may have died in 1307), and after the
 *     record's birth.
 * A death that moves the record's century on keeps the old century as the first of the life (century_from).
 * A record whose year is set is never touched, and a death year the person's own life dates rule out (a lone year,
 * Person::isNoDeathYear) is left alone. Every change is written to the audit log under the person. Safe to run again.
 */
class CarryLifeYearsCommand extends Command
{
    protected $signature = 'fahares:carry-life-years
        {--apply : Write the years; without it the command only counts}
        {--show=0 : Print this many of the carried and of the skipped years}';

    protected $description = 'Carry birth and death years of approved life rows to people whose record has none';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $show = (int) $this->option('show');
        $rows = PersonPeriod::query()->where('status', PersonPeriod::APPROVED)->whereIn('kind', ['birth', 'death'])
            ->whereNotNull('period_from')->orderBy('id')->get(['id', 'person_id', 'kind', 'period_from', 'period_to', 'source_text']);
        $counts = ['birth' => 0, 'death' => 0];
        $skipped = [];
        $lines = [];
        foreach ($rows->groupBy('person_id') as $personId => $ofPerson) {
            $person = Person::find($personId);
            if ($person === null) {
                continue;
            }
            $old = $person->only(['birth_year_hijri', 'death_year_hijri', 'century_hijri', 'century_from', 'death_from', 'death_to']);
            $from = [];
            // the death first: the birth is checked against it
            foreach (['death', 'birth'] as $kind) {
                $column = "{$kind}_year_hijri";
                $ofKind = $ofPerson->where('kind', $kind);
                if ($ofKind->isEmpty() || $person->{$column}) {
                    continue;
                }
                $why = $this->unfit($kind, $ofKind, $person);
                if ($why !== null) {
                    $skipped[$why] = ($skipped[$why] ?? 0) + 1;
                    $lines[] = "  skipped {$kind} #{$personId} {$person->name}: {$why}";
                    continue;
                }
                $person->{$column} = (int) $ofKind->first()->period_from;
                $from[$kind] = $ofKind->pluck('id')->all();
            }
            if ($from === []) {
                continue;
            }
            foreach (array_keys($from) as $kind) {
                $counts[$kind]++;
                $lines[] = "  {$kind} #{$personId} {$person->name}: " . $person->{"{$kind}_year_hijri"} . ' (' . $ofPerson->firstWhere('kind', $kind)->source_text . ')';
            }
            if (! $apply) {
                continue;
            }
            // a man of «ق 13» who died in 1307 still began in the thirteenth century
            if (isset($from['death']) && ! $person->birth_year_hijri && $old['century_hijri'] && ! $old['century_from']
                && (int) $old['century_hijri'] < (int) ceil($person->death_year_hijri / 100)) {
                $person->century_from = (int) $old['century_hijri'];
            }
            $person->save();
            AuditLog::create([
                'user_id' => null,
                'auditable_type' => Person::class,
                'auditable_id' => $person->id,
                'action' => 'update',
                'old_values' => $old,
                'new_values' => $person->fresh()->only(array_keys($old)) + ['by' => 'fahares:carry-life-years', 'person_periods' => $from],
            ]);
        }

        $this->info($apply ? 'Years written.' : 'Dry run: nothing written.');
        $this->line(sprintf('  %-34s %5d', 'birth years carried', $counts['birth']));
        $this->line(sprintf('  %-34s %5d', 'death years carried', $counts['death']));
        ksort($skipped);
        foreach ($skipped as $why => $n) {
            $this->line(sprintf('  %-34s %5d', "skipped: {$why}", $n));
        }
        foreach ($show > 0 ? array_slice($lines, 0, $show) : [] as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /** Why the year of these rows is not carried to the person, or null when it is. */
    private function unfit(string $kind, \Illuminate\Support\Collection $rows, Person $person): ?string
    {
        $years = $rows->map(fn ($row) => [(int) $row->period_from, (int) ($row->period_to ?? $row->period_from)])->unique()->values();
        if ($years->count() > 1) {
            return 'the rows differ';
        }
        [$year, $to] = $years[0];
        if ($year !== $to) {
            return 'not one year';
        }
        $century = $person->century_hijri ? (int) $person->century_hijri : null;
        $of = (int) ceil($year / 100);
        if ($kind === 'death') {
            if (Person::isNoDeathYear($person->life_dates_raw, $year)) {
                return 'the life dates rule the year out';
            }
            if ($person->death_from !== null) {
                return 'the record has a death range';
            }
            if ($person->birth_year_hijri && ($year - $person->birth_year_hijri < 10 || $year - $person->birth_year_hijri > 120)) {
                return 'does not fit the birth';
            }

            return $century !== null && ! in_array($of, [$century, $century + 1], true) ? 'another century' : null;
        }
        // «131؟ - 235 قمری»: the record's own text gives another birth
        $text = strtr((string) $person->life_dates_raw, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        if (preg_match('/^\s*(\d{2,4})\s*؟?\s*ق?\s*[-–]/u', $text, $m) === 1 && (int) $m[1] !== $year && Person::lifeCalendar($person->life_dates_raw) === null) {
            return 'the life dates give another birth';
        }
        $death = $person->death_year_hijri ?: $person->death_to;
        if ($death) {
            return $death - $year < 10 || $death - $year > 120 ? 'does not fit the death' : null;
        }

        return $century !== null && ! in_array($of, [$century - 1, $century], true) ? 'another century' : null;
    }
}
