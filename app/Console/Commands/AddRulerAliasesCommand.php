<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Models\AuditLog;
use App\Models\Person;
use App\Support\KnownRulers;
use App\Support\PersonNameMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Adds the spellings the Fankha «اهدا / به درخواست» field uses for a ruler of KnownRulers::LIST («ناصرالدین شاه»,
 * «سلطان سنجر») to the other names of the ruler's authority record, so that a search in that spelling finds him
 * (backlog item 35). Only LIST spellings, which name one ruler; those of USUALLY («شاه عباس صفوی») name more than one
 * and are not added. A spelling already among the person's name and other names (compared without spaces) is
 * skipped, and other names are only added to, so a run can be repeated. Without --apply it only counts.
 */
class AddRulerAliasesCommand extends Command
{
    protected $signature = 'fahares:add-ruler-aliases
        {--apply : Write; without it the command only counts}';

    protected $description = 'Add the dedication field\'s spellings of known rulers to their other names';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $matcher = new PersonNameMatcher;
        $counts = ['rulers' => 0, 'not_found' => 0, 'ambiguous' => 0, 'people_changed' => 0, 'aliases_added' => 0];

        foreach (KnownRulers::LIST as $ruler) {
            $counts['rulers']++;
            $ids = $matcher->ids(TextNormalizer::normalize($ruler['name']));
            if (count($ids) !== 1) {
                $counts[$ids ? 'ambiguous' : 'not_found']++;
                $this->warn(($ids ? 'Several records' : 'No record') . " for {$ruler['name']}" . ($ids ? ': #' . implode(', #', $ids) : ''));
                continue;
            }
            $person = Person::find($ids[0]);
            $aliases = array_values((array) ($person->aliases ?? []));
            $known = array_map([KnownRulers::class, 'compact'], array_merge([$person->name], $aliases));
            $added = [];
            foreach ($ruler['spellings'] as $spelling) {
                $key = KnownRulers::compact($spelling);
                if (! in_array($key, $known, true)) {
                    $added[] = $spelling;
                    $known[] = $key;
                }
            }
            if (! $added) {
                continue;
            }
            $counts['people_changed']++;
            $counts['aliases_added'] += count($added);
            $this->line("#{$person->id} {$person->name}: + " . implode('، ', $added));
            if ($apply) {
                DB::transaction(function () use ($person, $aliases, $added) {
                    $person->aliases = array_merge($aliases, $added);
                    $person->save();
                    AuditLog::create(['user_id' => null, 'auditable_type' => Person::class, 'auditable_id' => $person->id, 'action' => 'corpus_update',
                        'old_values' => ['aliases' => $aliases], 'new_values' => ['aliases' => $person->aliases, 'corpus_fix' => 'ruler spellings (item 35)']]);
                });
            }
        }

        $this->info($apply ? 'Written.' : 'Dry run: nothing written.');
        foreach ($counts as $kind => $n) {
            $this->line(sprintf('  %-16s %5d', $kind, $n));
        }

        return self::SUCCESS;
    }
}
