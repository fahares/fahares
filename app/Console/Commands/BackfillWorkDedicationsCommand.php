<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Models\Person;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Suggestions\ContributorRelations;
use App\Suggestions\ProtectedColumns;
use App\Support\DedicationParser;
use App\Support\KnownRulers;
use App\Support\PersonNameMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Fills work_people with the dedicatees and commissioners of the Fankha «اهدا / به درخواست» field
 * (works.dedication; backlog item 35, step 2). DedicationParser splits the field into people with their role.
 * A ruler of KnownRulers links to its authority record, which is created when the people table has none; a
 * spelling that usually means one ruler («شاه عباس صفوی») is decided by the work's year of composition, or, with
 * no year, links to the usual one, as probable when the other possibility is real;
 * a spelling whose authority name fits more than one record stays text. Any other name links only on a
 * confident match (PersonNameMatcher) whose death century the field's own dates confirm, else it is kept as text. Works whose people reviewers already changed
 * are left out. Rows cite the work's Fankha entry and go through ContributorRelations::addRole.
 */
class BackfillWorkDedicationsCommand extends Command
{
    protected $signature = 'fahares:backfill-work-dedications
        {--dry-run : Count and list what would be written without writing}
        {--only= : Comma-separated work ids to limit the run to}
        {--show=15 : How many examples to list per kind}';

    protected $description = 'Fill work_people (dedicatee, commissioner) from works.dedication';

    /** A piece longer than this is a sentence of the description, not a name. */
    private const MAX_NAME = 100;

    private PersonNameMatcher $matcher;

    /** @var array<string, Person|string> ruler authority name => its record, or 'new' / 'ambiguous' */
    private array $rulers = [];

    /** @var array<string, array<string, int>> */
    private array $counts = [];

    /** @var array<string, string[]> */
    private array $examples = [];

    public function handle(ContributorRelations $relations): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = array_filter(array_map('intval', explode(',', (string) $this->option('only'))));
        $guard = ProtectedColumns::for(Work::class);
        // The same command object can run twice in one process (tests): nothing carries over from a dry run
        $this->matcher = new PersonNameMatcher;
        $this->rulers = [];
        $this->counts = [];
        $this->examples = [];

        $query = Work::whereNotNull('dedication')->where('dedication', '<>', '')->select(['id', 'slug', 'volume_number', 'composition_year_hijri', 'dedication']);
        if ($only) {
            $query->whereIn('id', $only);
        }
        $query->orderBy('id')->chunkById(500, function ($works) use ($guard, $relations, $dry) {
            $write = function () use ($works, $guard, $relations, $dry) {
                foreach ($works as $work) {
                    $this->work($work, $guard, $relations, $dry);
                }
            };
            $dry ? $write() : DB::transaction($write);
        });

        $this->report($dry);

