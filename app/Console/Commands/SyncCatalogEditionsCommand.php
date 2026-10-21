<?php

namespace App\Console\Commands;

use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Library;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Creates or updates the catalogue editions of database/data/catalog_editions.json (backlog item 51): a later
 * printing of a library catalogue volume, as a catalog_volumes row with no catalog_id and no catalogers (so it
 * changes no cataloger count), pointing through edition_of_id at the volume Fankha cites. Rows are found by
 * facsimile_code and volume number, the library and the cited volume by slug and number, never by local id.
 *
 * edition_of may also correct the cited volume: «set» writes its columns and «detach_catalogers» (names) removes
 * catalogers another catalogue brought to it, e.g. Masjid-e A'zam vol 1, which carried the year, publisher and
 * cataloger of the Isfahan series.
 */
class SyncCatalogEditionsCommand extends Command
{
    protected $signature = 'catalogs:sync-editions
        {--dry-run : Report the changes without saving them}
        {--data= : Path to the editions file (default database/data/catalog_editions.json)}';

    protected $description = 'Create or update the catalogue editions whose page images the viewer shows';

    public function handle(): int
    {
        $path = $this->option('data') ?: database_path('data/catalog_editions.json');
        if (! File::exists($path)) {
            $this->error("Editions file not found: {$path}");

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');

        foreach (json_decode(File::get($path), true)['editions'] ?? [] as $spec) {
            $library = Library::where('slug', $spec['library_slug'])->first();
            if (! $library) {
                $this->error("Library not found: {$spec['library_slug']}");

                return self::FAILURE;
            }
            $cited = CatalogVolume::where('library_id', $library->id)->whereNull('edition_of_id')->whereNotNull('catalog_id')
                ->where('volume_number', $spec['edition_of']['volume_number'])->get();
            if ($cited->count() !== 1) {
                $this->error("{$spec['facsimile_code']}: expected one cited volume {$spec['edition_of']['volume_number']} of {$library->slug}, found {$cited->count()}");

                return self::FAILURE;
            }

            $this->correctCited($cited[0], $spec['edition_of'], $dry);

            $values = collect($spec)->except(['library_slug', 'edition_of', 'facsimile_code', 'volume_number'])->all()
                + ['library_id' => $library->id, 'edition_of_id' => $cited[0]->id, 'catalog_id' => null];
            $edition = CatalogVolume::firstOrNew(['facsimile_code' => $spec['facsimile_code'], 'volume_number' => $spec['volume_number']]);
            $edition->fill($values);
            $state = ! $edition->exists ? 'create' : ($edition->isDirty() ? 'update' : 'unchanged');
            $this->line("{$state}: {$spec['facsimile_code']} v{$spec['volume_number']}, edition of volume #{$cited[0]->id} ({$cited[0]->title})");
            if (! $dry && $state !== 'unchanged') {
                $edition->save();
            }
        }

        return self::SUCCESS;
    }

    private function correctCited(CatalogVolume $volume, array $spec, bool $dry): void
    {
        $volume->fill($spec['set'] ?? []);
        $detach = $volume->catalogers()->whereIn('name', $spec['detach_catalogers'] ?? [])->pluck('catalogers.id');
        if (! $volume->isDirty() && $detach->isEmpty()) {
            return;
        }
        $changes = collect($volume->getDirty())->map(fn ($v, $k) => "{$k}: «{$volume->getOriginal($k)}» → «{$v}»")->values()
            ->when($detach->isNotEmpty(), fn ($c) => $c->push('detach catalogers ' . $detach->implode(', ')));
        $this->line("correct: volume #{$volume->id} ({$volume->title}): " . $changes->implode('; '));
        if (! $dry) {
            $volume->save();
            $volume->catalogers()->detach($detach->all());
            // the same counts as LinkCatalogVolumesCommand::recountVolumesAndCatalogers, for the detached only
            Cataloger::whereIn('id', $detach)->each(function (Cataloger $cataloger) {
                $volumeIds = $cataloger->catalogVolumes()->pluck('catalog_volumes.id');
                $cataloger->update(['volumes_count' => $volumeIds->count(), 'manuscripts_count' => DB::table('manuscripts')
                    ->where(fn ($q) => $q->where('cataloger_id', $cataloger->id)->orWhereIn('catalog_volume_id', $volumeIds))->count()]);
            });
        }
    }
}
