<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Models\Manuscript;
use App\Models\TextExcerpt;
use App\Models\Work;
use App\Suggestions\ProtectedColumns;
use App\Support\DedicationParser;
use App\Support\DerivedData;
use App\Support\KnownRulers;
use App\Support\PersonNameMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reports, and with --apply mends, the derived data that has drifted from the columns it is made from (item 87): the
 * corpus commands write source columns with DB::table, which runs no model hook. For each kind it counts the records
 * that differ from a fresh recomputation, with examples: copy date ranges, composition date ranges, death ranges,
 * extents, bound volumes (collection_key), works whose dedication gives people they have no work_people row for, excerpt pointers, and the
 * dedicatees and commissioners a backfill wrote (suggestion_id null) that the current dedication no longer gives.
 * The last are only reported, never deleted. With --apply the idempotent fillers run in order, what they leave is
 * written through DerivedData, and the report is made again. Run it after each corpus apply.
 */
class RefreshDerivedCommand extends Command
{
    protected $signature = 'fahares:refresh-derived
        {--apply : Run the fillers and write what differs; without it the command only reports}
        {--excerpts= : Decision file of fahares:fill-text-excerpts (database/data/fankha_v2_excerpts_20261031.json)}
        {--deaths= : Decision file of fahares:fill-death-ranges (database/data/fankha_v2_deaths_20261031.json)}
        {--show=8 : How many examples to list per kind}';

    protected $description = 'Report derived data that differs from its source columns, and with --apply bring it in step';

    private const DEDICATION_ROLES = ['dedicatee', 'commissioner'];

    private array $report = [];

    public function handle(): int
    {
        $this->drift();
        $this->print('Drift');
        if (! $this->option('apply')) {
            $this->info('Dry run: nothing written.');

            return self::SUCCESS;
        }

        $this->call('fahares:fill-copy-date-ranges', ['--apply' => true, '--show' => 3]);
        $this->call('fahares:fill-copy-date-ranges', ['--works' => true, '--apply' => true, '--show' => 3]);
        $this->call('fahares:fill-extents', ['--apply' => true, '--show' => 3]);
        $this->call('fahares:fill-collection-keys', ['--apply' => true, '--show' => 3]);
        $this->call('fahares:backfill-work-dedications', ['--show' => 3]);
        foreach (['fahares:fill-text-excerpts' => ['excerpts', 'fankha_v2_excerpts_20261031.json'], 'fahares:fill-death-ranges' => ['deaths', 'fankha_v2_deaths_20261031.json']] as $filler => [$option, $file]) {
            $path = $this->option($option) ?: database_path("data/{$file}");
            if (is_file($path)) {
                $this->call($filler, ['--file' => $path, '--apply' => true, '--show' => 3]);
            }
        }

        // What the fillers leave: a date cleared with its range kept, a pointer the copy's text no longer makes…
        $this->drift();
        DerivedData::syncCopies(array_merge($this->report['copy_date_range']['ids'], $this->report['extent']['ids'], $this->report['collection_key']['ids'], $this->report['excerpt_pointers']['ids']), $this);
        DerivedData::syncWorks($this->report['composition_date_range']['ids'], $this);
        DB::transaction(function () {
            foreach (array_chunk($this->report['death_range']['ids'], 1000) as $chunk) {
                foreach (DB::table('people')->whereIn('id', $chunk)->get(DerivedData::PERSON_COLUMNS) as $row) {
                    if ($cols = DerivedData::personChanges($row)) {
                        DB::table('people')->where('id', $row->id)->update($cols);
                    }
                }
            }
        });

        $this->drift();
        $this->print('After');

        return self::SUCCESS;
    }