        return self::SUCCESS;
    }

    private function work(Work $work, ProtectedColumns $guard, ContributorRelations $relations, bool $dry): void
    {
        $this->count('works', 'with_dedication');
        if ($guard->protectsRows($work->id, 'people')) {
            $this->count('works', 'skipped:protected');

            return;
        }
        $people = DedicationParser::parse($work->dedication);
        if (! $people) {
            $this->count('works', 'no_person');
            $this->example('no_person', "#{$work->id}: {$work->dedication}");

            return;
        }
        $entry = SourceEntry::where('citable_type', Work::class)->where('citable_id', $work->id)
            ->where('origin', SourceEntry::ORIGIN_FANKHA)->orderByDesc('is_primary')->orderBy('id')->value('id');

        foreach ($people as $p) {
            $role = $p['role'];
            $name = PersonNameMatcher::clean($p['name']);
            $label = "#{$work->id} {$role}: {$name}";
            $this->count($role, 'candidates');
            if (mb_strlen($name) > self::MAX_NAME) {
                $this->skip($role, 'run_on', mb_substr($label, 0, 160));

                continue;
            }

            [$person, $how, $usually] = $this->person($name, $p['death'], $work->composition_year_hijri, $dry);
            $exists = $work->workPeople()->where('role', $role)
                ->where(fn ($q) => $person instanceof Person ? $q->where('person_id', $person->id) : $q->whereNull('person_id')->where('name', $name))->exists();
            if ($exists) {
                $this->skip($role, 'exists', $label);

                continue;
            }
            // An earlier run linked «شاه عباس صفوی (1052-1077ق)» to the first Abbas before the dates were read
            $others = [];
            foreach ($person instanceof Person ? $usually['group'] : [] as $other) {
                $others = array_merge($others, $this->matcher->ids($other));
            }
            $others = array_values(array_diff($others, [$person instanceof Person ? $person->id : 0]));
            $note = implode('؛ ', array_filter([$usually['note'], $p['note']])) ?: null;
            if ($person instanceof Person && $others && $relations->relinkRow($work, $role, $others, $person, PersonNameMatcher::certainty($p['hedge']) ?? $usually['certainty'], $note, $dry)) {
                $this->count($role, 'relinked_ruler');
                $this->example('relinked_ruler', "{$label} → #{$person->id} {$person->name}");

                continue;
            }
            if ($person instanceof Person && $relations->linkNameRow($work, $role, $name, $person, $dry)) {
                $this->count($role, 'linked_existing_name');
                $this->example('linked_existing_name', "{$label} → #{$person->id} {$person->name}");

                continue;
            }
            $this->count($role, $how);
            $this->example($how, $label . ($person instanceof Person ? " → #{$person->id} {$person->name}" : (is_string($person) ? " → {$person}" : '')));
            if ($p['hedge']) {
                $this->count($role, 'hedged');
            }
            if ($usually['note']) {
                $this->count($role, 'usual_ruler_probable');
            }
            if ($dry) {
                continue;
            }
            try {
                $relations->addRole($work, $role, $person, $person ? null : $name, $note, null, [
                    'certainty' => PersonNameMatcher::certainty($p['hedge']) ?? $usually['certainty'],
                    'hedge_raw' => $p['hedge'] ? mb_substr($p['hedge'], 0, 60) : null,
                    'source_entry_id' => $entry,
                ]);
            } catch (InvalidArgumentException $e) {
                $this->skip($role, 'refused', "{$label} ({$e->getMessage()})");
            }
        }
    }

    /**
     * The person a name links to and how: a ruler's authority record (found or created), a confident match,
     * or none (the reason), with the certainty and note a usual-but-not-sure spelling gives (KnownRulers::USUALLY).
     * In a dry run a ruler that would be created comes back as its name.
     *
     * @return array{0: Person|string|null, 1: string, 2: array{certainty: ?string, note: ?string, group: string[]}}
     */
    private function person(string $name, ?int $death, ?int $year, bool $dry): array
    {
        $none = ['certainty' => null, 'note' => null, 'group' => []];
        if ($found = KnownRulers::find($name, $year, $death)) {
            $ruler = $found['ruler'];
            $usually = ['certainty' => $found['certainty'], 'note' => $found['note'], 'group' => $found['group']];
            $record = $this->ruler($ruler, $dry);

            return match (true) {
                $record instanceof Person => [$record, $this->rulers[$ruler['name'] . ':created'] ?? false ? 'linked_ruler_created' : 'linked_ruler', $usually],
                $record === 'new' => [$ruler['name'], 'linked_ruler_created', $usually],
                default => [null, 'name_only:ruler_ambiguous', $none],
            };
        }
        // Scribes and owners named «محمد علی میرزا» or «شمس الدین محمد» fill the people table: only a death
        // year in the field's own parentheses that agrees with the record makes the match confident
        [$person, $why] = $this->matcher->match($name, PersonNameMatcher::century($death), requireCentury: true);

        return $person ? [$person, 'linked', $none] : [null, "name_only:{$why}", $none];
    }

    /** A ruler's authority record: the one person of that name, a new one, or 'ambiguous' when there are several. */
    private function ruler(array $ruler, bool $dry): Person|string
    {
        $key = $ruler['name'];
        if (isset($this->rulers[$key])) {
            return $this->rulers[$key];
        }
        $ids = $this->matcher->ids(TextNormalizer::normalize($key));
        if (count($ids) > 1) {
            $this->example('ruler_ambiguous', "{$key}: #" . implode(', #', $ids));

            return $this->rulers[$key] = 'ambiguous';
        }
        if ($ids) {
            return $this->rulers[$key] = Person::find($ids[0]);
        }
        if ($dry) {
            $this->example('ruler_created', $key);

            return $this->rulers[$key] = 'new';
        }

        $person = Person::create([
            'name' => $key,
            'normalized_name' => mb_substr(TextNormalizer::normalize($key), 0, 600),
            'slug' => 'person-' . Str::random(12),
            'death_year_hijri' => $ruler['death'],
            'century_hijri' => PersonNameMatcher::century($ruler['death']),
        ]);
        $person->update(['slug' => (Str::slug($key, '-', null) ?: 'person') . '-' . $person->id]);
        $this->matcher->remember($person);
        $this->example('ruler_created', "#{$person->id} {$key}");
        $this->rulers[$key . ':created'] = true;

        return $this->rulers[$key] = $person;
    }

    private function count(string $group, string $kind): void
    {
        $this->counts[$group][$kind] = ($this->counts[$group][$kind] ?? 0) + 1;
    }

    private function skip(string $role, string $kind, string $label): void
    {
        $this->count($role, "skipped:{$kind}");
        $this->example("skipped:{$kind}", $label);
    }

    private function example(string $kind, string $label): void
    {
        if (count($this->examples[$kind] ?? []) < (int) $this->option('show')) {
            $this->examples[$kind][] = $label;
        }
    }

    private function report(bool $dry): void
    {
        $this->info($dry ? 'Dry run: nothing written.' : 'Written.');
        foreach ($this->counts as $group => $counts) {
            ksort($counts);
            $this->line("== {$group}");
            foreach ($counts as $kind => $n) {
                $this->line(sprintf('  %-32s %6d', $kind, $n));
            }
        }
        foreach ($this->examples as $kind => $labels) {
            $this->line("-- {$kind}");
            foreach ($labels as $label) {
                $this->line("   {$label}");
            }
        }
    }
}
