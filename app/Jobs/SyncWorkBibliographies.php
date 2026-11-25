<?php

namespace App\Jobs;

use App\Models\Work;
use App\Sources\RecordEntries;
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

    /**
     * Reads each work's citations again from its entries (step 14.396): a place a source's citation form now finds is
     * written there, and a text taken at a place the entry has left is marked; a review or a tie stays.
     */
    public static function run(array $works): void
    {
        foreach (array_chunk($works, 500) as $ids) {
            foreach (Work::whereIn('id', $ids)->get(['id', 'created_at']) as $work) {
                RecordEntries::bibliography($work, RecordEntries::bibliographyOf($work));
            }
        }
    }
}
