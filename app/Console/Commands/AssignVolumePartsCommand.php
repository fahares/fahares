<?php

namespace App\Console\Commands;

use App\Models\Cataloger;
use App\Models\CatalogVolumePart;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gives every copy of a volume with parts the part its library and page name, and its first cataloger as the copy's
 * own (step 13.150, docs/plans/catalog-volume-parts.md); a copy that has a part its volume no longer holds leaves it.
 * Then takes the part and cataloger counts again. Reports how each part was found and the copies on their library's
 * one part whose page is not in its pages, a sign of a wrong tie. SourceEntrySync::syncCited does the same for the
 * copies a command moves; this is for the parts made or edited. Safe to run again.
 */
class AssignVolumePartsCommand extends Command
{
    protected $signature = 'catalogs:assign-volume-parts {--dry-run : Report the counts without saving}';

    protected $description = 'Give each copy of a volume with parts its part, and take the part and cataloger counts again';

    public function handle(): int
    {
        $ids = DB::table('manuscripts')->whereIn('catalog_volume_id', DB::table('catalog_volume_parts')->select('catalog_volume_id'))
            ->orWhereNotNull('catalog_volume_part_id')->pluck('id');
        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing is saved.');
            DB::beginTransaction();
        }
        $stats = CatalogVolumePart::assign($ids);
        Cataloger::recount();
        if ($this->option('dry-run')) {
            DB::rollBack();
        }

        $this->info(sprintf('%d copies read.', $ids->count()));
        foreach (['library' => 'by their library', 'page' => 'by their library and page', 'by_page' => 'by their page alone',
            'none' => 'without a part', 'outside' => 'page outside their part', 'changed' => 'changed'] as $key => $label) {
            $this->line(sprintf('  %s: %d', $label, $stats[$key]));
        }

        return self::SUCCESS;
    }
}
