<?php

namespace App\Console\Commands;

use App\Support\Collections;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Makes the «مجموعه» record of every bound volume and links its treatises to it (backlog item 163, step 14.322):
 * one record for each library and collection_key, manuscripts.collection_id for each keyed copy (App\Support\Collections).
 * Nothing else of a copy is written and nothing is reindexed. Safe to run again: a second run changes nothing.
 * Without --apply it only reports what differs.
 */
class BuildCollectionsCommand extends Command
{
    protected $signature = 'fahares:build-collections {--apply : Write; without it the command only reports}';

    protected $description = 'Make the record of each bound volume and link its treatises to it';

    public function handle(): int
    {
        if ($this->option('apply')) {
            $counts = Collections::sync();
            $this->info('Written.');
        } else {
            $keyed = fn () => DB::table('manuscripts')->where('manuscripts.collection_pinned', false)->whereNotNull('manuscripts.library_id')->whereNotNull('manuscripts.collection_key')->where('manuscripts.collection_key', '<>', '');
            $link = fn () => $keyed()->leftJoin('collections', fn ($j) => $j->on('collections.library_id', '=', 'manuscripts.library_id')->on('collections.key', '=', 'manuscripts.collection_key'));
            $counts = [
                'made' => DB::query()->fromSub($link()->whereNull('collections.id')->distinct()->select('manuscripts.library_id', 'manuscripts.collection_key'), 'm')->count(),
                'linked' => $link()->where(fn ($q) => $q->whereNull('collections.id')->orWhereNull('manuscripts.collection_id')->orWhereColumn('manuscripts.collection_id', '<>', 'collections.id'))->count(),
                'unlinked' => DB::table('manuscripts')->where('collection_pinned', false)->whereNotNull('collection_id')
                    ->where(fn ($q) => $q->whereNull('library_id')->orWhereNull('collection_key')->orWhere('collection_key', ''))->count(),
            ];
            $this->info('Dry run: nothing written.');
        }
        foreach ($counts as $kind => $n) {
            $this->line(sprintf('  %-12s %8d', $kind, $n));
        }
        $this->line(sprintf('  %-12s %8d', 'collections', DB::table('collections')->count()));
        foreach (DB::table('collections')->selectRaw('kind, COUNT(*) AS n, SUM(copies_count) AS copies, SUM(copies_count = 1) AS single')->groupBy('kind')->orderBy('kind')->get() as $row) {
            $this->line(sprintf('  %-12s %8d  copies %d, with one copy %d', $row->kind, $row->n, $row->copies, $row->single));
        }

        return self::SUCCESS;
    }
}
