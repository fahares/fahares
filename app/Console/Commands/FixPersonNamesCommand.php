<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\ManuscriptPerson;
use App\Models\Person;
use App\Models\Work;
use App\Models\WorkPerson;
use App\Suggestions\ProtectedColumns;
use App\Support\DerivedData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Puts right the names that the OCR of a catalogue misread, as reviewed (step 14.451: Fankha's OCR words that stand in
 * an author's or scribe's name). The decision file lists, for each misread word:
 * - «record»: only the record's own name (a work's author text and author row, a copy's scribe text and scribe row);
 * - «rename»: the linked person's name was misread too: the person is renamed, the misread name goes to its wrong
 *   names (the works and copies that carried that name follow, as for any rename), and the record's own name is fixed;
 * - «variant»: the printed form is another spelling of the same name: it is added to the person's other names, and the
 *   record's own name takes it.
 * A person is renamed only while it still has the name the review saw. A record's name is patched only where the
 * misread word stands once as a whole word. Columns a reviewed correction protects are left. Each change is logged.
 * Without --apply it only reports.
 */
class FixPersonNamesCommand extends Command
{
    protected $signature = 'fahares:fix-person-names
                            {--file= : The decision file (database/data/fankha_ocr_names_step14_451.json)}
                            {--apply : Write the changes; without it the command only reports}';

    protected $description = 'Put right person and record names an OCR misread, as reviewed';

    private const RECORD = [
        'work' => ['table' => 'works', 'model' => Work::class, 'column' => 'author_name', 'rows' => 'work_people', 'key' => 'work_id', 'role' => WorkPerson::AUTHOR],
        'copy' => ['table' => 'manuscripts', 'model' => Manuscript::class, 'column' => 'scribe_name', 'rows' => 'manuscript_people', 'key' => 'manuscript_id', 'role' => ManuscriptPerson::SCRIBE],
    ];

    private array $counts = [];
    private array $problems = [];

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path('data/fankha_ocr_names_step14_451.json');
        $file = File::exists($path) ? json_decode(File::get($path), true) : null;
        if (! $file || ! isset($file['actions'])) {
            $this->error("Decision file missing or invalid: {$path}");

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $step = $file['step'] ?? null;
        $guards = ['person' => ProtectedColumns::for(Person::class), 'work' => ProtectedColumns::for(Work::class), 'copy' => ProtectedColumns::for(Manuscript::class)];
        $touched = ['work' => [], 'copy' => []];

        $run = function () use ($file, $guards, $step, $apply, &$touched) {
            // the persons first: a rename carries the records that held the old name with it
            foreach ($file['actions'] as $a) {
                match ($a['action']) {
                    'rename' => $this->rename($a, $guards['person'], $step, $apply),
                    'variant' => $a['person_id'] && $a['alias'] ? $this->alias($a, $guards['person'], $step, $apply) : null,
                    default => null,
                };
            }
            foreach ($file['actions'] as $a) {
                if (isset(self::RECORD[$a['table'] ?? '']) && $this->record($a, $guards[$a['table']], $step, $apply)) {
                    $touched[$a['table']][] = (int) $a['id'];
                }
            }
        };
        $apply ? DB::transaction($run) : $run();

        if ($apply) {
            $touched['copy'] && DerivedData::syncCopies(array_values(array_unique($touched['copy'])), $this);
            $touched['work'] && DerivedData::syncWorks(array_values(array_unique($touched['work'])), $this);
        }

        ksort($this->counts);
        $this->table(['outcome', 'count'], collect($this->counts)->map(fn ($n, $k) => [$k, $n])->values()->all());
        foreach (array_slice($this->problems, 0, 200) as $line) {
            $this->warn($line);
        }
        $apply || $this->warn('Dry run: nothing is saved.');

        return self::SUCCESS;
    }

