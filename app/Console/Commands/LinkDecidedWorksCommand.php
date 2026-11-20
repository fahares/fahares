<?php

namespace App\Console\Commands;

use App\Models\Work;
use App\Support\WorkCatalogueEntries;
use App\Support\WorkRelationLinker;
use App\Support\WorkRelationParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Writes the related works a decisions file ties to their works (backlog item 6, steps 14.270 and 14.285): the entries
 * of «وابسته به» and «شروح و حواشی» that works:link-related left, judged one by one against candidate works (the
 * corpus's reconciliations/related_works_model_decisions_*.json). Only «match» with confidence «high» is written.
 *
 * Each such entry is a row of work_text_relations (WorkCatalogueEntries): the row is tied to the work with
 * link_source «model», and where the two works have no relation yet, one is made in work_relations with source
 * «model». A pair related the other way round is left. The ties and relations of source «model» are made again on
 * every run and the ones no longer given taken away, as works:link-related does its own; run that command first, so
 * that the pairs it finds are its own.
 *
 * With --proposals, the «match» of confidence «medium» go to the panel's «پیوند آثار مرتبط» (step 14.274): the row
 * gets the work as a candidate, link_source «review» and link_status «proposed», and the page does not tie it until a
 * reviewer confirms. A row that is tied, proposed or rejected already is left as it is.
 */
class LinkDecidedWorksCommand extends Command
{
    public const SOURCE = 'model';

    public const CONFIDENCE = 80;

    protected $signature = 'works:link-decisions
        {file : The decisions file}
        {--dry-run : Read and count without saving}
        {--proposals : Also queue the matches of confidence medium for review in the panel}
        {--no-index : Do not index the changed works again}';

    protected $description = 'Write the related works a decisions file ties to their works (work_text_relations, work_relations)';

    public function handle(): int
    {
        $decisions = json_decode((string) @file_get_contents($this->argument('file')), true)['decisions'] ?? null;
        if (! is_array($decisions)) {
            $this->error('No decisions in ' . $this->argument('file'));

            return self::FAILURE;
        }
        $matches = array_values(array_filter($decisions, fn ($d) => ($d['verdict'] ?? '') === 'match' && ! empty($d['found_id'])));
        $decisions = array_values(array_filter($matches, fn ($d) => ($d['confidence'] ?? '') === 'high'));
        $medium = $this->option('proposals') ? array_values(array_filter($matches, fn ($d) => ($d['confidence'] ?? '') === 'medium')) : [];
        $this->info(count($decisions) . ' decisions to write. Reading the works…');
        $linker = new WorkRelationLinker;

        // every other relation, as it is: a pair it has needs none of ours, and one it has the other way round is left
        $related = DB::table('work_relations')->where('source', '<>', self::SOURCE)->get(['work_id', 'related_work_id'])
            ->mapWithKeys(fn ($r) => ["{$r->work_id}>{$r->related_work_id}" => true]);
        // the catalogue's entries of the works the file names, by work, side and the entry's key
        $rows = DB::table('work_text_relations')->where('source', WorkCatalogueEntries::SOURCE)->whereNotNull('entry_key')
            ->whereIn('work_id', array_unique(array_column([...$decisions, ...$medium], 'work_id')))->orderBy('position')->orderBy('id')->get()
            ->groupBy(fn ($r) => "{$r->work_id}>{$r->side}>{$r->entry_key}")->map(fn ($g) => $g->all())->all();
        $taken = [];
        // the row a decision is about: the file writes an entry as «titles؛ author (born-died)», its key has no life dates
        $rowOf = function (array $d) use (&$rows, &$taken) {
            $entry = (string) $d['entry'];
            $key = str_contains($entry, '؛') ? preg_replace('/\s\(\d*-\d*\)$/u', '', $entry) : $entry;
            foreach ($rows["{$d['work_id']}>{$d['side']}>{$key}"] ?? [] as $row) {
                if (! isset($taken[$row->id])) {
                    return $row;
                }
            }

            return null;
        };

        $ties = $relations = $left = [];
        foreach ($decisions as $d) {
            [$id, $side, $found] = [(int) $d['work_id'], (string) $d['side'], (int) $d['found_id']];
            $row = $rowOf($d);
            $entry = $row ? (WorkRelationParser::parseEntries($row->text)[0] ?? null) : null;
            $why = match (true) {
                ! $linker->has($id) || ! $linker->has($found) || $id === $found => 'a work is not there',
                ! $entry => 'the entry is not in the text',
                default => null,
            };
            $pair = $why ? null : $linker->relationOf($id, $side, $entry, $found);
            if ($pair && isset($related["{$pair['parent']}>{$pair['child']}"])) {
                $why = 'related the other way round';
            }
            if ($why) {
                $left[$why][] = "#{$id} {$side}: {$d['entry']} → #{$found}";

                continue;
            }
            $taken[$row->id] = true;
            $ties[$row->id] = ['row' => $row, 'found' => $found, 'entry' => $entry];
            if (! isset($related["{$pair['child']}>{$pair['parent']}"])) {
                $relations["{$pair['child']}>{$pair['parent']}"] ??= $pair;
            }
        }
        foreach ($left as $why => $lines) {
            $this->warn(count($lines) . " left: {$why}");
            foreach (array_slice($lines, 0, 5) as $line) {
                $this->line("  {$line}");
            }
        }

        $have = DB::table('work_text_relations')->where('link_source', self::SOURCE)->get(['id', 'work_id', 'linked_work_id'])->keyBy('id');
        $addTies = collect($ties)->filter(fn ($t, $id) => (int) ($have[$id]->linked_work_id ?? 0) !== $t['found']);
        $removeTies = $have->diffKeys($ties);
        $want = collect($relations)->keyBy(fn ($p) => "{$p['child']}>{$p['parent']}>{$p['type']}");
        $haveRelations = DB::table('work_relations')->where('source', self::SOURCE)->get(['id', 'work_id', 'related_work_id', 'type'])
            ->keyBy(fn ($r) => "{$r->work_id}>{$r->related_work_id}>{$r->type}");
        $add = $want->diffKeys($haveRelations);
        $remove = $haveRelations->diffKeys($want);
        $this->table(['type', 'relations'], $want->countBy('type')->sortDesc()->map(fn ($n, $t) => [$t, $n])->values()->all());
        $this->info(sprintf('%d entries: %d to add, %d to remove, %d kept', count($ties), $addTies->count(), $removeTies->count(), count($ties) - $addTies->count()));
        $this->info(sprintf('%d relations: %d to add, %d to remove, %d kept', $want->count(), $add->count(), $remove->count(), $want->count() - $add->count()));

        // the proposals: a row that is tied, proposed or rejected already is left as it is
        $proposals = [];
        foreach ($medium as $d) {
            $row = $rowOf($d);
            $found = (int) $d['found_id'];
            $entry = $row && $linker->has((int) $d['work_id']) && $linker->has($found) && (int) $d['work_id'] !== $found ? (WorkRelationParser::parseEntries($row->text)[0] ?? null) : null;
            $pair = $entry ? $linker->relationOf((int) $d['work_id'], (string) $d['side'], $entry, $found) : null;
            if (! $pair || isset($related["{$pair['parent']}>{$pair['child']}"]) || $row->link_status !== null) {
                continue;
            }
            $taken[$row->id] = true;
            $proposals[$row->id] = ['linked_work_id' => $found, 'link_source' => 'review', 'link_status' => 'proposed', 'confidence' => 60,
                'why' => mb_substr((string) ($d['why'] ?? ''), 0, 300) ?: null];
        }
        if ($this->option('proposals')) {
            $this->info(sprintf('%d matches of confidence medium: %d proposals to add', count($medium), count($proposals)));
        }
        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing is saved.');

            return self::SUCCESS;
        }

        $now = now();
        $works = Work::whereIn('id', collect($ties)->pluck('found')->unique())->get(['id', 'clean_title', 'primary_title', 'alternative_titles', 'author_name'])->keyBy('id');
        DB::transaction(function () use ($ties, $addTies, $removeTies, $add, $remove, $proposals, $works, $now) {
            // the ties no longer given first: the rules may tie such a row again (below)
            foreach ($removeTies->pluck('id')->chunk(1000) as $ids) {
                DB::table('work_text_relations')->whereIn('id', $ids)->update(['linked_work_id' => null, 'link_status' => null, 'link_source' => null, 'differs' => false,
                    'why' => null, 'confidence' => null]);
            }
            foreach ($ties as $id => $tie) {
                $work = $works->get($tie['found']);
                DB::table('work_text_relations')->where('id', $id)->update(['linked_work_id' => $tie['found'], 'link_status' => 'linked', 'link_source' => self::SOURCE,
                    'confidence' => self::CONFIDENCE, 'differs' => $work ? WorkCatalogueEntries::wordsDiffer($tie['entry'], WorkCatalogueEntries::linkedPart($tie['entry'], $work), $work) : false,
                    'updated_at' => $now]);
            }
            foreach ($remove->pluck('id')->chunk(1000) as $ids) {
                DB::table('work_relations')->whereIn('id', $ids)->delete();
            }
            foreach ($add->chunk(500) as $chunk) {
                DB::table('work_relations')->insertOrIgnore($chunk->map(fn ($p) => [
                    'work_id' => $p['child'], 'related_work_id' => $p['parent'], 'type' => $p['type'], 'note' => null,
                    'source' => self::SOURCE, 'confidence' => self::CONFIDENCE, 'created_at' => $now, 'updated_at' => $now,
                ])->values()->all());
            }
            foreach ($proposals as $id => $proposal) {
                DB::table('work_text_relations')->where('id', $id)->whereNull('link_status')->update($proposal + ['updated_at' => $now]);
            }
        });
        // a row a decision no longer ties goes back to the rules
        foreach (Work::whereIn('id', $removeTies->pluck('work_id')->unique())->get(['id']) as $work) {
            WorkCatalogueEntries::tie($work);
        }
        $this->info("Saved: {$addTies->count()} entries and {$add->count()} relations added, {$removeTies->count()} and {$remove->count()} removed.");
        if ($proposals) {
            $this->info(count($proposals) . ' proposals queued for review.');
        }

        // the works made on another feed the search with its titles (Work::baseTitles)
        $changed = $add->pluck('child')->merge($remove->pluck('work_id'))->unique()->values();
        if (! $this->option('no-index') && $changed->isNotEmpty()) {
            foreach ($changed->chunk(500) as $ids) {
                Work::whereIn('id', $ids)->searchable();
            }
            $this->info("Indexed {$changed->count()} works again.");
        }

        return self::SUCCESS;
    }
}
