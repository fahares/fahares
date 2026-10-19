<?php

namespace App\Console\Commands;

use App\Models\Library;
use Illuminate\Console\Command;
use Illuminate\Http\File;

/**
 * Sets libraries' official logos from database/data/library_logos.json and the images beside it.
 * Local and production ids differ, so rows are matched by short name and city. A library that
 * already has a logo (one uploaded in the admin panel, say) is left alone unless --force is given.
 */
class ApplyLibraryLogosCommand extends Command
{
    protected $signature = 'fahares:apply-library-logos
        {--file=database/data/library_logos.json : Data file, relative to the project root}
        {--dir=database/data/library_logos : Folder of the logo images, relative to the project root}
        {--force : Replace logos that are already set}
        {--dry-run : Report the changes without saving them}';

    protected $description = 'Set libraries\' official logos from the curated data file';

    public function handle(): int
    {
        $path = base_path($this->option('file'));
        $rows = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        if (! is_array($rows)) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $dir = base_path($this->option('dir'));
        $dry = (bool) $this->option('dry-run');
        $set = $skipped = $missing = 0;
        foreach ($rows as $row) {
            $image = $dir . '/' . $row['file'];
            if (! is_file($image)) {
                $this->error("Image missing: {$image}");

                return self::FAILURE;
            }
            $library = Library::where('name', $row['name'])->where('city', $row['city'])->first();
            if (! $library) {
                $missing++;
                $this->warn("Not found: {$row['name']} ({$row['city']})");

                continue;
            }
            if ($library->logo_url && ! $this->option('force')) {
                $skipped++;

                continue;
            }

            $this->line("{$library->id}\t{$library->name} ({$library->city}) ← {$row['file']}" . ($row['on_dark'] ? ' [dark plate]' : ''));
            if (! $dry) {
                $library->logo_on_dark = (bool) $row['on_dark'];
                $library->storeLogo(new File($image));
            }
            $set++;
        }

        $this->info(($dry ? 'Would set ' : 'Set ') . "{$set} of " . count($rows) . " logos; {$skipped} already had one, {$missing} not found.");

        return self::SUCCESS;
    }
}
