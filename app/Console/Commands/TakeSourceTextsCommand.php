<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Sources\Extractors\Extractors;
use App\Sources\Extractors\NumberedCopyCatalogueExtractor as Copies;
use App\Sources\TextRefresh;
use Illuminate\Console\Command;

/**
 * Takes the printed text of every copy a source cites that has none yet (step 14.206): the entries made from Fankha's
 * references to a library catalogue whose page texts came later, such as Golpaygani's. Each is found near the page
 * it has (TextRefresh::find, with the extractor's own reading of a misprinted shelfmark), and its address becomes the
 * one found: the page of the entry's heading, the copy's page and its number. --extractor sets the source's printed
 * format first (one of Extractors::FORMATS). A text already taken, or written by hand, is never touched, so a second
 * run finds nothing to do. Each change is an «update» in the audit log, so it rolls back like any other.
 */
class TakeSourceTextsCommand extends Command
{
    protected $signature = 'sources:take-texts
        {source : The source, by id or code}
        {--extractor= : Set the source\'s printed format first}
        {--dry-run : Report what would change without saving}
        {--show=15 : How many copies not found to list}';

    protected $description = 'Take the printed text of the copies a source cites that have none yet';

    public function handle(): int
    {
        $source = Source::where('id', $this->argument('source'))->orWhere('code', $this->argument('source'))->first();
        if (! $source) {
            $this->error("No source {$this->argument('source')}");

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');
        if ($extractor = $this->option('extractor')) {
            if (! isset(Extractors::FORMATS[$extractor])) {
                $this->error("Unknown extractor {$extractor}: " . implode(', ', array_keys(Extractors::FORMATS)));

                return self::FAILURE;
            }
            $source->extractor = $extractor;
            $dry || $source->save();
        }
        if (! Extractors::for($source->extractor)) {
            $this->error("{$source->title} has no extractor: pass --extractor");

            return self::FAILURE;
        }

        $entries = SourceEntry::with(['sourceVolume', 'citable'])->where('source_id', $source->id)->where('citable_type', Manuscript::class)
            ->where('text_status', SourceEntry::TEXT_NONE)->orderBy('volume_number')->orderBy('page_start')->orderBy('id')->get();
        $stats = ['without text' => $entries->count(), 'no page or pages' => 0, 'not found' => 0, 'taken' => 0, 'by a misprint' => 0, 'address changed' => 0];
        $missed = [];
        foreach ($entries as $entry) {
            $entry->setRelation('source', $source);
            if (! $entry->page_start || ! Extractors::canRead($entry)) {
                $stats['no page or pages']++;

                continue;
            }
            $found = TextRefresh::find($entry, null, null);
            if (! $found) {
                $stats['not found']++;
                $missed[] = [$entry->id, $entry->volumeNumber(), $entry->page_start, $entry->citable?->shelfmark];

                continue;
            }
            $stats['taken']++;
            // a copy whose printed numbers are not its own was taken for a misprint of one of them
            if ($source->extractor === 'numbered_copies' && Copies::numbers((string) preg_replace('/^.*?شماره\s*:?/u', '', strtok((string) $found['copy_text'], "\n")))
                !== Copies::numbers((string) $entry->citable?->shelfmark)) {
                $stats['by a misprint']++;
            }
            if ($found['page_start'] !== $entry->page_start) {
                $stats['address changed']++;
            }
            if ($dry) {
                continue;
            }
            $old = TextRefresh::apply($entry, null, null, $found);
            AuditLog::create([
                'user_id' => null,
                'auditable_type' => SourceEntry::class,
                'auditable_id' => $entry->id,
                'action' => 'update',
                'old_values' => array_diff_key($old, ['id' => 0]),
                'new_values' => $entry->fresh()->only(TextRefresh::COLUMNS) + ['command' => 'sources:take-texts'],
            ]);
        }
        Extractors::forget();

        $this->table(['', 'count'], collect($stats)->map(fn ($n, $k) => [$k, number_format($n)])->values()->all());
        foreach (array_slice($missed, 0, (int) $this->option('show')) as [$id, $volume, $page, $shelfmark]) {
            $this->line("  not found: #{$id} v{$volume} p{$page} {$shelfmark}");
        }
        $dry && $this->warn('Dry run: nothing is saved.');

        return self::SUCCESS;
    }
}
