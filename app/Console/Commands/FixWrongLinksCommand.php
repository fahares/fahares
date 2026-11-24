<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use App\Suggestions\RecordSplitter;
use App\Suggestions\SuggestionApplier;
use App\Suggestions\SuggestionRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Mends links of an author or a scribe that the catalogue itself has wrong (backlog item 126, step 14.341): a person
 * whose death year the books of lives confirm, with a work or a copy dated after it. The decision file names each
 * record by its texts (title, author, library and shelfmark), never by id, with the evidence in «why». Three kinds:
 *   - works: the work is another person's («to», a person of the site, or «create», a new one) or no known person's
 *     («to»: null). The copies in the author's own hand follow: their scribe becomes the new author, or no one;
 *   - not_autograph: a copy the catalogue calls «کاتب = مؤلف» that was written after the author's death. The scribe
 *     link and the autograph mark go;
 *   - scribes: a scribe who is a namesake of the linked person. The copies that name the scribe move to a new
 *     person (RecordSplitter); the person's own autographs stay.
 * Every change goes through the site's own correction code (SuggestionApplier) and is logged as a «verify» entry, so
 * that a later sync from the corpus leaves the corrected columns alone (ProtectedColumns). A run only writes what
 * differs and can be repeated; a record that is no longer as the file describes it is reported and left alone.
 */
class FixWrongLinksCommand extends Command
{
    protected $signature = 'fahares:fix-wrong-links
        {--file= : Decision file (database/data/wrong_links_step14_341.json)}
        {--apply : Write the changes; without it the command only lists}';

    protected $description = 'Mend the author and scribe links that the catalogue has wrong, by a decision file';

    private const STEP = '14.341';

    private array $counts = ['changed' => 0, 'unchanged' => 0, 'differs' => 0];

