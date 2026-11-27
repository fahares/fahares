<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\ManuscriptPerson;
use App\Models\Person;
use App\Models\Work;
use App\Models\WorkPerson;
use App\Suggestions\RecordRemover;
use App\Support\CopyDateRange;
use App\Support\PersonNameMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * People whose «name» is descriptive text (backlog item 15, step 14.435), from database/data/item15_descriptive_people.json:
 * a work's description glued to its author («امام بغوی. نویسنده می‌گوید …»), a scribe with the words around him
 * («از محمد علی آشتیانی», «سید رضا طباطبایی از روی چاپ سنگی …»), a commissioner read as the scribe («به امر …»), or no
 * name at all («مؤلف و دیگران», «قرن 10 و 11»). The file names each person by its exact name (or the name's start, with
 * «prefix»), so the ids of the local copy and of production need not match; the file's head says what each action does.
 * A person is changed only while its name is still the descriptive one, so a second run changes nothing; a person left
 * with no links is removed, and its page says so. Every write is in the audit log.
 */
class FixDescriptivePeopleCommand extends Command
{
    protected $signature = 'fahares:fix-descriptive-people
        {--file=database/data/item15_descriptive_people.json : The decision file}
        {--apply : Write; without it the command only reports}';

    protected $description = 'Backlog item 15: people whose name is descriptive text';

    private const FIX = 'fix-descriptive-people (step 14.435, backlog item 15)';

    private bool $apply = false;

    private array $counts = ['author' => 0, 'scribe' => 0, 'patron' => 0, 'remove' => 0, 'already' => 0, 'ambiguous' => 0, 'people_removed' => 0];

    /** @var array<int, true> people to recount and re-index */
    private array $people = [];

    /** @var array<int, true> */
    private array $works = [];

    /** @var array<int, true> */
    private array $copies = [];

    public function handle(): int
    {
        // a fresh count each run: the container hands out the same command object to each call in one process
        $this->counts = array_map(fn () => 0, $this->counts);
        $this->people = $this->works = $this->copies = [];
        $this->apply = (bool) $this->option('apply');
        $data = json_decode((string) file_get_contents(base_path($this->option('file'))), true, flags: JSON_THROW_ON_ERROR);

        foreach ($data['people'] as $entry) {
            $query = Person::query();
            ! empty($entry['prefix']) ? $query->where('name', 'like', addcslashes($entry['name'], '%_\\') . '%') : $query->where('name', $entry['name']);
            $found = $query->get();
            if ($found->isEmpty()) {
                $this->counts['already']++;
                continue;
            }
            if ($found->count() > 1) {
                $this->counts['ambiguous']++;
                $this->warn('  more than one person: «' . mb_substr($entry['name'], 0, 60) . '» (' . $found->pluck('id')->implode(', ') . ')');
                continue;
            }
            $person = $found->first();
            $this->counts[$entry['action']]++;
            $this->line("  #{$person->id} {$entry['action']}: «" . mb_substr($person->name, 0, 50) . '» → «' . ($entry['link_to'] ?? $entry['to'] ?? '—') . '»');
            if ($this->apply) {
                DB::transaction(fn () => match ($entry['action']) {
                    'author' => $this->author($person, $entry),
                    'scribe' => $this->scribe($person, $entry),
                    'patron' => $this->patron($person, $entry),
                    'remove' => $this->remove($person, $entry),
                });
                $this->removeIfUnlinked($person->fresh());
            }
        }

        if ($this->apply) {
            foreach (Person::whereIn('id', array_keys($this->people))->get() as $p) {
                $p->recountContributions();
                $p->searchable();
            }
            Work::whereIn('id', array_keys($this->works))->get()->searchable();
            Manuscript::whereIn('id', array_keys($this->copies))->get()->searchable();
        }

        $this->info($this->apply ? 'Written.' : 'Dry run: nothing written.');
        foreach ($this->counts as $k => $n) {
            $this->line(sprintf('  %-16s %4d', $k, $n));
        }

        return self::SUCCESS;
    }

