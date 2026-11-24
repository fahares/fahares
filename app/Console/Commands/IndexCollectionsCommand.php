<?php

namespace App\Console\Commands;

use App\Models\Manuscript;
use App\Support\CollectionIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sends what the treatises of the collections read from their «مجموعه» to the search index (backlog item 163, step
 * 14.329), as a partial update of each copy's document: only `id` and the fields of CollectionIndex::FIELDS travel
 * (the hand, the lines, the scribe, the size, the paper, the binding, the note types), so the documents need not be
 * built again. To run after fahares:build-collections --apply changed many collections at once; a change of one
 * collection sends its treatises by itself (Collections::reindex). Paces itself like fahares:index-note-types.
 * Safe to run again, and to resume with --from.
 */
class IndexCollectionsCommand extends Command
{
    protected $signature = 'fahares:index-collections
        {--chunk=5000 : Copies per batch, at least}
        {--from=0 : The first collection id (to resume an interrupted run)}
        {--sleep=1 : Seconds to rest after each batch}
        {--max-queued=1 : Wait while Meilisearch has more tasks than this waiting or in work}
        {--apply : Send the updates; without it only counts}';

    protected $description = 'Send what the treatises read from their collections to the search index as a partial update';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $engine = config('scout.driver') === 'meilisearch';
        $index = $apply && $engine ? app(\Meilisearch\Client::class)->index((new Manuscript)->searchableAs()) : null;
        [$copies, $lastId, $reading] = [0, max(0, (int) $this->option('from')) - 1, []];
        $total = DB::table('collections')->where('id', '>', $lastId)->count();
        $done = 0;
        $documents = [];
        $send = function () use (&$documents, &$copies, &$done, &$lastId, $index, $apply, $total) {
            $copies += count($documents);
            if ($index && $documents) {
                $this->waitForEngine();
                $index->updateDocuments($documents, 'id');
                sleep(max(0, (int) $this->option('sleep')));
            }
            $documents = [];
            $this->line(($apply ? 'Sent' : 'Counted') . " {$copies} copies of {$done} of {$total} collections (up to collection #{$lastId})");
        };

        // collection by collection, so that what is known of each (CollectionIndex) is asked once
        while (($ids = DB::table('collections')->where('id', '>', $lastId)->orderBy('id')->limit(200)->pluck('id'))->isNotEmpty()) {
            foreach (Manuscript::whereIn('collection_id', $ids)->with(['scripts:id,name', 'scribes:people.id', 'notes:id,manuscript_id,type,also'])->orderBy('id')->get() as $copy) {
                $fields = CollectionIndex::fields($copy);
                foreach ($fields['inherited'] as $field) {
                    $reading[$field] = ($reading[$field] ?? 0) + 1;
                }
                $documents[] = ['id' => (int) $copy->id] + $fields;
            }
            $done += $ids->count();
            $lastId = (int) $ids->last();
            CollectionIndex::flush();
            if (count($documents) >= max(1, (int) $this->option('chunk'))) {
                $send();
            }
        }
        $send();
        ksort($reading);
        $this->info(($apply ? ($engine ? 'Sent' : 'No search engine here; counted') : 'Would send') . " {$copies} copies; reading from their collection: "
            . ($reading ? http_build_query($reading, '', ', ') : 'none'));

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
