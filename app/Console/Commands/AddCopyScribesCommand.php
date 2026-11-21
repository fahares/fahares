<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\ManuscriptPerson;
use App\Models\Person;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the scribes after the first to copies (backlog item 87, step 14.294) from a file the corpus makes
 * (fankha_v2_copy_scribes_export.py): {"records": [{"id", "shelfmark", "first": {"from", "to", "note"?},
 * "scribes": [{"name", "note"?}]}]}. Parser v2.6.0 reads these from the copy's own text: the hands of an album of
 * calligraphy, the scribe of each part, or the first scribe's name printed in two pieces («رفسنجانی، یوسف»).
 *
 * A copy is written only when it has one scribe row and its scribe_name is the file's «from» name or the whole list
 * the site took for one name («درویش در 1182ق، محمدهاشم بن …، محمد شفیع تبریزی در 1230ق»: it begins with the first
 * scribe and holds every other), so a copy a reviewer gave scribes to is left alone. The first scribe's own name goes
 * to scribe_name and to its row, which leaves the person named by the whole list; a new scribe is a row after it.
 * A name of three words or more is linked to the one person with exactly that name, or to a new person; a shorter
 * name («عبدالمجید», «محمد هاشم») stays a name with no person, as it could be anyone of that name. The people's
 * counts and the search documents follow. Safe to run again.
 */
class AddCopyScribesCommand extends Command
{
    protected $signature = 'fahares:add-copy-scribes
        {--file= : The JSON file of the corpus}
        {--apply : Write; without it the command only counts}';

    protected $description = 'Add the second and later scribes of copies, from parser v2';

