<?php

namespace App\Console\Commands;

use App\Models\Work;
use App\Support\WorkRelationLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Writes every entry of the two texts of related works («وابسته به», «شروح و حواشی») to work_text_relations, as the
 * work's page shows it (WorkRelationLinker::pageRows): the first step of dropping the fields works.related_work and
 * works.commentaries_and_glosses (backlog item 6, step 14.283).
 *
 * An entry tied to a work keeps the tie (linked_work_id, link_status linked, and whether the catalogue's words
 * differ): by the judged decisions or a reviewer when work_entry_links says so, else by the rules («auto»). An entry
 * waiting in the panel's queue, or rejected there, keeps its candidate and its state. With --decisions, an entry the
 * judging of step 14.229 tied to no work keeps what it said (model_verdict: unsure, none, medium). What a field says
 * after its entries is a row of type «note».
 *
 * The rows of source «catalogue» are made again on every run and the rows by hand («suggestion») left; until the
 * readers are moved to the table, the command is run again after anything that changes the texts or their ties.
 */
class MaterializeRelatedWorksCommand extends Command
{
    public const SOURCE = 'catalogue';

    protected $signature = 'works:materialize-related
        {--decisions= : The decisions file of step 14.229, for what the judging said of the entries it tied to no work}
        {--dry-run : Read and count without saving}';

    protected $description = 'Write the entries of related_work and commentaries_and_glosses to work_text_relations';

