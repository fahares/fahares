<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\SourceEntry;
use App\Sources\Extractors\Extractors;
use App\Sources\TextRefresh;
use Illuminate\Console\Command;

/**
 * Takes again, at the address they now have, the texts marked as taken at an earlier one (step 13.6): the entries a
 * reviewer moved before the review could take the new text, or without taking it. Only sources with an extractor
 * and page texts are read; a text written by hand is never touched. Each change is an «update» in the audit log,
 * so it rolls back like any other.
 */
class RetextSourceEntriesCommand extends Command
{
    protected $signature = 'sources:retext
        {--entry=* : Only these entries}
        {--dry-run : Report what would change without saving}';

    protected $description = 'Take the printed text again at the corrected address of the entries whose text came from an earlier one';

    public function handle(): int
    {
        $entries = SourceEntry::with(['source', 'sourceVolume', 'citable'])->where('text_status', '!=', SourceEntry::TEXT_EDITED)
            ->whereNotNull('text_page')
            ->where(fn ($q) => $q->where('text_status', SourceEntry::TEXT_STALE)->orWhereColumn('text_page', '!=', 'page_start'))
            ->when($this->option('entry'), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->orderBy('id')->get();
        $stats = ['marked' => $entries->count(), 'unreadable source' => 0, 'not found' => 0, 'taken' => 0];

        foreach ($entries as $entry) {
            if (! Extractors::canRead($entry)) {
                $stats['unreadable source']++;

                continue;
            }
            $found = TextRefresh::find($entry);
            if (! $found) {
                $stats['not found']++;
                $this->warn("#{$entry->id} {$entry->cardLabel($entry->citable)}: not found at its address");

                continue;
            }
            $stats['taken']++;
            $this->line("#{$entry->id} {$entry->cardLabel($entry->citable)}: entry {$found['entry_number']}, p. {$found['page_start']}");
            if ($this->option('dry-run')) {
                continue;
            }
            $old = TextRefresh::apply($entry);
            AuditLog::create([
                'user_id' => null,
                'auditable_type' => SourceEntry::class,
                'auditable_id' => $entry->id,
                'action' => 'update',
                'old_values' => array_diff_key($old, ['id' => 0]),
                'new_values' => $entry->fresh()->only(TextRefresh::COLUMNS) + ['command' => 'sources:retext'],
            ]);
        }

        $this->table(['', 'count'], collect($stats)->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->option('dry-run') && $this->warn('Dry run: nothing is saved.');

        return self::SUCCESS;
    }
}
