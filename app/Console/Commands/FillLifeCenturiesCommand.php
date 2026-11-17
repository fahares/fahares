<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Suggestions\ProtectedColumns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings century_from, the first century of a life that lies in more than one, in step with the person's years
 * (step 14.247, Person::centuryFrom). Two kinds of row:
 *   - filled: the birth year lies in a century before the person's own (born in 970, century 11: 10);
 *   - emptied: a stored value the years no longer give, or one that is not before the person's century.
 * A value a reviewer set is left alone (ProtectedColumns), as is that of a person with no birth year. The model's
 * saving hook keeps the column for a person saved through it; this is for the rows that were there before the
 * column, and for those a command writes by a query. A run only writes what differs and can be repeated. The search
 * documents are not written: reindex the people after it.
 */
class FillLifeCenturiesCommand extends Command
{
    protected $signature = 'fahares:fill-life-centuries
        {--apply : Write the column; without it the command only lists}';

    protected $description = 'Fill the first century of the life of the people whose birth year lies before their century';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $guard = ProtectedColumns::for(Person::class);
        $counts = ['filled' => 0, 'emptied' => 0];
        DB::table('people')->where(fn ($q) => $q->whereNotNull('birth_year_hijri')->orWhereNotNull('century_from'))->orderBy('id')
            ->select(['id', 'name', 'birth_year_hijri', 'death_year_hijri', 'century_hijri', 'century_from'])
            ->chunkById(5000, function ($people) use ($apply, $guard, &$counts) {
                $updates = [];
                foreach ($people as $p) {
                    $int = fn ($value) => $value === null ? null : (int) $value;
                    $old = $int($p->century_from);
                    $new = Person::centuryFrom($int($p->birth_year_hijri), $int($p->century_hijri), $old);
                    if ($new === $old || ! $guard->strip((int) $p->id, ['century_from' => $new])) {
                        continue;
                    }
                    $counts[$new === null ? 'emptied' : 'filled']++;
                    $updates[$p->id] = $new;
                    $this->line(sprintf('  #%d «%s» [%s - %s, century %s]: century_from %s → %s', $p->id, $p->name, $p->birth_year_hijri ?? '?',
                        $p->death_year_hijri ?? '?', $p->century_hijri ?? 'null', $old ?? 'null', $new ?? 'null'));
                }
                if ($apply && $updates) {
                    DB::transaction(function () use ($updates) {
                        foreach ($updates as $id => $century) {
                            DB::table('people')->where('id', $id)->update(['century_from' => $century]);
                        }
                    });
                }
            });
        $guard->printTo($this, 'person');
        $this->info(($apply ? 'Written' : 'Dry run, nothing written') . ": {$counts['filled']} first centuries filled, {$counts['emptied']} emptied.");

        return self::SUCCESS;
    }
}