    public function handle(): int
    {
        $data = json_decode((string) @file_get_contents((string) $this->option('file')), true);
        if (! isset($data['records'])) {
            $this->error('File not found or without records: ' . $this->option('file'));

            return 1;
        }
        $apply = (bool) $this->option('apply');
        $counts = [];
        $added = $linked = $created = 0;
        $people = [];
        $copies = [];
        foreach ($data['records'] as $rec) {
            $outcome = $this->outcome($rec);
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            if ($outcome !== 'write') {
                if (! in_array($outcome, ['already'], true)) {
                    $this->line("#{$rec['id']}: {$outcome}");
                }

                continue;
            }
            if ($this->output->isVerbose()) {
                $this->line("#{$rec['id']}: write");
            }
            $rows = DB::table('manuscript_people')->where('manuscript_id', $rec['id'])->where('role', ManuscriptPerson::SCRIBE)->orderBy('position')->get();
            $plan = [];
            foreach ($rec['scribes'] as $i => $scribe) {
                [$personId, $how] = $this->person($scribe['name'], $apply);
                $plan[] = ['person_id' => $personId, 'name' => $personId ? null : $scribe['name'], 'note' => $scribe['note'] ?? null, 'position' => 1 + $i, 'how' => $how];
                $added++;
                $linked += $how === 'linked';
                $created += $how === 'created';
            }
            if (! $apply) {
                continue;
            }
            DB::transaction(function () use ($rec, $rows, $plan, &$people) {
                $copy = DB::table('manuscripts')->where('id', $rec['id'])->first(['scribe_name', 'scribe_id']);
                $old = ['scribe_name' => $copy->scribe_name, 'scribes' => $this->names($rec['id'])];
                // a copy with a scribe's name and no row for him yet: his row comes first
                if ($rows->isEmpty()) {
                    DB::table('manuscript_people')->insert([
                        'manuscript_id' => $rec['id'], 'person_id' => $copy->scribe_id, 'name' => $copy->scribe_id ? null : mb_substr((string) $copy->scribe_name, 0, 600),
                        'role' => ManuscriptPerson::SCRIBE, 'position' => 0, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $first = DB::table('manuscript_people')->where('manuscript_id', $rec['id'])->where('role', ManuscriptPerson::SCRIBE)->orderBy('position')->first();
                $to = $rec['first']['to'] ?? $rec['first']['from'];
                $renamed = $this->key($copy->scribe_name) !== $this->key($to);
                if ($first && ($renamed || ! empty($rec['first']['note']))) {
                    $set = ['updated_at' => now()];
                    if (! empty($rec['first']['note'])) {
                        $set['note'] = mb_substr($rec['first']['note'], 0, 500);
                    }
                    if ($renamed) {
                        // the row's person is named by the old text (a nisba alone, or the whole list): the first
                        // scribe gets the person of his own name, or stays a name
                        [$personId] = $this->person($to, true);
                        $people[] = $first->person_id;
                        $people[] = $personId;
                        $set += ['person_id' => $personId, 'name' => $personId ? null : mb_substr($to, 0, 600)];
                        DB::table('manuscripts')->where('id', $rec['id'])->update(['scribe_name' => mb_substr($to, 0, 600), 'scribe_id' => $personId, 'updated_at' => now()]);
                    }
                    DB::table('manuscript_people')->where('id', $first->id)->update($set);
                }
                foreach ($plan as $row) {
                    DB::table('manuscript_people')->insert([
                        'manuscript_id' => $rec['id'], 'person_id' => $row['person_id'], 'name' => $row['name'] === null ? null : mb_substr($row['name'], 0, 600),
                        'role' => ManuscriptPerson::SCRIBE, 'note' => $row['note'] === null ? null : mb_substr($row['note'], 0, 500),
                        'position' => $row['position'], 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $people[] = $row['person_id'];
                }
                AuditLog::create([
                    'user_id' => null, 'auditable_type' => Manuscript::class, 'auditable_id' => $rec['id'], 'action' => 'corpus_update',
                    'old_values' => $old,
                    'new_values' => ['scribe_name' => $to, 'scribes' => $this->names($rec['id']), 'corpus_fix' => $this->option('file'), 'category' => 'scribes'],
                ]);
            });
            $copies[] = $rec['id'];
        }
        $this->table(['outcome', 'copies'], collect($counts)->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->info(($apply ? 'Added ' : 'Would add ') . "{$added} scribes: {$linked} linked to a person, {$created} to a new person, " . ($added - $linked - $created) . ' as a name.');
        if ($apply && $copies) {
            $ids = array_values(array_unique(array_filter($people)));
            Person::withoutSyncingToSearch(fn () => Person::recountMany($ids));
            Person::whereIn('id', $ids)->get()->searchable();
            Manuscript::whereIn('id', $copies)->get()->searchable();
        }

        return 0;
    }

    /** What the command does with the record: «write», «already», or why it is left */
    private function outcome(array $rec): string
    {
        $copy = DB::table('manuscripts')->where('id', $rec['id'])->first(['shelfmark', 'scribe_name']);
        if (! $copy) {
            return 'no such copy';
        }
        if (trim((string) $copy->shelfmark) !== trim((string) $rec['shelfmark'])) {
            return 'another shelfmark';
        }
        $names = array_map($this->key(...), $this->names($rec['id']));
        $want = array_map(fn ($s) => $this->key($s['name']), $rec['scribes']);
        $to = $this->key($rec['first']['to'] ?? $rec['first']['from']);
        if ($this->key($copy->scribe_name) === $to && ! array_diff($want, $names) && count($names) === count($want) + 1) {
            return 'already';
        }
        if (count($names) > 1) {
            return 'the copy has several scribes';
        }
        $have = $this->key($copy->scribe_name);
        $whole = str_starts_with($have, $this->key(explode('،', $rec['first']['from'])[0]))
            && ! array_filter($want, fn ($name) => ! str_contains($have, $name)) && ($want || str_starts_with($have, $to));
        if ($have !== $this->key($rec['first']['from']) && ! $whole) {
            return 'the copy has another scribe';
        }

        return 'write';
    }

    /** @return string[] the copy's scribes in their order: the person's name, or the name written */
    private function names(int $id): array
    {
        return DB::table('manuscript_people as mp')->leftJoin('people', 'people.id', '=', 'mp.person_id')
            ->where('mp.manuscript_id', $id)->where('mp.role', ManuscriptPerson::SCRIBE)->orderBy('mp.position')->orderBy('mp.id')
            ->get(['people.name as person', 'mp.name'])->map(fn ($r) => (string) ($r->person ?? $r->name))->all();
    }

    /** A name's letters and digits alone, so spacing and marks do not part two spellings */
    private function key(?string $name): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', TextNormalizer::normalize((string) $name));
    }

    /**
     * The person of a name: the one person with exactly that name, or a new one; none for a name under three words.
     *
     * @return array{0: ?int, 1: string} the person's id and «linked», «created» or «name»
     */
    private function person(string $name, bool $apply): array
    {
        if (count(preg_split('/\s+/u', trim($name))) < 3) {
            return [null, 'name'];
        }
        $key = mb_substr(TextNormalizer::normalize($name), 0, 600);
        $ids = DB::table('people')->where('normalized_name', $key)->limit(2)->pluck('id')->all();
        if (count($ids) === 1) {
            return [(int) $ids[0], 'linked'];
        }
        if (count($ids) > 1) {
            return [null, 'name'];
        }
        if (! $apply) {
            return [null, 'created'];
        }
        $person = Person::withoutSyncingToSearch(fn () => Person::create(['name' => $name, 'normalized_name' => $key, 'slug' => 'person-' . Str::random(12), 'is_author' => false, 'is_scribe' => false]));
        Person::withoutSyncingToSearch(fn () => $person->update(['slug' => (Str::slug($name, '-', null) ?: 'person') . '-' . $person->id]));

        return [$person->id, 'created'];
    }
}