    public function handle(SuggestionApplier $applier, RecordSplitter $splitter): int
    {
        $path = $this->option('file') ?: database_path('data/wrong_links_step14_341.json');
        $plan = File::exists($path) ? json_decode(File::get($path), true) : null;
        if (! is_array($plan)) {
            $this->error("Decision file missing or invalid: {$path}");

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $this->counts = ['changed' => 0, 'unchanged' => 0, 'differs' => 0];
        foreach ($plan['works'] ?? [] as $entry) {
            $this->work($entry, $apply, $applier);
        }
        foreach ($plan['not_autograph'] ?? [] as $entry) {
            $this->notAutograph($entry, $apply, $applier);
        }
        foreach ($plan['scribes'] ?? [] as $entry) {
            $this->scribe($entry, $apply, $splitter);
        }
        $this->info(($apply ? 'Written' : 'Dry run, nothing written') . ": {$this->counts['changed']} changed, {$this->counts['unchanged']} already right, {$this->counts['differs']} not as the file says.");

        return self::SUCCESS;
    }

    private function work(array $entry, bool $apply, SuggestionApplier $applier): void
    {
        $target = $entry['to'] ?? $entry['create'] ?? null;
        $label = "work «{$entry['title']}»";
        $works = Work::where('primary_title', $entry['title'])->get();
        // already mended: the work of that title has the new author, or none, and a «verify» entry of this step
        $done = $works->first(fn (Work $w) => ($target === null ? $w->author_id === null : $w->author?->name === $target) && $this->logged($w));
        if ($done) {
            $this->note('unchanged', "{$label}: " . ($target ?? 'no author'));

            return;
        }
        $work = $works->first(fn (Work $w) => $w->author?->name === $entry['author']);
        $new = match (true) {
            $target === null => null,
            isset($entry['create']) => Person::where('name', $entry['create'])->first(),
            default => Person::where('name', $entry['to'])->first(),
        };
        if (! $work || (isset($entry['to']) && $entry['to'] !== null && ! $new)) {
            $this->note('differs', "{$label}: no work of «{$entry['author']}» with this title" . ($work ? ", or no person «{$target}»" : ''));

            return;
        }
        $old = $work->author;
        $copies = Manuscript::where('work_id', $work->id)->where('is_autograph', true)->where('scribe_id', $old->id)->get();
        $this->note('changed', "{$label}: author «{$old->name}» → " . ($target === null ? 'no one' : "«{$target}»" . ($new ? '' : ' (new person)'))
            . ($copies->isEmpty() ? '' : '; autograph copies: ' . $copies->map(fn ($c) => "{$c->library} {$c->shelfmark}")->implode(', ')));
        if (! $apply) {
            return;
        }
        DB::transaction(function () use ($work, $entry, $target, $new, $copies, $applier) {
            $input = match (true) {
                $target === null => ['applied_value' => null, 'person_action' => 'clear'],
                $new !== null => ['applied_value' => $new->name, 'person_action' => 'link', 'person_id' => $new->id],
                default => ['applied_value' => $target, 'person_action' => 'create'],
            };
            $result = $applier->apply($work, SuggestionRegistry::fieldOf($work, 'author_name'), $input);
            $this->log($work, $result, $entry['why']);
            $authorId = $work->fresh()->author_id;
            foreach ($copies as $copy) {
                // the author's own hand: the scribe is the work's author, whoever that now is
                $field = SuggestionRegistry::fieldOf($copy, 'scribe_name');
                $result = $authorId
                    ? $applier->apply($copy, $field, ['applied_value' => $copy->scribe_name, 'person_action' => 'link', 'person_id' => $authorId])
                    : $applier->apply($copy, $field, ['applied_value' => null, 'person_action' => 'clear']);
                if (! $copy->is_autograph) {
                    // «کاتب = مؤلف» still holds of a work whose author is not known
                    $copy->update(['is_autograph' => true]);
                    unset($result['old']['is_autograph'], $result['new']['is_autograph']);
                }
                $this->log($copy, $result, $entry['why']);
            }
        });
    }

    private function notAutograph(array $entry, bool $apply, SuggestionApplier $applier): void
    {
        $label = "copy «{$entry['title']}» {$entry['library']} {$entry['shelfmark']}";
        $copy = Manuscript::where('library', $entry['library'])->where('shelfmark', $entry['shelfmark'])
            ->whereHas('work', fn ($q) => $q->where('primary_title', $entry['title']))->first();
        if (! $copy) {
            $this->note('differs', "{$label}: not found");

            return;
        }
        if (! $copy->is_autograph && ! $copy->scribe_id) {
            $this->note('unchanged', "{$label}: not an autograph");

            return;
        }
        $this->note('changed', "{$label}: autograph mark and scribe «" . ($copy->scribe?->name ?? '') . '» removed');
        if (! $apply) {
            return;
        }
        DB::transaction(function () use ($copy, $entry, $applier) {
            $result = $applier->apply($copy, SuggestionRegistry::fieldOf($copy, 'scribe_name'), ['applied_value' => null, 'person_action' => 'clear']);
            $this->log($copy, $result, $entry['why']);
        });
    }

    private function scribe(array $entry, bool $apply, RecordSplitter $splitter): void
    {
        $label = "scribe «{$entry['scribe_name']}»";
        $person = Person::where('name', $entry['person'])->first();
        $copies = $person ? Manuscript::where('scribe_id', $person->id)->where('scribe_name', $entry['scribe_name'])->where('is_autograph', false)->pluck('id')->all() : [];
        if (! $copies) {
            $exists = Person::where('name', $entry['new_name'])->exists();
            $this->note($exists ? 'unchanged' : 'differs', "{$label}: " . ($exists ? "already «{$entry['new_name']}»" : "no copies of «{$entry['person']}» name this scribe"));

            return;
        }
        $this->note('changed', "{$label}: " . count($copies) . " copies move from «{$person->name}» to a new person «{$entry['new_name']}»");
        if (! $apply) {
            return;
        }
        DB::transaction(function () use ($person, $entry, $copies, $splitter) {
            $result = $splitter->splitPerson($person, ['name' => $entry['new_name']], ['manuscripts' => $copies]);
            AuditLog::create(['user_id' => null, 'auditable_type' => Person::class, 'auditable_id' => $person->id, 'action' => 'split',
                'old_values' => $result, 'new_values' => ['created_id' => $result['created_id'], 'step' => self::STEP, 'why' => $entry['why']]]);
        });
    }

    /** The «verify» entry that protects the corrected columns from a later sync, with the step and the evidence. */
    private function log(Work|Manuscript $record, array $result, string $why): void
    {
        AuditLog::create(['user_id' => null, 'auditable_type' => $record::class, 'auditable_id' => $record->id, 'action' => 'verify',
            'old_values' => $result['old'] ?: null, 'new_values' => ($result['new'] ?: []) + ['direct_edit' => true, 'step' => self::STEP, 'why' => $why]]);
    }

    private function logged(Work $work): bool
    {
        return AuditLog::where('auditable_type', Work::class)->where('auditable_id', $work->id)->where('action', 'verify')->get(['new_values'])
            ->contains(fn (AuditLog $log) => ($log->new_values['step'] ?? null) === self::STEP);
    }

    private function note(string $kind, string $line): void
    {
        $this->counts[$kind]++;
        $this->line(sprintf('  %-9s %s', $kind, $line));
    }
}