    /** A work's author: renamed, or its works moved to the existing person «link_to»; each work gets its work_people row. */
    private function author(Person $person, array $entry): void
    {
        $target = $person;
        if (isset($entry['link_to'])) {
            $target = Person::where('name', $entry['link_to'])->sole();
        } else {
            $this->rename($person, $entry['to']);
        }
        $role = $entry['role'] ?? WorkPerson::AUTHOR;
        $hedge = $entry['hedge'] ?? null;
        $certainty = $entry['certainty'] ?? ($hedge ? PersonNameMatcher::certainty($hedge) : null);

        $workIds = DB::table('work_author')->where('person_id', $person->id)->pluck('work_id')
            ->merge(DB::table('work_people')->where('person_id', $person->id)->pluck('work_id'))->unique();
        foreach ($workIds as $workId) {
            $work = Work::find($workId);
            $row = DB::table('work_people')->where('work_id', $workId)->whereIn('person_id', [$person->id, $target->id])->first();
            $values = ['person_id' => $target->id, 'name' => $target->name, 'role' => $role, 'certainty' => $certainty, 'hedge_raw' => $hedge, 'updated_at' => now()];
            $row ? DB::table('work_people')->where('id', $row->id)->update($values)
                : DB::table('work_people')->insert($values + ['work_id' => $workId, 'position' => 0, 'created_at' => now()]);
            DB::table('work_author')->where('work_id', $workId)->where('person_id', $person->id)->update(['person_id' => $target->id, 'name' => $target->name]);

            $old = ['author_id' => $work->author_id];
            $new = [];
            if ($role === WorkPerson::AUTHOR && in_array($work->author_id, [null, $person->id], true)) {
                $new['author_id'] = $target->id;
            } elseif ($role !== WorkPerson::AUTHOR && $work->author_id === $person->id) {
                $new['author_id'] = null;
            }
            // «مولانا زیرک (؟)»: the doubt is now the row's certainty, as fahares:mark-hedged-authors leaves it
            if ($hedge && isset($entry['to']) && preg_match('/^' . preg_quote($entry['to'], '/') . '\s*\(?\s*' . preg_quote($hedge, '/') . '\s*\)?$/u', (string) $work->author_name)) {
                $old['author_name'] = $work->author_name;
                $new['author_name'] = $entry['to'];
            }
            if ($new) {
                DB::table('works')->where('id', $workId)->update($new + ['updated_at' => now()]);
            }
            AuditLog::create(['user_id' => null, 'auditable_type' => Work::class, 'auditable_id' => $workId, 'action' => 'corpus_update',
                'old_values' => $old + ['person' => $person->name], 'new_values' => $new + ['person_id' => $target->id, 'role' => $role, 'certainty' => $certainty, 'hedge_raw' => $hedge, 'corpus_fix' => self::FIX]]);
            $this->works[$workId] = true;
        }
        $this->people[$target->id] = true;
    }