    public function handle(): int
    {
        $verdicts = $this->verdicts();
        if ($verdicts === null) {
            return self::FAILURE;
        }
        $links = DB::table('work_entry_links')->get()->groupBy('work_id');
        $count = ['works' => 0, 'entries' => 0, 'linked' => 0, 'linked, words differ' => 0, 'proposed' => 0, 'rejected' => 0, 'tied to no work' => 0,
            'with a verdict of the judging' => 0, 'notes' => 0];
        $bySource = [];
        $seen = [];
        $now = now();
        // one group: lazyById adds its own «id > …» to the query, which an «or» beside it would escape
        $works = Work::where(fn ($q) => $q->where(fn ($q) => $q->whereNotNull('related_work')->where('related_work', '<>', ''))
            ->orWhere(fn ($q) => $q->whereNotNull('commentaries_and_glosses')->whereNotIn('commentaries_and_glosses', ['', '[]'])));
        foreach ($works->lazyById(500) as $work) {
            $page = WorkRelationLinker::pageRows($work);
            $tied = collect($links->get($work->id) ?? [])->keyBy(fn ($l) => "{$l->side}>{$l->entry}");
            $rows = [];
            foreach ($page['entries'] as $entry) {
                $link = $entry['key'] !== null ? $tied->get("{$entry['side']}>{$entry['key']}") : null;
                $row = ['work_id' => $work->id, 'side' => $entry['side'], 'type' => $entry['type'], 'text' => $entry['text'], 'position' => $entry['position'],
                    'entry_key' => $entry['key'], 'source' => self::SOURCE, 'linked_work_id' => null, 'link_source' => null, 'link_status' => null, 'differs' => false,
                    'why' => null, 'confidence' => null, 'decided_by' => null, 'decided_at' => null, 'model_verdict' => null, 'created_at' => $now, 'updated_at' => $now];
                if ($entry['work']) {
                    $decided = $link && $link->status === 'linked' && (int) $link->linked_work_id === $entry['work']->id ? $link : null;
                    $row = ['linked_work_id' => $entry['work']->id, 'link_status' => 'linked', 'link_source' => $decided->source ?? 'auto', 'differs' => $entry['differs'],
                        'why' => $decided->why ?? null, 'confidence' => $decided->confidence ?? null, 'decided_by' => $decided->decided_by ?? null, 'decided_at' => $decided->decided_at ?? null] + $row;
                    $count['linked']++;
                    $count['linked, words differ'] += (int) $entry['differs'];
                    $bySource[$row['link_source']] = ($bySource[$row['link_source']] ?? 0) + 1;
                } elseif ($link && in_array($link->status, ['proposed', 'rejected'], true)) {
                    // a reviewer's queue: the work is a candidate, not a tie
                    $row = ['linked_work_id' => $link->linked_work_id, 'link_status' => $link->status, 'link_source' => $link->source, 'why' => $link->why,
                        'confidence' => $link->confidence, 'decided_by' => $link->decided_by, 'decided_at' => $link->decided_at] + $row;
                    $count[$link->status]++;
                } else {
                    $count['tied to no work']++;
                }
                if ($row['link_status'] !== 'linked' && $entry['key'] !== null && ($verdict = $verdicts["{$work->id}>{$entry['side']}>{$entry['key']}"] ?? null)) {
                    $row['model_verdict'] = $verdict;
                    $count['with a verdict of the judging']++;
                }
                $rows[] = $row;
            }
            foreach ($page['notes'] as $note) {
                $rows[] = ['work_id' => $work->id, 'side' => 'related', 'type' => 'note', 'text' => $note, 'position' => 999, 'entry_key' => null, 'source' => self::SOURCE,
                    'linked_work_id' => null, 'link_source' => null, 'link_status' => null, 'differs' => false, 'why' => null, 'confidence' => null, 'decided_by' => null,
                    'decided_at' => null, 'model_verdict' => null, 'created_at' => $now, 'updated_at' => $now];
                $count['notes']++;
            }
            $count['works']++;
            $count['entries'] += count($page['entries']);
            $seen[] = $work->id;
            if (! $this->option('dry-run')) {
                DB::transaction(function () use ($work, $rows) {
                    DB::table('work_text_relations')->where('work_id', $work->id)->where('source', self::SOURCE)->delete();
                    foreach (array_chunk($rows, 200) as $chunk) {
                        DB::table('work_text_relations')->insert($chunk);
                    }
                });
            }
        }
        $this->table(['', 'count'], collect($count)->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->line('Tied by: ' . collect($bySource)->map(fn ($n, $k) => "{$k} {$n}")->implode(', '));
        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing is saved.');

            return self::SUCCESS;
        }
        // a work whose two texts are gone has no entry left
        $stale = DB::table('work_text_relations')->where('source', self::SOURCE)->when($seen, fn ($q) => $q->whereNotIn('work_id', $seen))->delete();
        $this->info('Saved: ' . DB::table('work_text_relations')->where('source', self::SOURCE)->count() . " rows of the catalogue; {$stale} of works with no text any more removed.");

        return self::SUCCESS;
    }

    /**
     * What the judging said of the entries it tied to no work, by work, side and the entry's key: unsure, none, and
     * «medium» for a match it was not sure of.
     *
     * @return array<string, string>|null null when the file given cannot be read
     */
    private function verdicts(): ?array
    {
        if (! $this->option('decisions')) {
            return [];
        }
        $decisions = json_decode((string) @file_get_contents($this->option('decisions')), true)['decisions'] ?? null;
        if (! is_array($decisions)) {
            $this->error('No decisions in ' . $this->option('decisions'));

            return null;
        }
        $verdicts = [];
        foreach ($decisions as $d) {
            $verdict = match (true) {
                ($d['verdict'] ?? '') === 'match' && ($d['confidence'] ?? '') === 'high' => null,
                ($d['verdict'] ?? '') === 'match' => 'medium',
                in_array($d['verdict'] ?? '', ['unsure', 'none'], true) => $d['verdict'],
                default => null,
            };
            if ($verdict) {
                // the file writes an entry as «titles؛ author (born-died)»; its key has no life dates
                $entry = (string) $d['entry'];
                $key = str_contains($entry, '؛') ? preg_replace('/\s\(\d*-\d*\)$/u', '', $entry) : $entry;
                $verdicts["{$d['work_id']}>{$d['side']}>{$key}"] = $verdict;
            }
        }

        return $verdicts;
    }
}
