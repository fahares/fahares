<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Suggestions\ContributorRelations;
use App\Suggestions\ProtectedColumns;
use App\Support\FankhaWorkLocator;
use App\Support\PersonNameMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Fills work_people with the translators and authors of the original that parser v2 reads from the Fankha
 * entries (work fields translator and original_author, each with name and hedge). Works are found by title and page
 * (FankhaWorkLocator): the slug suffix «-v{vol}-{ordinal}» of step 13.68 led 138 rows to a neighbour, and a row
 * an earlier run so gave a neighbour is moved here. A row links a person only on a confident match (see
 * PersonNameMatcher); otherwise the name is kept as text. Names the parser ran into the description («… . این کتاب …»), «نامعلوم»
 * and works whose people reviewers already changed are left out. Every row cites the work's Fankha entry.
 * Rows go through ContributorRelations::addRole, the one write path for work_people.
 */
class BackfillWorkPeopleCommand extends Command
{
    protected $signature = 'fahares:backfill-work-people
        {--dry-run : Count and list what would be written without writing}
        {--source= : Directory of the parser v2 fankha_vol_XX.json files}
        {--volume= : One volume (1-34)}
        {--show=15 : How many examples to list per kind of skipped or unlinked name}';

    protected $description = 'Fill work_people (translator, original_author) from the parser v2 Fankha JSON';

    /** Parser v2 fields and the role each one fills. */
    private const FIELDS = ['translator' => 'translator', 'original_author' => 'original_author'];

    private const UNKNOWN = ['نامعلوم', 'ناشناخته', 'ناشناس', 'مجهول', 'نامشخص'];

    private PersonNameMatcher $matcher;

    private FankhaWorkLocator $locator;

    /** @var array<string, true> "work id|role|name" the v2 JSON gives */
    private array $expected = [];

    /** @var array<string, array<string, int>> */
    private array $counts = [];

    /** @var array<string, string[]> */
    private array $examples = [];

    public function handle(ContributorRelations $relations): int
    {
        $dry = (bool) $this->option('dry-run');
        $dir = $this->option('source') ?: base_path('../fahares-corpus/json/fankha_v2');
        if (! File::isDirectory($dir)) {
            $this->error("Directory not found: {$dir}");

            return self::FAILURE;
        }
        $volumes = $this->option('volume') ? [(int) $this->option('volume')] : range(1, 34);
        $guard = ProtectedColumns::for(Work::class);
        // The same command object can run twice in one process (tests): nothing carries over from a dry run
        $this->matcher = new PersonNameMatcher;
        $this->counts = [];
        $this->examples = [];
        // Every volume's works, to find the site's work by title and page (FankhaWorkLocator), not by slug suffix
        $all = [];
        foreach (range(1, 34) as $v) {
            $file = sprintf('%s/fankha_vol_%02d.json', rtrim($dir, '/'), $v);
            foreach (File::exists($file) ? (json_decode(File::get($file), true)['works'] ?? []) : [] as $jw) {
                $all[] = ['key' => $jw['key'], 'title' => $jw['title'] ?? '', 'provenance' => ['page_start' => $jw['provenance']['page_start'] ?? 0]]
                    + array_intersect_key($jw, self::FIELDS);
            }
        }
        $this->locator = new FankhaWorkLocator($all);
        // Which work rightly has which translator: a neighbour that has the same one in its own entry keeps its row
        $this->expected = [];
        foreach ($all as $jw) {
            foreach (self::FIELDS as $field => $role) {
                if (! empty($jw[$field]['name']) && ($id = $this->locator->find($jw['key']['vol'], $jw['key']['ordinal'], $jw['title'], $jw['provenance']['page_start']))) {
                    $this->expected["{$id}|{$role}|" . $this->cleanName($jw[$field]['name'])[0]] = true;
                }
            }
        }
        unset($all);

        foreach ($volumes as $vol) {
            $file = sprintf('%s/fankha_vol_%02d.json', rtrim($dir, '/'), $vol);
            if (! File::exists($file)) {
                $this->warn("Missing {$file}");

                continue;
            }
            $data = json_decode(File::get($file), true);
            $rows = [];
            foreach ($data['works'] ?? [] as $jw) {
                foreach (self::FIELDS as $field => $role) {
                    if (! empty($jw[$field]['name'])) {
                        $rows[] = [$jw['key']['ordinal'] ?? null, $role, $jw[$field], $jw['title'] ?? '', (int) ($jw['provenance']['page_start'] ?? 0)];
                    }
                }
            }
            if (! $rows) {
                continue;
            }

            $write = function () use ($rows, $vol, $guard, $relations, $dry) {
                foreach ($rows as [$ordinal, $role, $value, $title, $page]) {
                    $this->row($vol, (int) $ordinal, $role, $value, $title, $page, $guard, $relations, $dry);
                }
            };
            $dry ? $write() : DB::transaction($write);
        }

        $this->report($dry);

        return self::SUCCESS;
    }

