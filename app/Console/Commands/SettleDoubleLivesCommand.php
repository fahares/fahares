<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Services\PersonMerger;
use Illuminate\Console\Command;

/**
 * Settles the lives tied to more than one person (step 14.193), from a judged list keyed by slug, since local and
 * production ids differ: people a model review found to be one are merged into the record kept (through PersonMerger,
 * logged and undoable; the printed names stay as the catalogues give them); a life wrongly tied to someone is rejected,
 * which hides it from his page and keeps sources:tie-lives-to-people from tying it again; the groups the review left
 * unsure go to «تصحیح جمعی» through fahares:queue-judged-people. A record merged since is followed to the one it went
 * into. Safe to re-run. Without --apply it only reports.
 */
class SettleDoubleLivesCommand extends Command
{
    protected $signature = 'fahares:settle-double-lives
        {--file=database/data/double_lives_step14_193.json : The judged list}
        {--apply : Write the changes}';

    protected $description = 'Merge, reject or queue the people tied to the same life, from a judged list';

    public function handle(): int
    {
        $path = base_path($this->option('file'));
        $doc = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        if (! is_array($doc['merges'] ?? null)) {
            $this->error("No decisions in {$path}");

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $counts = ['merged' => 0, 'already_one' => 0, 'missing' => 0, 'rejected' => 0, 'already_rejected' => 0, 'missing_tie' => 0];
        $merger = app(PersonMerger::class);

        foreach ($doc['merges'] as $group) {
            $keep = self::person($group['keep']);
            if (! $keep) {
                $counts['missing']++;
                $this->line("  missing kept person: {$group['keep']}");

                continue;
            }
            foreach ($group['merge'] as $slug) {
                $gone = self::person($slug);
                if (! $gone) {
                    $counts['missing']++;
                    $this->line("  missing person: {$slug}");

                    continue;
                }
                if ($gone->id === $keep->id) {
                    $counts['already_one']++;

                    continue;
                }
                $counts['merged']++;
                if ($apply) {
                    $merger->merge($gone, $keep, null, null, ['reason' => 'fahares:settle-double-lives (step 14.193)'], keepPrintedNames: true);
                    $keep->refresh();
                }
            }
        }

        $sources = Source::where('kind', Source::KIND_BIOGRAPHICAL)->get()->keyBy('code');
        foreach ($doc['rejects'] ?? [] as $r) {
            $person = self::person($r['person_slug']);
            $item = isset($sources[$r['source']]) ? SourceItem::where('source_id', $sources[$r['source']]->id)
                ->where('item_key', $r['item_key'])->where('volume_number', $r['volume'])->first() : null;
            $tie = $person && $item ? SourceEntry::where('citable_type', Person::class)->where('citable_id', $person->id)
                ->where('source_item_id', $item->id)->first() : null;
            if (! $tie) {
                $counts['missing_tie']++;
                $this->line("  missing tie: {$r['person_slug']} {$r['source']} {$r['item_key']}");

                continue;
            }
            if ($tie->status === SourceEntry::STATUS_REJECTED) {
                $counts['already_rejected']++;

                continue;
            }
            $counts['rejected']++;
            if ($apply) {
                $tie->update(['status' => SourceEntry::STATUS_REJECTED, 'reviewed_at' => now()]);
            }
        }
        $this->table(array_keys($counts), [array_values($counts)]);

        // the unsure groups, by the records' ids as they are now, for the queue command
        $groups = collect($doc['unsure'] ?? [])->map(function ($g) {
            $people = collect($g['slugs'])->map(fn ($s) => self::person($s))->filter()->unique('id');

            return ['ids' => $people->pluck('id')->sort()->values()->all(), 'names' => $people->pluck('name', 'id')->all(), 'note' => $g['note']];
        })->filter(fn ($g) => count($g['ids']) > 1)->values();
        $queue = storage_path('app/double_lives_unsure.json');
        file_put_contents($queue, json_encode(['groups' => $groups], JSON_UNESCAPED_UNICODE));
        $this->call('fahares:queue-judged-people', ['--file' => $queue] + ($apply ? [] : ['--dry-run' => true]));
        @unlink($queue);

        $this->info($apply ? 'Saved.' : 'Dry run; pass --apply to write.');

        return self::SUCCESS;
    }

    /** The person of a slug, or the one he was merged into since. */
    private static function person(string $slug): ?Person
    {
        $person = Person::where('slug', $slug)->first() ?? TiePersonLivesCommand::mergedInto($slug);

        return $person ? Person::find($person->id) : null;
    }
}
