<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Models\SourceVolume;
use Illuminate\Console\Command;

/**
 * Loads the tables of contents of the sources' volumes (step 13.84) into source_volumes.metadata.toc, from the file
 * scripts/sources/noor_toc.py writes: {code: {volume: [{"title", "page", "level"}]}}. Running it again replaces them.
 */
class ImportSourceTocCommand extends Command
{
    protected $signature = 'sources:import-toc
        {file : JSON file of the tables of contents}
        {--dry-run : Count them without saving anything}';

    protected $description = 'Load the tables of contents of the sources\' volumes, for a source\'s page';

    public function handle(): int
    {
        $data = json_decode((string) @file_get_contents($this->argument('file')), true);
        if (! is_array($data)) {
            $this->error('Cannot read ' . $this->argument('file'));

            return self::FAILURE;
        }

        foreach ($data as $code => $volumes) {
            $source = Source::where('code', $code)->first();
            if (! $source) {
                $this->warn("{$code}: no such source");
                continue;
            }
            $saved = 0;
            foreach ($volumes as $number => $toc) {
                $volume = SourceVolume::where('source_id', $source->id)->where('volume_number', (int) $number)->first();
                if (! $volume) {
                    $this->warn("{$code} v{$number}: no such volume");
                    continue;
                }
                $toc = array_values(array_filter(array_map(fn ($h) => ['title' => trim((string) ($h['title'] ?? '')), 'page' => (int) ($h['page'] ?? 0),
                    'level' => max(1, (int) ($h['level'] ?? 1))], $toc), fn ($h) => $h['title'] !== '' && $h['page'] > 0));
                if (! $this->option('dry-run')) {
                    $volume->update(['metadata' => array_merge($volume->metadata ?? [], ['toc' => $toc])]);
                }
                $saved += count($toc);
            }
            $this->line("{$code}: {$saved} headings in " . count($volumes) . ' volumes');
        }
        $this->info($this->option('dry-run') ? 'Dry run: nothing is saved.' : 'Saved.');

        return self::SUCCESS;
    }
}
