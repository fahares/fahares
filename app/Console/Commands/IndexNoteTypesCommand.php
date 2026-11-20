<?php

namespace App\Console\Commands;

use App\Models\Manuscript;
use App\Models\ManuscriptNote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sends the types of every copy's notes to the search index (step 14.269), as a partial update of each copy's
 * document: only `id` and `note_types` travel, so the 324 000 documents need not be built again (the lesson of step
 * 14.201). A copy without notes gets an empty list, so a second run after fahares:import-copy-notes also clears what
 * a copy lost. Paces itself like fahares:reindex-work-estimates. Safe to run again, and to resume with --from.
 */
class IndexNoteTypesCommand extends Command
{
    protected $signature = 'fahares:index-note-types
        {--chunk=5000 : Copies per batch}
        {--from=0 : The first copy id (to resume an interrupted run)}
        {--sleep=1 : Seconds to rest after each batch}
        {--max-queued=1 : Wait while Meilisearch has more tasks than this waiting or in work}
        {--apply : Send the updates; without it only counts}';

    protected $description = "Send the types of the copies' notes to the search index as a partial update";

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $engine = config('scout.driver') === 'meilisearch';
        $index = $apply && $engine ? app(\Meilisearch\Client::class)->index((new Manuscript)->searchableAs()) : null;
        [$copies, $withNotes, $lastId] = [0, 0, max(0, (int) $this->option('from')) - 1];
        $total = DB::table('manuscripts')->where('id', '>', $lastId)->count();

        while (($ids = DB::table('manuscripts')->where('id', '>', $lastId)->orderBy('id')->limit(max(1, (int) $this->option('chunk')))->pluck('id'))->isNotEmpty()) {
            $notes = DB::table('manuscript_notes')->whereIn('manuscript_id', $ids)->get(['manuscript_id', 'type', 'also'])->groupBy('manuscript_id');
            $documents = $ids->map(fn ($id) => ['id' => (int) $id, 'note_types' => ManuscriptNote::typesOf($notes->get($id, []))])->all();
            $copies += count($documents);
            $withNotes += count(array_filter($documents, fn ($d) => $d['note_types']));
            $lastId = (int) $ids->last();
            if ($index) {
                $this->waitForEngine();
                $index->updateDocuments($documents, 'id');
                sleep(max(0, (int) $this->option('sleep')));
            }
            $this->line(($apply ? 'Sent' : 'Counted') . " {$copies} of {$total} copies (up to #{$lastId})");
        }
        $this->info(($apply ? ($engine ? 'Sent' : 'No search engine here; counted') : 'Would send') . " the note types of {$copies} copies, {$withNotes} of them with notes.");

        return self::SUCCESS;
    }

    /** Holds the next batch back while Meilisearch still has earlier ones to work through. */
    private function waitForEngine(): void
    {
        $limit = max(0, (int) $this->option('max-queued'));
        $client = app(\Meilisearch\Client::class);
        $waited = 0;
        while (($pending = $client->getTasks((new \Meilisearch\Contracts\TasksQuery)->setStatuses(['enqueued', 'processing'])->setLimit(1))->getTotal()) > $limit) {
            if ($waited % 60 === 0) {
                $this->line("  waiting: Meilisearch has {$pending} tasks to work through");
            }
            sleep(5);
            $waited += 5;
        }
    }
}
