<?php

namespace App\Jobs;

use App\Models\Work;
use App\Sources\SourceEntrySync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Makes the bibliography entries of some works again, after a change to how a source is cited (step 14.57): run in
 * the request for a small change, in the queue for a large one.
 */
class SyncWorkBibliographies implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    /** @param  list<int>  $works */
    public function __construct(public array $works) {}

    public function handle(): void
    {
        self::run($this->works);
    }

    /** As SourceEntrySync::syncBibliography for each work, the sources and their volumes read once. */
    public static function run(array $works): void
    {
        $sources = SourceEntrySync::configSources();
        $volumes = SourceEntrySync::volumeIds(array_values(array_filter($sources)));
        foreach (array_chunk($works, 500) as $ids) {
            $rows = [];
            foreach (Work::whereIn('id', $ids)->get(['id', 'bibliography', 'created_at']) as $work) {
                $strings = SourceEntrySync::bibliographyStrings($work->getAttributes()['bibliography'] ?? null);
                foreach (SourceEntrySync::bibliographyValues($work->id, $strings, $sources, $volumes) as $values) {
                    $rows[] = SourceEntrySync::row($values, $work->created_at);
                }
                \Illuminate\Support\Facades\DB::table('source_entries')->where('legacy_type', 'work_bibliography')->where('legacy_id', $work->id)
                    ->where('legacy_index', '>=', count($strings))->delete();
            }
            if ($rows) {
                SourceEntrySync::upsert($rows);
            }
        }
    }
}
