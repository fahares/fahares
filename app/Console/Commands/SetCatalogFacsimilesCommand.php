<?php

namespace App\Console\Commands;

use App\Models\CatalogVolume;
use App\Models\Library;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Gives library catalogue volumes their page images (step 14.122): database/data/catalog_facsimiles.json names, for
 * each scanned volume, the folder of its images (facsimile_code and volume number, under
 * storage/app/public/catalogs) and its printed page range. Unlike catalogs:sync-editions, the scan is of the printing
 * Fankha cites, so the code goes on the cited volume itself and every reference to it opens its page at once.
 *
 * A volume is found by library slug, volume number and, when the library has several rows of that number (parts of a
 * volume, «جلد ۲/۲۴»), its exact title, never by local id. A volume the site has no row for yet (printed after Fankha,
 * or a part Fankha never cites) is created from «create» and joins the library's catalogue through the volume sync.
 * Safe to run again: unchanged rows are reported and left alone.
 */
class SetCatalogFacsimilesCommand extends Command
{
    protected $signature = 'catalogs:set-facsimiles
        {--dry-run : Report the changes without saving them}
        {--data= : Path to the data file (default database/data/catalog_facsimiles.json)}';

    protected $description = 'Set the page images (facsimile code and page range) of scanned library catalogue volumes';

    private const COLUMNS = ['facsimile_code', 'page_first', 'page_last', 'pages_count', 'edition_label', 'publication_year', 'publisher'];

    public function handle(): int
    {
        $path = $this->option('data') ?: database_path('data/catalog_facsimiles.json');
        if (! File::exists($path)) {
            $this->error("Data file not found: {$path}");

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');
        $specs = json_decode(File::get($path), true)['volumes'] ?? [];

        $folders = collect($specs)->map(fn ($s) => $s['facsimile_code'] . '/' . $s['volume_number']);
        if ($folders->duplicates()->isNotEmpty()) {
            $this->error('Two volumes share an image folder: ' . $folders->duplicates()->implode(', '));

            return self::FAILURE;
        }

        $counts = ['create' => 0, 'update' => 0, 'unchanged' => 0];
        foreach ($specs as $spec) {
            $library = Library::where('slug', $spec['library_slug'])->first();
            if (! $library) {
                $this->error("Library not found: {$spec['library_slug']}");

                return self::FAILURE;
            }
            $volume = $this->find($library, $spec);
            if ($volume === false) {
                return self::FAILURE;
            }

            $values = collect($spec)->only(self::COLUMNS)->all();
            if (! $volume) {
                $volume = new CatalogVolume(['library_id' => $library->id, 'volume_number' => $spec['volume_number']] + $spec['create']);
            }
            $volume->fill($values);
            $volume->metadata = array_merge((array) $volume->metadata, $spec['metadata'] ?? []);

            $state = ! $volume->exists ? 'create' : ($volume->isDirty() ? 'update' : 'unchanged');
            $counts[$state]++;
            $this->line("{$state}: {$spec['facsimile_code']}/v{$spec['volume_number']} → " . ($volume->exists ? "#{$volume->id} " : '') . $volume->title);
            if (! $dry && $state !== 'unchanged') {
                $volume->save();
            }
        }
        $this->info(sprintf('%d created, %d updated, %d unchanged%s.', $counts['create'], $counts['update'], $counts['unchanged'],
            $dry ? ' (dry run)' : ''));

        return self::SUCCESS;
    }

    /** The volume of the spec: null when it must be created, false (after an error) when the spec is ambiguous. */
    private function find(Library $library, array $spec): CatalogVolume|null|false
    {
        // a volume made by an earlier run is known by its image folder
        $made = CatalogVolume::where('library_id', $library->id)->where('facsimile_code', $spec['facsimile_code'])
            ->where('volume_number', $spec['volume_number'])->first();
        if ($made) {
            return $made;
        }
        $title = $spec['title'] ?? $spec['create']['title'] ?? null;
        $rows = CatalogVolume::where('library_id', $library->id)->whereNull('edition_of_id')
            ->where('volume_number', $spec['volume_number'])
            ->when($title !== null, fn ($q) => $q->where('title', $title))->get();
        if ($rows->count() === 1 && ! isset($spec['create'])) {
            return $rows[0];
        }
        if ($rows->isEmpty() && isset($spec['create'])) {
            return null;
        }
        $this->error(sprintf('%s v%s: expected %s, found %d rows (%s)', $spec['library_slug'], $spec['volume_number'],
            isset($spec['create']) ? 'no row to create' : 'one row', $rows->count(), $rows->pluck('title')->implode(' | ')));

        return false;
    }
}