    /** Fills $this->report with every kind's drifted records. */
    private function drift(): void
    {
        $this->report = [];
        foreach (['copy_date_range', 'extent', 'collection_key', 'composition_date_range', 'death_range', 'dedications_missing', 'dedications_not_given', 'excerpt_pointers'] as $kind) {
            $this->report[$kind] = ['count' => 0, 'ids' => [], 'examples' => []];
        }

        $guard = ProtectedColumns::for(Manuscript::class);
        DB::table('manuscripts')->select(DerivedData::COPY_COLUMNS)->orderBy('id')->chunkById(2000, function ($rows) use ($guard) {
            $deaths = Manuscript::authorDeaths($rows->pluck('work_id'));
            foreach ($rows as $row) {
                ['date' => $date, 'extent' => $extent, 'collection' => $collection] = DerivedData::copyChanges($row, $guard, $deaths[$row->work_id] ?? null);
                if ($date) {
                    $this->add('copy_date_range', $row->id, "#{$row->id} «{$row->copy_date_raw}»: {$row->copy_date_from}–{$row->copy_date_to} → {$date['copy_date_from']}–{$date['copy_date_to']}");
                }
                if ($extent) {
                    $show = fn ($folios, $pages, $raw) => '«' . ($raw ?? '') . '» ' . ($folios ?? '—') . 'گ/' . ($pages ?? '—') . 'ص';
                    $this->add('extent', $row->id, "#{$row->id} " . $show($row->folios, $row->pages, $row->extent_raw) . ' → ' . $show($extent['folios'], $extent['pages'], $extent['extent_raw']));
                }
                if ($collection) {
                    $this->add('collection_key', $row->id, "#{$row->id} «{$row->shelfmark}»: " . ($row->collection_key ?? '—') . ' → ' . ($collection['collection_key'] ?? '—'));
                }
            }
        });
        DB::table('works')->select(DerivedData::WORK_COLUMNS)->orderBy('id')->chunkById(5000, function ($rows) {
            foreach ($rows as $row) {
                if ($cols = DerivedData::workChanges($row)) {
                    $this->add('composition_date_range', $row->id, "#{$row->id} «{$row->composition_date_raw}»: {$row->composition_date_from}–{$row->composition_date_to} → {$cols['composition_date_from']}–{$cols['composition_date_to']}");
                }
            }
        });
        DB::table('people')->select(DerivedData::PERSON_COLUMNS)->orderBy('id')->chunkById(5000, function ($rows) {
            foreach ($rows as $row) {
                if ($cols = DerivedData::personChanges($row)) {
                    $this->add('death_range', $row->id, "#{$row->id} «{$row->life_dates_raw}» ({$row->death_year_hijri}): {$row->death_from}–{$row->death_to} → {$cols['death_from']}–{$cols['death_to']}");
                }
            }
        });
        $this->dedications();
        $this->pointers();
    }

    /**
     * Works whose dedication gives a person of a role they have no work_people row of, and rows a backfill wrote that
     * the current dedication no longer gives: by its name for a name-only row, and for a linked one by the person
     * the backfill's own rules (KnownRulers, PersonNameMatcher) find for one of the names.
     */
    private function dedications(): void
    {
        $guard = ProtectedColumns::for(Work::class);
        $matcher = new PersonNameMatcher;
        $rows = [];
        foreach (DB::table('work_people')->leftJoin('people', 'people.id', '=', 'work_people.person_id')->whereIn('role', self::DEDICATION_ROLES)
            ->get(['work_people.id', 'work_id', 'role', 'person_id', 'work_people.name', 'people.name as person_name', 'suggestion_id']) as $row) {
            $rows[$row->work_id][] = $row;
        }
        $works = DB::table('works')->where(fn ($q) => $q->where(fn ($q) => $q->whereNotNull('dedication')->where('dedication', '<>', ''))->orWhereIn('id', array_keys($rows)))
            ->orderBy('id')->get(['id', 'dedication', 'composition_year_hijri']);
        foreach ($works as $work) {
            if ($guard->protectsRows($work->id, 'people')) {
                continue;
            }
            $given = [];
            foreach (DedicationParser::parse((string) $work->dedication) as $p) {
                $name = PersonNameMatcher::clean($p['name']);
                if (mb_strlen($name) <= BackfillWorkDedicationsCommand::MAX_NAME) {
                    $given[$p['role']][] = $p + ['clean' => $name];
                }
            }
            $have = collect($rows[$work->id] ?? []);
            foreach ($given as $role => $people) {
                $missing = array_filter($people, fn ($p) => ! $have->where('role', $role)->contains(fn ($row) => $this->gives([$p], $row, $work, $matcher)));
                if ($missing) {
                    $this->add('dedications_missing', $work->id, "#{$work->id} {$role}: " . implode('، ', array_column($missing, 'clean')));
                }
            }
            foreach ($have->whereNull('suggestion_id') as $row) {
                if (! $this->gives($given[$row->role] ?? [], $row, $work, $matcher)) {
                    $this->add('dedications_not_given', $row->id, "work_people #{$row->id} (work #{$work->id}) {$row->role}: "
                        . ($row->person_id ? "#{$row->person_id} {$row->person_name}" : $row->name) . ' ← «' . mb_substr((string) $work->dedication, 0, 80) . '»');
                }
            }
        }
    }