    /** A scribe: renamed on the person and on the copies; the words around the name go to the link and to the copy's notes. */
    private function scribe(Person $person, array $entry): void
    {
        $oldName = $person->name;
        $this->rename($person, $entry['to']);
        foreach ($this->scribed($person) as $m) {
            DB::table('manuscript_people')->where('manuscript_id', $m->id)->where('person_id', $person->id)->where('role', ManuscriptPerson::SCRIBE)
                ->update(array_filter(['label_raw' => $entry['label_raw'] ?? null, 'note' => $entry['link_note'] ?? null]) + ['updated_at' => now()]);
            $new = [];
            if ($m->scribe_name === $oldName) {
                $new['scribe_name'] = $entry['to'];
            }
            if (isset($entry['patron'])) {
                $this->addPatron($m, $entry['patron'], $entry['patron_label'] ?? null, $new);
            }
            if ($new) {
                DB::table('manuscripts')->where('id', $m->id)->update($new + ['updated_at' => now()]);
            }
            if (isset($entry['note'])) {
                $this->addNote($m->id, $entry['note']);
            }
            AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $m->id, 'action' => 'corpus_update',
                'old_values' => ['scribe_name' => $m->scribe_name, 'commissioned_by' => $m->commissioned_by],
                'new_values' => $new + array_intersect_key($entry, array_flip(['label_raw', 'link_note', 'note'])) + ['corpus_fix' => self::FIX]]);
            $this->copies[$m->id] = true;
        }
        $this->people[$person->id] = true;
    }

    /** A commissioner read as the scribe: the scribe link becomes a name-only patron row and the copy's commissioned_by. */
    private function patron(Person $person, array $entry): void
    {
        foreach ($this->scribed($person) as $m) {
            DB::table('manuscript_people')->where('manuscript_id', $m->id)->where('person_id', $person->id)->where('role', ManuscriptPerson::SCRIBE)->delete();
            $new = $this->unscribe($m, $person);
            $this->addPatron($m, $entry['to'], $entry['label_raw'] ?? null, $new);
            DB::table('manuscripts')->where('id', $m->id)->update($new + ['updated_at' => now()]);
            AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $m->id, 'action' => 'corpus_update',
                'old_values' => ['scribe_id' => $m->scribe_id, 'scribe_name' => $m->scribe_name, 'commissioned_by' => $m->commissioned_by],
                'new_values' => $new + ['patron' => $entry['to'], 'corpus_fix' => self::FIX]]);
            $this->copies[$m->id] = true;
        }
    }

    /** No name at all: the link goes; the words become a copy note, or the copy date when the copy has none. */
    private function remove(Person $person, array $entry): void
    {
        foreach ($this->scribed($person) as $m) {
            DB::table('manuscript_people')->where('manuscript_id', $m->id)->where('person_id', $person->id)->where('role', ManuscriptPerson::SCRIBE)->delete();
            $new = $this->unscribe($m, $person);
            if (isset($entry['copy_date_raw']) && blank($m->copy_date_raw)) {
                $range = CopyDateRange::parse($entry['copy_date_raw']);
                $new += ['copy_date_raw' => $entry['copy_date_raw'], 'copy_date_hijri_year' => $range['from'], 'copy_date_from' => $range['from'],
                    'copy_date_to' => $range['to'], 'copy_date_kind' => $range['kind'], 'copy_date_certainty' => $range['certainty'], 'copy_date_hedge_raw' => $range['hedge']];
            }
            DB::table('manuscripts')->where('id', $m->id)->update($new + ['updated_at' => now()]);
            if (isset($entry['note'])) {
                $this->addNote($m->id, $entry['note']);
            }
            AuditLog::create(['user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $m->id, 'action' => 'corpus_update',
                'old_values' => ['scribe_id' => $m->scribe_id, 'scribe_name' => $m->scribe_name, 'copy_date_raw' => $m->copy_date_raw],
                'new_values' => $new + ['note' => $entry['note'] ?? null, 'corpus_fix' => self::FIX]]);
            $this->copies[$m->id] = true;
        }
    }

    /** The copies a person scribed, through the link table or the copy's own scribe column. */
    private function scribed(Person $person)
    {
        $ids = DB::table('manuscript_people')->where('person_id', $person->id)->where('role', ManuscriptPerson::SCRIBE)->pluck('manuscript_id')
            ->merge(DB::table('manuscripts')->where('scribe_id', $person->id)->pluck('id'))->unique();

        return Manuscript::whereIn('id', $ids)->orderBy('id')->get();
    }

    /** The copy's own scribe columns, when they hold this person */
    private function unscribe(Manuscript $m, Person $person): array
    {
        $new = [];
        if ($m->scribe_id === $person->id) {
            $new['scribe_id'] = DB::table('manuscript_people')->where('manuscript_id', $m->id)->where('role', ManuscriptPerson::SCRIBE)->orderBy('position')->value('person_id');
        }
        if ($m->scribe_name === $person->name) {
            $new['scribe_name'] = null;
        }

        return $new;
    }

    private function addPatron(Manuscript $m, string $name, ?string $label, array &$new): void
    {
        $exists = DB::table('manuscript_people')->where('manuscript_id', $m->id)->where('role', 'patron')->where('name', $name)->exists();
        if (! $exists) {
            DB::table('manuscript_people')->insert(['manuscript_id' => $m->id, 'person_id' => null, 'name' => $name, 'role' => 'patron',
                'label_raw' => $label, 'position' => (int) DB::table('manuscript_people')->where('manuscript_id', $m->id)->where('role', 'patron')->max('position') + 1,
                'created_at' => now(), 'updated_at' => now()]);
        }
        if (blank($m->commissioned_by)) {
            $new['commissioned_by'] = $name;
        }
    }

    private function addNote(int $manuscriptId, string $text): void
    {
        if (DB::table('manuscript_notes')->where('manuscript_id', $manuscriptId)->where('text', $text)->exists()) {
            return;
        }
        DB::table('manuscript_notes')->insert(['manuscript_id' => $manuscriptId, 'type' => 'transcription', 'text' => $text, 'typed_by' => 'rule',
            'position' => (int) DB::table('manuscript_notes')->where('manuscript_id', $manuscriptId)->max('position') + 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function rename(Person $person, string $name): void
    {
        $old = $person->name;
        $person->update(['name' => $name]);
        AuditLog::create(['user_id' => null, 'auditable_type' => Person::class, 'auditable_id' => $person->id, 'action' => 'corpus_update',
            'old_values' => ['name' => $old], 'new_values' => ['name' => $name, 'corpus_fix' => self::FIX]]);
    }

    /** A person nothing links to any more is removed; its page then says so. */
    private function removeIfUnlinked(?Person $person): void
    {
        if (! $person || DB::table('work_author')->where('person_id', $person->id)->exists() || (new RecordRemover)->blockers($person)) {
            return;
        }
        DB::transaction(function () use ($person) {
            $removed = (new RecordRemover)->remove($person);
            AuditLog::create(['user_id' => null, 'auditable_type' => Person::class, 'auditable_id' => $person->id, 'action' => 'remove',
                'old_values' => $removed, 'new_values' => ['corpus_fix' => self::FIX]]);
        });
        $person->unsearchable();
        unset($this->people[$person->id]);
        $this->counts['people_removed']++;
    }
}
