<?php

namespace App\Console\Commands;

use App\Models\Library;
use App\Models\Manuscript;
use Illuminate\Console\Command;

/**
 * Sets libraries' official full names from database/data/library_full_names.json.
 * Names come from Fankha's «رموز و اختصارات» list (source "fankha"); libraries missing from it
 * get a name built from the short name (source "auto"). Local and production ids differ, so rows
 * are matched by slug, then by short name and city.
 */
class ApplyLibraryFullNamesCommand extends Command
{
    protected $signature = 'fahares:apply-library-full-names
        {--file=database/data/library_full_names.json : Data file, relative to the project root}
        {--dry-run : Report the changes without saving them}
        {--no-reindex : Do not reindex the manuscripts of renamed libraries}';

    protected $description = 'Set libraries\' full names from the curated data file and reindex their manuscripts';

    public function handle(): int
    {
        $path = base_path($this->option('file'));
        $rows = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        if (! is_array($rows)) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $changed = [];
        $missing = 0;
        foreach ($rows as $row) {
            $library = Library::where('slug', $row['slug'])->first()
                ?? Library::where('name', $row['name'])->where('city', $row['city'])->first();
            if (! $library) {
                $missing++;
                $this->warn("Not found: {$row['name']} ({$row['city']})");

                continue;
            }
            if ($library->full_name === $row['full_name']) {
                continue;
            }

            $this->line("{$library->id}\t{$library->name} ({$library->city}): {$library->full_name} → {$row['full_name']}");
            if (! $dry) {
                $library->update(['full_name' => $row['full_name']]);
            }
            $changed[] = $library->id;
        }

        $this->info(($dry ? 'Would change ' : 'Changed ') . count($changed) . ' of ' . count($rows) . " libraries; {$missing} not found.");

        if (! $dry && $changed && ! $this->option('no-reindex')) {
            // The manuscripts index carries the library's full name, so renamed libraries' copies are re-sent
            $count = 0;
            Manuscript::whereIn('library_id', $changed)->with(['work:id,primary_title,clean_title,author_name', 'scripts:id,name', 'libraryRecord:id,full_name'])
                ->chunkById(1000, function ($manuscripts) use (&$count) {
                    $manuscripts->searchable();
                    $count += $manuscripts->count();
                });
            $this->info("Queued {$count} manuscripts for reindexing.");
        }

        return self::SUCCESS;
    }
}
