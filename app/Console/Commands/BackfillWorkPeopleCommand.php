<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Models\Person;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Suggestions\ContributorRelations;
use App\Suggestions\ProtectedColumns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Fills work_people with the translators and authors of the original that parser v2 reads from the Fankha
 * entries (work fields translator and original_author, each with name and hedge). Works are matched by their
 * natural key, the slug suffix «-v{vol}-{ordinal}». A row links a person only on a confident match: one person
 * whose name or other name is exactly this one, with no disagreeing death century, and with three words or
 * more or an agreeing death century;
 * otherwise the name is kept as text. Names the parser ran into the description («… . این کتاب …»), «نامعلوم»
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

    /** The cataloguer's hedge words and the certainty they express. */
    private const HEDGES = ['؟' => 'doubtful', 'محتملا' => 'probable', 'ظاهرا' => 'probable', 'گویا' => 'probable', 'شاید' => 'doubtful', 'احتمالا' => 'probable'];

    private const UNKNOWN = ['نامعلوم', 'ناشناخته', 'ناشناس', 'مجهول', 'نامشخص'];

    /** @var array<string, int[]> normalized name or other name => person ids */
    private array $index = [];

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
        $this->buildIndex();

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
        // «طالبوف ، عبدالرحیم»: the people table writes the comma without the space before it
        $name = trim(preg_replace(['/\s+/u', '/\s+،/u'], [' ', '،'], (string) $value['name']));
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

        [$person, $why] = $this->match($name, $normalized, $value['death'] ?? null);
        $exists = $work->workPeople()->where('role', $role)
            ->where(fn ($q) => $person ? $q->where('person_id', $person->id) : $q->whereNull('person_id')->where('name', $name))->exists();
        if ($exists) {
            return $this->skip($role, 'exists', $label);
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
            'certainty' => $hedge !== '' ? (self::HEDGES[TextNormalizer::normalize($hedge)] ?? self::HEDGES[$hedge] ?? 'probable') : null,
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
     * The person this name confidently is, else null and why not.
     *
     * @return array{0: ?Person, 1: ?string}
     */
    private function match(string $name, string $normalized, ?array $death): array
    {
        // «جان طیلر و ابوالقاسم …», «یوسفعلی، ماردروس، میرزا علیقلی…»: several people in one name
        if (preg_match('/\sو\s/u', $name) || mb_substr_count($name, '،') >= 2) {
            return [null, 'several'];
        }
        $words = count(preg_split('/[\s،]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY));
        if ($words < 2) {
            return [null, 'one_word'];
        }
        $ids = $this->index[$normalized] ?? [];
        if (! $ids) {
            return [null, 'no_match'];
        }
        if (count($ids) > 1) {
            return [null, 'ambiguous'];
        }
        $person = Person::find($ids[0]);
        $century = $death['century'] ?? null;
        $personCentury = $person->century_hijri ?? ($person->death_year_hijri ? intdiv($person->death_year_hijri - 1, 100) + 1 : null);
        if ($century && $personCentury && (int) $century !== (int) $personCentury) {
            return [null, 'death_conflict'];
        }
        // A two-word name («میرزا محمد», «منشی، محسن») is common: it links only when the death century agrees
        if ($words < 3 && ! ($century && $personCentury)) {
            return [null, 'short_unconfirmed'];
        }

        return [$person, null];
    }

    /** Every person by normalized name and other names, for exact lookups. */
    private function buildIndex(): void
    {
        DB::table('people')->select(['id', 'normalized_name', 'aliases'])->orderBy('id')->chunk(5000, function ($people) {
            foreach ($people as $p) {
                $names = [$p->normalized_name];
                foreach ((array) json_decode((string) $p->aliases, true) as $alias) {
                    $names[] = TextNormalizer::normalize(is_array($alias) ? ($alias['name'] ?? '') : (string) $alias);
                }
                foreach (array_unique(array_filter($names)) as $n) {
                    $this->index[$n][] = $p->id;
                }
            }
        });
        $this->index = array_map(fn ($ids) => array_values(array_unique($ids)), $this->index);
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
