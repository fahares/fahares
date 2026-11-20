<?php

namespace App\Console\Commands;

use App\Models\Work;
use App\Support\WorkRelationLinker;
use App\Support\WorkRelationParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Writes the related works a decisions file ties to their works (backlog item 6, step 14.270): the entries of «وابسته
 * به» and «شروح و حواشی» that works:link-related left, judged one by one against candidate works (the corpus's
 * reconciliations/related_works_model_decisions_*.json). Only «match» with confidence «high» is written.
 *
 * Each such entry gets a row in work_entry_links, by which the work's page links it; and where the two works have no
 * relation yet, one in work_relations with source «model». A pair related the other way round is left. The rows of
 * source «model» are made again on every run and the ones no longer given removed, as works:link-related does its own;
 * run that command first, so that the pairs it finds are its own.
 *
 * With --proposals, the «match» of confidence «medium» go to the panel's «پیوند آثار مرتبط» (step 14.274): rows of
 * source «review» and status «proposed», which the page does not link until a reviewer confirms them. A proposal is
 * written once; an entry that has a row already, of any source or status, is left as it is.
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

    protected $description = 'Write the related works a decisions file ties to their works (work_entry_links, work_relations)';

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
        $texts = DB::table('works')->whereIn('id', array_unique(array_column([...$decisions, ...$medium], 'work_id')))->get(['id', 'related_work', 'commentaries_and_glosses'])->keyBy('id');

        $links = $relations = [];
        $left = [];
        foreach ($decisions as $d) {
            [$id, $side, $found] = [(int) $d['work_id'], (string) $d['side'], (int) $d['found_id']];
            $entry = isset($texts[$id]) ? $this->entry($texts[$id], $side, (string) $d['entry']) : null;
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
            $key = WorkRelationLinker::entryKey($entry);
            // one entry names one work: of two decisions on one entry, the first stands
            if (isset($links["{$id}>{$side}>" . sha1($key)])) {
                continue;
            }
            $links["{$id}>{$side}>" . sha1($key)] = ['work_id' => $id, 'side' => $side, 'entry' => $key, 'entry_hash' => sha1($key), 'linked_work_id' => $found];
            if (! isset($related["{$pair['child']}>{$pair['parent']}"])) {
                $relations["{$pair['child']}>{$pair['parent']}"] ??= $pair;
            }
        }
        foreach ($left as $why => $rows) {
            $this->warn(count($rows) . " left: {$why}");
            foreach (array_slice($rows, 0, 5) as $row) {
                $this->line("  {$row}");
            }
        }

        $links = collect($links)->keyBy(fn ($l) => "{$l['work_id']}>{$l['side']}>{$l['entry_hash']}>{$l['linked_work_id']}")->all();
        $haveLinks = DB::table('work_entry_links')->where('source', self::SOURCE)->get(['id', 'work_id', 'side', 'entry_hash', 'linked_work_id'])
            ->keyBy(fn ($r) => "{$r->work_id}>{$r->side}>{$r->entry_hash}>{$r->linked_work_id}");
        $addLinks = collect($links)->diffKeys($haveLinks);
        $removeLinks = $haveLinks->diffKeys($links);
        $want = collect($relations)->keyBy(fn ($p) => "{$p['child']}>{$p['parent']}>{$p['type']}");
        $have = DB::table('work_relations')->where('source', self::SOURCE)->get(['id', 'work_id', 'related_work_id', 'type'])
            ->keyBy(fn ($r) => "{$r->work_id}>{$r->related_work_id}>{$r->type}");
        $add = $want->diffKeys($have);
        $remove = $have->diffKeys($want);
        $this->table(['type', 'relations'], $want->countBy('type')->sortDesc()->map(fn ($n, $t) => [$t, $n])->values()->all());
        $this->info(sprintf('%d entries: %d to add, %d to remove, %d kept', count($links), $addLinks->count(), $removeLinks->count(), count($links) - $addLinks->count()));
        $this->info(sprintf('%d relations: %d to add, %d to remove, %d kept', $want->count(), $add->count(), $remove->count(), $want->count() - $add->count()));

        // the proposals: an entry that has a row already, of any source or status, is left as it is
        $proposals = [];
        foreach ($medium as $d) {
            [$id, $side, $found] = [(int) $d['work_id'], (string) $d['side'], (int) $d['found_id']];
            $entry = isset($texts[$id]) && $linker->has($id) && $linker->has($found) && $id !== $found ? $this->entry($texts[$id], $side, (string) $d['entry']) : null;
            $pair = $entry ? $linker->relationOf($id, $side, $entry, $found) : null;
            if (! $pair || isset($related["{$pair['parent']}>{$pair['child']}"])) {
                continue;
            }
            $key = WorkRelationLinker::entryKey($entry);
            $proposals["{$id}>{$side}>" . sha1($key)] ??= ['work_id' => $id, 'side' => $side, 'entry' => $key, 'entry_hash' => sha1($key), 'linked_work_id' => $found,
                'source' => 'review', 'status' => 'proposed', 'confidence' => 60, 'why' => mb_substr((string) ($d['why'] ?? ''), 0, 300) ?: null];
        }
        $there = DB::table('work_entry_links')->whereIn('work_id', array_unique(array_column($proposals, 'work_id')))->get(['work_id', 'side', 'entry_hash'])
            ->mapWithKeys(fn ($r) => ["{$r->work_id}>{$r->side}>{$r->entry_hash}" => true])->all();
        $proposals = array_diff_key($proposals, $there, collect($links)->mapWithKeys(fn ($l) => ["{$l['work_id']}>{$l['side']}>{$l['entry_hash']}" => true])->all());
        if ($this->option('proposals')) {
            $this->info(sprintf('%d matches of confidence medium: %d proposals to add', count($medium), count($proposals)));
        }
        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing is saved.');

            return self::SUCCESS;
        }

        $now = now();
        DB::transaction(function () use ($addLinks, $removeLinks, $add, $remove, $now) {
            // the removals first: an entry given another work has its old row out of the way
            foreach ($removeLinks->pluck('id')->chunk(1000) as $ids) {
                DB::table('work_entry_links')->whereIn('id', $ids)->delete();
            }
            foreach ($addLinks->chunk(500) as $chunk) {
                DB::table('work_entry_links')->insert($chunk->map(fn ($l) => $l + ['source' => self::SOURCE, 'confidence' => self::CONFIDENCE, 'created_at' => $now, 'updated_at' => $now])->values()->all());
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
        });
        $this->info("Saved: {$addLinks->count()} entries and {$add->count()} relations added, {$removeLinks->count()} and {$remove->count()} removed.");
        foreach (array_chunk(array_values($proposals), 500) as $chunk) {
            DB::table('work_entry_links')->insertOrIgnore(array_map(fn ($p) => $p + ['created_at' => $now, 'updated_at' => $now], $chunk));
        }
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

    /**
     * The entry of the work's text the decision is about: the file writes it as «titles؛ author (born-died)».
     *
     * @return array{titles: list<string>, author: ?string, born: ?int, died: ?int, type: ?string, base: ?string}|null
     */
    private function entry(object $work, string $side, string $text): ?array
    {
        $texts = $side === 'related' ? [(string) $work->related_work] : (json_decode($work->commentaries_and_glosses ?? '[]', true) ?: []);
        foreach ($texts as $field) {
            foreach (WorkRelationParser::parseEntries((string) $field) as $entry) {
                $dates = $entry['born'] || $entry['died'] ? " ({$entry['born']}-{$entry['died']})" : '';
                if (implode(' = ', $entry['titles']) . ($entry['author'] ? '؛ ' . $entry['author'] . $dates : '') === $text) {
                    return $entry;
                }
            }
        }

        return null;
    }
}
