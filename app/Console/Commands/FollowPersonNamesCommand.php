<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Models\AuditLog;
use App\Models\Person;
use App\Models\Work;
use App\Support\PersonNames;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Works whose author text is not their linked person's name (step 14.295). A person renamed before the model began
 * to carry the new name to their works left those works with the old one; other differences have other causes and are
 * only counted, each kind apart, so that the owner decides which to change:
 *
 *   renamed   the text is a name the person had before, by the audit log of the person (a reviewed rename or an
 *             edit in the panel), and the work has one author: written with --apply
 *   alias     the text is one of the person's other names or misspelt forms: written with --apply --aliases
 *   spelling  the text and the name differ only in what the search normalises (ي/ی, ك/ک, diacritics, spacing)
 *   several   the work has more than one author, and the text names them all: never written
 *   other     anything else: a longer or hedged wording, or a link made on other grounds
 *
 * Without --apply it only reports. Each written work is logged (PersonNames) and reindexed. Safe to run again.
 * --reindex makes the search document of every work counted here again, written or not: since this step the document
 * holds the linked person's name as it is now, which these works' documents made before it lack.
 */
class FollowPersonNamesCommand extends Command
{
    protected $signature = 'fahares:follow-person-names
        {--apply : Write the «renamed» works; without it the command only reports}
        {--aliases : With --apply, write the «alias» works too}
        {--reindex : Make the search documents of all the works counted again (they gain the present name of the person)}
        {--person= : Only the works of this person (its id)}
        {--show=8 : How many examples to list per kind}';

    protected $description = 'Report, and with --apply correct, works whose author text is an earlier name of their linked person';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $counts = ['renamed' => 0, 'alias' => 0, 'spelling' => 0, 'several' => 0, 'other' => 0];
        $examples = [];
        $written = 0;
        $reindexed = 0;
        // every name each person had before, from the person's own audit logs
        $former = [];
        AuditLog::where('auditable_type', Person::class)->whereIn('action', ['verify', 'update'])
            ->when($this->option('person'), fn ($q, $id) => $q->where('auditable_id', (int) $id))
            ->select(['id', 'auditable_id', 'old_values'])->orderBy('id')
            ->each(function (AuditLog $log) use (&$former) {
                $name = trim((string) ($log->old_values['name'] ?? ''));
                if ($name !== '') {
                    $former[$log->auditable_id][$name] = true;
                }
            });

        DB::table('works')->join('people', 'people.id', '=', 'works.author_id')
            ->whereColumn('works.author_name', '!=', 'people.name')
            ->when($this->option('person'), fn ($q, $id) => $q->where('people.id', (int) $id))
            ->select(['works.id', 'works.author_name', 'works.has_other_authors', 'works.primary_title', 'people.id as person_id', 'people.name', 'people.aliases', 'people.wrong_names'])
            ->orderBy('works.id')
            ->chunk(1000, function ($rows) use (&$counts, &$examples, &$written, &$reindexed, $former, $apply) {
                if ($this->option('reindex')) {
                    Work::whereIn('id', $rows->pluck('id'))->with('author')->get()->each->searchable();
                    $reindexed += $rows->count();
                }
                foreach ($rows as $row) {
                    $text = trim((string) $row->author_name);
                    $others = array_map('trim', array_merge((array) json_decode($row->aliases ?? '[]', true), (array) json_decode($row->wrong_names ?? '[]', true)));
                    $kind = match (true) {
                        (bool) $row->has_other_authors => 'several',
                        isset($former[$row->person_id][$text]) => 'renamed',
                        in_array($text, $others, true) => 'alias',
                        TextNormalizer::normalize($text) === TextNormalizer::normalize((string) $row->name) => 'spelling',
                        default => 'other',
                    };
                    $counts[$kind]++;
                    if (count($examples[$kind] ?? []) < (int) $this->option('show')) {
                        $examples[$kind][] = "#{$row->id} {$row->primary_title}: «{$text}» → «{$row->name}» (person #{$row->person_id})";
                    }
                    if ($apply && ($kind === 'renamed' || ($kind === 'alias' && $this->option('aliases')))) {
                        $written += (int) PersonNames::followWork(Work::findOrFail($row->id), Person::findOrFail($row->person_id));
                    }
                }
            });

        foreach ($counts as $kind => $n) {
            $this->line(sprintf('%-9s %d', $kind, $n));
            foreach ($examples[$kind] ?? [] as $example) {
                $this->line('    ' . $example);
            }
        }
        if ($reindexed) {
            $this->line("Reindexed: {$reindexed} works.");
        }
        $this->info($apply ? "Done: {$written} works written." : 'Dry run: nothing written. Use --apply for the «renamed» works.');

        return self::SUCCESS;
    }
}
