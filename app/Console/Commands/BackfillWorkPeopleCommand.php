<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Suggestions\ContributorRelations;
use App\Suggestions\ProtectedColumns;
use App\Support\PersonNameMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Fills work_people with the translators and authors of the original that parser v2 reads from the Fankha
 * entries (work fields translator and original_author, each with name and hedge). Works are matched by their
 * natural key, the slug suffix «-v{vol}-{ordinal}». A row links a person only on a confident match (see
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
                        $rows[] = [$jw['key']['ordinal'] ?? null, $role, $jw[$field]];
                    }
                }
            }
            if (! $rows) {
                continue;
            }

            $write = function () use ($rows, $vol, $guard, $relations, $dry) {
                foreach ($rows as [$ordinal, $role, $value]) {
                    $this->row($vol, (int) $ordinal, $role, $value, $guard, $relations, $dry);
                }
            };
            $dry ? $write() : DB::transaction($write);
        }

        $this->report($dry);

        return self::SUCCESS;
    }

    private function row(int $vol, int $ordinal, string $role, array $value, ProtectedColumns $guard, ContributorRelations $relations, bool $dry)
    {
        $name = PersonNameMatcher::clean((string) $value['name']);
        // «محمد عارف (ظاهراً از ترکی)»: the parenthesis is a note on the name
        $note = null;
        if (preg_match('/^(.+?)\s*\(([^()]+)\)$/u', $name, $m)) {
            [$name, $note] = [trim($m[1]), trim($m[2])];
        }
        $label = "v{$vol}-{$ordinal} {$role}: {$name}";
        $this->count($role, 'candidates');

        $suffix = "-v{$vol}-{$ordinal}";
        $work = Work::where('slug', 'like', '%' . $suffix)->get()->first(fn ($w) => str_ends_with($w->slug, $suffix));
        if (! $work) {
            return $this->skip($role, 'work_missing', $label);
        }
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