    private function gives(array $people, object $row, object $work, PersonNameMatcher $matcher): bool
    {
        foreach ($people as $p) {
            if (! $row->person_id) {
                if ($row->name === $p['clean']) {
                    return true;
                }
                continue;
            }
            if (TextNormalizer::normalize($row->person_name) === TextNormalizer::normalize($p['clean'])) {
                return true;
            }
            $ruler = KnownRulers::find($p['clean'], $work->composition_year_hijri === null ? null : (int) $work->composition_year_hijri, $p['death']);
            if ($ruler && ($ruler['ruler']['name'] === $row->person_name || in_array($row->person_name, $ruler['group'], true))) {
                return true;
            }
            if (in_array((int) $row->person_id, $matcher->ids(TextNormalizer::normalize($p['clean'])), true)
                || $matcher->match($p['clean'], PersonNameMatcher::century($p['death']), requireCentury: true)[0]?->id === (int) $row->person_id) {
                return true;
            }
        }

        return false;
    }

    /** Copies whose pointer rows differ from what their incipit and explicit name among their work's excerpts. */
    private function pointers(): void
    {
        $workIds = TextExcerpt::where('owner_type', Work::class)->distinct()->pluck('owner_id')->all();
        $stored = [];
        foreach (TextExcerpt::where('owner_type', Manuscript::class)->get(['owner_id', 'kind', 'same_as_excerpt_id', 'same_as_raw']) as $row) {
            $stored[$row->owner_id][$row->kind] = $row;
        }
        $copies = DB::table('manuscripts')->where(fn ($q) => $q->whereIn('id', array_keys($stored))
            ->orWhere(fn ($q) => $q->whereIn('work_id', $workIds)->where(fn ($q) => $q->where('incipit_text', 'like', '%برابر%')->orWhere('explicit_text', 'like', '%برابر%'))))
            ->get(['id', 'work_id', 'incipit_text', 'explicit_text']);
        $excerpts = [];
        foreach ($copies as $copy) {
            $excerpts[$copy->work_id] ??= FillTextExcerptsCommand::excerptsOf((int) $copy->work_id);
            foreach (['incipit' => $copy->incipit_text, 'explicit' => $copy->explicit_text] as $kind => $text) {
                $text = trim((string) $text);
                $pointer = FillTextExcerptsCommand::pointer($text, $excerpts[$copy->work_id][$kind]);
                $want = $pointer && $pointer['found'] ? [$pointer['target']?->id, mb_substr($text, 0, 120)] : null;
                $row = $stored[$copy->id][$kind] ?? null;
                $have = $row ? [$row->same_as_excerpt_id, $row->same_as_raw] : null;
                if ($want != $have) {
                    $this->add('excerpt_pointers', $copy->id, "#{$copy->id} {$kind}: " . ($have ? "«{$have[1]}» → #{$have[0]}" : 'none') . ' ≠ ' . ($want ? "«{$want[1]}» → #{$want[0]}" : 'none'));
                }
            }
        }
    }

    private function add(string $kind, int $id, string $example): void
    {
        $this->report[$kind]['count']++;
        $this->report[$kind]['ids'][] = $id;
        if (count($this->report[$kind]['examples']) < (int) $this->option('show')) {
            $this->report[$kind]['examples'][] = $example;
        }
    }

    private function print(string $title): void
    {
        $this->line("== {$title}");
        foreach ($this->report as $kind => $r) {
            $this->line(sprintf('  %-26s %8d', $kind, $r['count']));
        }
        foreach ($this->report as $kind => $r) {
            if ($r['examples']) {
                $this->line("-- {$kind}");
                foreach ($r['examples'] as $line) {
                    $this->line("   {$line}");
                }
            }
        }
        $this->line('  The search index is not compared: the commands above reindex every record they write.');
    }
}
