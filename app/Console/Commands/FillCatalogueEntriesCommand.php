<?php

namespace App\Console\Commands;

use App\Models\SourceEntry;
use App\Sources\SourceEntrySync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Copies each copy's catalogue part and cataloger (manuscripts.catalog_volume_part_id, cataloger_id) onto its citation
 * entry (step 14.403, backlog item 170). Run once after the deploy that adds those entry columns; the writers keep
 * them in step from then on. By ranges of 5,000 copies, only the entries that differ: safe to run again.
 */
class FillCatalogueEntriesCommand extends Command
{
    protected $signature = 'sources:fill-catalogue-entries {--dry-run : Count the entries that differ, write nothing}';

    protected $description = "Copy each copy's catalogue part and cataloger onto its citation entry";

    public function handle(): int
    {
        if (! SourceEntry::holdsCatalogue()) {
            $this->error('source_entries has no catalog_volume_part_id / cataloger_id yet: run the migrations first.');

            return self::FAILURE;
        }
        $differ = fn () => DB::table('source_entries as e')->join('manuscripts as m', 'm.id', '=', 'e.legacy_id')
            ->where('e.legacy_type', 'fankha_citation')->where('e.legacy_index', 0)
            ->where(fn ($q) => $q->whereRaw('NOT (e.catalog_volume_part_id <=> m.catalog_volume_part_id)')->orWhereRaw('NOT (e.cataloger_id <=> m.cataloger_id)'))
            ->count();
        $this->info('Entries that differ from their copy: ' . number_format($differ()));
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        $written = SourceEntrySync::syncCatalogueAll(fn ($at, $last, $written) => $at % 50000 < 5000 || $at === $last
            ? $this->line(sprintf('  copies up to #%s: %s written', number_format($at), number_format($written))) : null);
        $this->info('Written: ' . number_format($written) . '; still differing: ' . number_format($differ()));

        return self::SUCCESS;
    }
}
