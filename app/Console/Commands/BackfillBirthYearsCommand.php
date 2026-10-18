<?php

namespace App\Console\Commands;

use App\Models\Person;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills people.birth_year_hijri from the author line of each work's catalog text
 * («جامی، عبدالرحمن بن احمد، 817 - 898 قمری»). The first import kept only the death year,
 * so no person had a birth year. A year is written only when the line's death year equals the
 * person's recorded death year, the span is plausible, and every work of the person agrees.
 */
class BackfillBirthYearsCommand extends Command
{
    protected $signature = 'people:backfill-birth-years
        {--dry-run : Count and list what would change without writing}
        {--only= : Comma-separated person ids to limit the run to}';

    protected $description = 'Fill missing Hijri birth years of authors from the catalog text of their works';

    // «817 - 898 قمری» in the first lines of the entry; solar or Gregorian ranges are not matched
    private const RANGE = '/(?<!\d)(\d{2,4})\s*[-–]\s*(\d{2,4})\s*قمری/u';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = array_filter(array_map('intval', explode(',', (string) $this->option('only'))));

        $candidates = [];
        $query = DB::table('works')
            ->join('people', 'people.id', '=', 'works.author_id')
            ->whereNull('people.birth_year_hijri')
            ->whereNotNull('people.death_year_hijri')
            ->whereNotNull('works.raw_text')
            ->select('works.id as work_id', 'works.raw_text', 'people.id as person_id', 'people.death_year_hijri');
        if ($only) {
            $query->whereIn('people.id', $only);
        }

        $query->orderBy('works.id')->chunk(2000, function ($rows) use (&$candidates) {
            foreach ($rows as $row) {
                $head = implode("\n", array_slice(preg_split('/\R/u', preg_replace('/<!--\s*page:\s*\d+\s*-->/u', '', $row->raw_text)), 0, 8));
                $head = strtr($head, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
                if (! preg_match(self::RANGE, $head, $m)) {
                    continue;
                }
                [$birth, $death] = [(int) $m[1], (int) $m[2]];
                if ($death !== (int) $row->death_year_hijri || $birth >= $death || $death - $birth > 120) {
                    continue;
                }
                $candidates[$row->person_id][$birth] = ($candidates[$row->person_id][$birth] ?? 0) + 1;
            }
        });

        $updates = [];
        $conflicts = 0;
        foreach ($candidates as $personId => $years) {
            if (count($years) > 1) {
                $conflicts++;
                continue;
            }
            $updates[$personId] = array_key_first($years);
        }

        foreach (array_slice($updates, 0, 10, true) as $personId => $birth) {
            $this->line("#{$personId}: {$birth}");
        }
        $this->info(sprintf('%s birth years %s; %d people skipped because their works disagree.',
            number_format(count($updates)), $dry ? 'would be filled' : 'filled', $conflicts));

        if (! $dry && $updates) {
            DB::transaction(function () use ($updates) {
                foreach ($updates as $personId => $birth) {
                    Person::whereKey($personId)->whereNull('birth_year_hijri')->update(['birth_year_hijri' => $birth]);
                }
            });
            if (config('scout.driver') && config('scout.driver') !== 'null') {
                Person::whereIn('id', array_keys($updates))->searchable();
            }
        }

        return self::SUCCESS;
    }
}
