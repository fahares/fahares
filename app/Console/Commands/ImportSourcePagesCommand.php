<?php

namespace App\Console\Commands;

use App\Console\Commands\MatchCatalogEditionCommand as Format;
use App\Models\SourceVolume;
use App\Sources\Extractors\Extractors;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Loads the text of a volume's pages into source_pages (step 13.6), from the files catalogs:match-edition reads (a
 * directory of {"page", "text"} JSON files, or a .jsonl / .jsonl.gz file), so the site can take an entry's text again
 * at a corrected address. --extractor names the printed format of the volume's source (App\Sources\Extractors).
 * Running it again replaces the pages it is given.
 */
class ImportSourcePagesCommand extends Command
{
    protected $signature = 'sources:import-pages
        {volume : facsimile_code of the volume (source_volumes row)}
        {pages : Directory of page JSON files, or a .jsonl / .jsonl.gz file, with "page" and "text"}
        {--volume= : Volume number, when the code has several}
        {--extractor= : The printed format of the source, to set it (one of Extractors::FORMATS)}
        {--dry-run : Count the pages without saving anything}';

    protected $description = 'Load the page texts of a source volume, for taking entry texts at corrected addresses';

    public function handle(): int
    {
        $volumes = SourceVolume::with('source')->where('facsimile_code', $this->argument('volume'))
            ->when($this->option('volume'), fn ($q, $v) => $q->where('volume_number', $v))->get();
        if ($volumes->count() !== 1) {
            $this->error("Expected one volume {$this->argument('volume')}, found {$volumes->count()} (use --volume)");

            return self::FAILURE;
        }
        $volume = $volumes[0];
        $extractor = $this->option('extractor');
        if ($extractor !== null && ! isset(Extractors::FORMATS[$extractor])) {
            $this->error("Unknown extractor {$extractor}: " . implode(', ', array_keys(Extractors::FORMATS)));

            return self::FAILURE;
        }
        $pages = Format::readPages($this->argument('pages'));
        if (! $pages) {
            $this->error('No page texts found in ' . $this->argument('pages'));

            return self::FAILURE;
        }

        $this->line(sprintf('%s v%d (%s): %d pages, %d–%d, %s characters', $volume->facsimile_code, $volume->volume_number,
            $volume->source->title, count($pages), array_key_first($pages), array_key_last($pages), number_format(array_sum(array_map('mb_strlen', $pages)))));
        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing is saved.');

            return self::SUCCESS;
        }

        $now = now();
        $rows = [];
        foreach ($pages as $page => $text) {
            $rows[] = ['source_volume_id' => $volume->id, 'page' => $page, 'text' => $text, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('source_pages')->upsert($chunk, ['source_volume_id', 'page'], ['text', 'updated_at']);
        }
        if ($extractor !== null) {
            $volume->source->update(['extractor' => $extractor]);
        }
        $this->info(count($rows) . ' pages saved' . ($extractor ? "; {$volume->source->title} is read as {$extractor}" : '') . '.');

        return self::SUCCESS;
    }
}