    private function row(int $vol, int $ordinal, string $role, array $value, string $title, int $page, ProtectedColumns $guard, ContributorRelations $relations, bool $dry)
    {
        [$name, $note] = $this->cleanName((string) $value['name']);
        $label = "v{$vol}-{$ordinal} {$role}: {$name}";
        $this->count($role, 'candidates');

        $work = Work::find($this->locator->find($vol, $ordinal, $title, $page) ?? 0);
        if (! $work) {
            return $this->skip($role, 'work_missing', $label);
        }
        // The work the slug suffix named before step 13.92 (a neighbour, where v2's ordinals moved)
        $wrongId = $this->locator->bySlugSuffix($vol, $ordinal);
        $wrong = $wrongId && $wrongId !== $work->id ? Work::find($wrongId) : null;
        if ($guard->protectsRows($work->id, 'people')) {
            return $this->skip($role, 'protected', $label);
        }
        $normalized = TextNormalizer::normalize($name);
        if (in_array($normalized, self::UNKNOWN, true) || preg_match('/^(' . implode('|', self::UNKNOWN) . ')[\s.،]/u', $normalized)) {
            return $this->skip($role, 'unknown', $label);
        }
        // The parser ran the name into the description that follows it
        if (mb_strlen($name) > 80 || preg_match('/[.؛:]\s|[.؛:]$/u', $name)) {
            return $this->skip($role, 'run_on', mb_substr($label, 0, 160));
        }

        [$person, $why] = $this->matcher->match($name, $value['death']['century'] ?? null);
        // A row an earlier run gave the neighbour goes to this work (or away, when this work has it already)
        if ($wrong && ! $guard->protectsRows($wrong->id, 'people') && ! isset($this->expected["{$wrong->id}|{$role}|{$name}"])) {
            $row = $wrong->workPeople()->where('role', $role)->whereNull('suggestion_id')
                ->where(fn ($q) => $q->where('name', $name)->when($person, fn ($q) => $q->orWhere('person_id', $person->id)))->first();
            if ($row) {
                $this->count($role, 'moved_from_neighbour');
                $this->example('moved_from_neighbour', "{$label}: #{$wrong->id} «{$wrong->primary_title}» → #{$work->id} «{$work->primary_title}»");
                if (! $dry) {
                    $relations->moveRole($row, $work, SourceEntry::where('citable_type', Work::class)->where('citable_id', $work->id)
                        ->where('origin', SourceEntry::ORIGIN_FANKHA)->where('volume_number', $vol)->orderByDesc('is_primary')->orderBy('id')->value('id'));
                }
            }
        }
        $exists = $work->workPeople()->where('role', $role)
            ->where(fn ($q) => $person ? $q->where('person_id', $person->id) : $q->whereNull('person_id')->where('name', $name))->exists();
        if ($exists) {
            return $this->skip($role, 'exists', $label);
        }
        // An earlier run kept this name as text; it now links
        if ($person && $relations->linkNameRow($work, $role, $name, $person, $dry)) {
            $this->count($role, 'linked_existing_name');

            return $this->example('linked_existing_name', "{$label} → #{$person->id} {$person->name}");
        }
        $this->count($role, $person ? 'linked' : 'name_only');
        if ($person) {
            $this->example('linked', "{$label} → #{$person->id} {$person->name}");
        } else {
            $this->count($role, "name_only:{$why}");
            $this->example("name_only:{$why}", $label);
        }

        $hedge = trim((string) ($value['hedge'] ?? ''));
        $extra = [
            'certainty' => PersonNameMatcher::certainty($hedge),
            'hedge_raw' => $hedge !== '' ? mb_substr($hedge, 0, 60) : null,
            'source_entry_id' => SourceEntry::where('citable_type', Work::class)->where('citable_id', $work->id)
                ->where('origin', SourceEntry::ORIGIN_FANKHA)->where('volume_number', $vol)
                ->orderByDesc('is_primary')->orderBy('id')->value('id'),
        ];
        if ($hedge !== '') {
            $this->count($role, 'hedged');
        }
        if ($dry) {
            return;
        }
        try {
            $relations->addRole($work, $role, $person, $person ? null : $name, $note, null, $extra);
        } catch (InvalidArgumentException $e) {
            $this->skip($role, 'refused', "{$label} ({$e->getMessage()})");
        }
    }

    /**
     * The name as stored, and the parenthesis after it as a note: «محمد عارف (ظاهراً از ترکی)».
     *
     * @return array{0: string, 1: ?string}
     */
    private function cleanName(string $raw): array
    {
        $name = PersonNameMatcher::clean($raw);
        if (preg_match('/^(.+?)\s*\(([^()]+)\)$/u', $name, $m)) {
            return [trim($m[1]), trim($m[2])];
        }

        return [$name, null];
    }

    private function count(string $role, string $kind): void
    {
        $this->counts[$role][$kind] = ($this->counts[$role][$kind] ?? 0) + 1;
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
        foreach ($this->counts as $role => $counts) {
            ksort($counts);
            $this->line("== {$role}");
            foreach ($counts as $kind => $n) {
                $this->line(sprintf('  %-28s %6d', $kind, $n));
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
