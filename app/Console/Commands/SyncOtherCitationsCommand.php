<?php

namespace App\Console\Commands;

use App\Sources\SourceEntrySync;
use App\Support\CatalogCitations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Makes the entries of the places a copy cites besides its catalogue entry's (step 14.42), one for each place of
 * each source: «[اهدائی رهبر: 3-673] و [ف: 21-1380]», «[ف: 11-495 و 12-407]». Without --apply it reports what it
 * would make. Safe to run again: the entries follow the copy's text.
 */
class SyncOtherCitationsCommand extends Command
{
    protected $signature = 'fahares:sync-other-citations {--apply : Write the entries} {--show=10 : Sample copies to print}';

    protected $description = 'One source entry for each further place of a printed catalogue a copy cites';

    public function handle(): int
    {
        ini_set('memory_limit', '2048M');
        DB::disableQueryLog();
        $copies = $places = 0;
        $shown = 0;
        $ids = [];
        DB::table('manuscripts')->select(['id', 'metadata', 'raw_text'])->where('raw_text', 'like', '%]%')
            ->chunkById(5000, function ($rows) use (&$copies, &$places, &$shown, &$ids) {
                foreach ($rows as $m) {
                    $others = CatalogCitations::others($m->metadata, $m->raw_text);
                    if (! $others) {
                        continue;
                    }
                    $copies++;
                    $places += count($others);
                    $ids[] = $m->id;
                    if ($shown++ < (int) $this->option('show')) {
                        $this->line("#{$m->id}: " . implode(' | ', array_column($others, 'text')));
                    }
                }
            });
        $had = DB::table('source_entries')->where('legacy_type', 'fankha_citation_more')->count();
        $this->info("{$copies} copies cite {$places} further places ({$had} entries now)");
        if (! $this->option('apply')) {
            $this->warn('Dry run: nothing written.');

            return self::SUCCESS;
        }
        // copies that no longer cite a further place lose theirs
        $stale = DB::table('source_entries')->where('legacy_type', 'fankha_citation_more')->whereNotIn('legacy_id', $ids ?: [0])->delete();
        DB::transaction(function () use ($ids) {
            foreach (array_chunk($ids, 2000) as $part) {
                SourceEntrySync::syncOtherCited(DB::table('manuscripts')->whereIn('id', $part)->get(['id', 'metadata', 'raw_text']));
            }
        });
        $q = DB::table('source_entries')->where('legacy_type', 'fankha_citation_more');
        $this->info(sprintf('Written: %d entries, %d at a volume, %d stale removed', (clone $q)->count(), (clone $q)->whereNotNull('source_volume_id')->count(), $stale));

        return self::SUCCESS;
    }
}