    private function count(string $key): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
    }

    private function rename(array $a, ProtectedColumns $guard, ?string $step, bool $apply): void
    {
        $person = Person::find($a['person_id']);
        if (! $person) {
            $this->count('person missing');

            return;
        }
        if ($person->name === $a['new_name']) {
            $this->count('rename already');

            return;
        }
        if ($person->name !== $a['person_name']) {
            $this->count('rename conflict');
            $this->problems[] = "person #{$person->id}: name is «{$person->name}», not «{$a['person_name']}»";

            return;
        }
        if ($guard->strip($person->id, ['name' => true]) === []) {
            $this->count('rename protected');
            $this->problems[] = "person #{$person->id}: name has a reviewed correction, left as is";

            return;
        }
        $this->count('renamed');
        if (! $apply) {
            return;
        }
        $old = ['name' => $person->name, 'wrong_names' => $person->wrong_names];
        $wrong = array_values(array_unique(array_merge((array) ($person->wrong_names ?? []), [$person->name])));
        // the name without its vowel signs, as the panel's person form writes it
        $normalized = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $a['new_name']);
        $person->forceFill(['name' => $a['new_name'], 'normalized_name' => $normalized, 'wrong_names' => $wrong])->save();
        $this->log(Person::class, $person->id, $old, ['name' => $person->name, 'wrong_names' => $wrong], $step);
    }

    private function alias(array $a, ProtectedColumns $guard, ?string $step, bool $apply): void
    {
        $person = Person::find($a['person_id']);
        $aliases = (array) ($person?->aliases ?? []);
        if (! $person || $person->name === $a['alias'] || in_array($a['alias'], $aliases, true)) {
            $this->count($person ? 'alias already' : 'person missing');

            return;
        }
        if ($guard->strip($person->id, ['aliases' => true]) === []) {
            $this->count('alias protected');

            return;
        }
        $this->count('alias added');
        if (! $apply) {
            return;
        }
        $new = array_values(array_merge($aliases, [$a['alias']]));
        $person->forceFill(['aliases' => $new])->save();
        $this->log(Person::class, $person->id, ['aliases' => $aliases], ['aliases' => $new], $step);
    }

    /** The record's own name text and its name row; true when something was written. */
    private function record(array $a, ProtectedColumns $guard, ?string $step, bool $apply): bool
    {
        $def = self::RECORD[$a['table']];
        $row = DB::table($def['table'])->where('id', $a['id'])->first(['id', $def['column']]);
        if (! $row) {
            $this->count('record missing');

            return false;
        }
        $written = false;
        $column = $def['column'];
        [$value, $outcome] = $this->patch((string) $row->$column, $a['old'], $a['new']);
        if ($outcome === 'patched' && $guard->strip($row->id, [$column => true]) === []) {
            $outcome = 'protected';
        }
        $this->count("record {$outcome}");
        if ($outcome === 'patched' && $apply) {
            DB::table($def['table'])->where('id', $row->id)->update([$column => $value, 'updated_at' => now()]);
            $this->log($def['model'], $row->id, [$column => $row->$column], [$column => $value], $step);
            $written = true;
        }
        if ($outcome === 'conflict') {
            $this->problems[] = "{$a['table']} #{$row->id}: «{$a['old']}» is not once in «{$row->$column}»";
        }
        // the name row the record's person link carries
        foreach (DB::table($def['rows'])->where($def['key'], $row->id)->where('role', $def['role'])->get(['id', 'name']) as $nameRow) {
            [$value, $outcome] = $this->patch((string) $nameRow->name, $a['old'], $a['new']);
            if ($outcome === 'patched') {
                $this->count('name row patched');
                if ($apply) {
                    DB::table($def['rows'])->where('id', $nameRow->id)->update(['name' => $value, 'updated_at' => now()]);
                    $this->log($def['model'], $row->id, ['person_name' => $nameRow->name], ['person_name' => $value], $step);
                    $written = true;
                }
            }
        }

        return $written;
    }

    /**
     * The name with the misread word put right: «patched» when it stands once as a whole word, «already» when it is
     * gone and the printed word is there, otherwise «conflict».
     *
     * @return array{0: ?string, 1: string}
     */
    private function patch(string $value, string $old, string $new): array
    {
        $word = fn (string $w) => '/(?<![\p{L}\p{Mn}])' . preg_quote($w, '/') . '(?![\p{L}\p{Mn}])/u';
        $found = preg_match_all($word($old), $value);
        if ($found === 1) {
            return [preg_replace($word($old), $new, $value), 'patched'];
        }
        if ($found === 0 && preg_match($word($new), $value)) {
            return [null, 'already'];
        }

        return [null, $found === 0 ? 'absent' : 'conflict'];
    }

    private function log(string $class, int $id, array $old, array $new, ?string $step): void
    {
        AuditLog::create([
            'user_id' => null,
            'auditable_type' => $class,
            'auditable_id' => $id,
            'action' => 'update',
            'old_values' => $old,
            'new_values' => $new + ['command' => 'fahares:fix-person-names', 'step' => $step],
        ]);
    }
}
