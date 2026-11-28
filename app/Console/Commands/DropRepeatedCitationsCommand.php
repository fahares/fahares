<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\SourceEntry;
use App\Sources\RepeatedCitations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes the repeated Fankha citations that merges of two records of one copy left (backlog item 178, step 14.455).
 * Until that step RecordMerger moved the removed record's citation entry to the kept copy and cleared its legacy_type,
 * so a copy whose own citation has the same text cites the same catalogue page twice; the repeat, which the catalogue
 * commands and SourceEntrySync no longer see, keeps an older volume or page.
 *
 * A repeat is an entry of origin fankha_citation without legacy_type whose copy has another such entry with the same
 * text (trimmed). The entry kept is the one with legacy_type fankha_citation, then fankha_citation_more, then the
 * oldest. A repeat goes when it holds no data the kept entry lacks (RepeatedCitations::adds) and nothing links to it.
 * One at another place (an older volume or page) goes only when the kept entry has a legacy_type, which keeps it up to
 * date; the report lists it apart.
 *
 * Without --apply it only reports. Each removal writes the whole row to an AuditLog «delete» of the entry (and to the
 * backup file), from which RecordMerger puts it back if the merge is undone. Running it again finds nothing left.
 */
class DropRepeatedCitationsCommand extends Command
{
    protected $signature = 'sources:drop-repeated-citations
        {--apply : Remove the repeats; without it the command only reports}
        {--backup= : A .jsonl file for the rows removed (default storage/app/private/backups/)}';

    protected $description = 'Remove the repeated Fankha citation entries that merges of one copy\'s two records left';

    public const FIX = 'step 14.455 drop-repeated-citations';

    /** The entry kept among those of one text: the one the catalogue commands keep up to date first. */
    private const RANK = ['fankha_citation' => 0, 'fankha_citation_more' => 1];

    /** The row of an entry this command removed, from its audit log; null when it removed none of that id. */
    public static function droppedRow(int $id): ?array
    {
        $log = AuditLog::where('auditable_type', SourceEntry::class)->where('auditable_id', $id)->where('action', 'delete')
            ->where('new_values->corpus_fix', self::FIX)->latest('id')->first();

        return $log ? (array) $log->old_values : null;
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $copies = DB::table('source_entries')->where('citable_type', Manuscript::class)->where('origin', SourceEntry::ORIGIN_FANKHA_CITATION)
            ->whereNull('legacy_type')->whereNotNull('citation_text')->distinct()->orderBy('citable_id')->pluck('citable_id');
        $linked = collect(['claims', 'work_people', 'person_periods'])
            ->flatMap(fn ($table) => DB::table($table)->whereNotNull('source_entry_id')->distinct()->pluck('source_entry_id'))->flip();

        $drop = [];
        $kept = [];
        foreach ($copies->chunk(1000) as $chunk) {
            $groups = DB::table('source_entries')->where('citable_type', Manuscript::class)->whereIn('citable_id', $chunk->all())
                ->where('origin', SourceEntry::ORIGIN_FANKHA_CITATION)->whereNotNull('citation_text')->get()
                ->groupBy(fn ($row) => $row->citable_id . '|' . RepeatedCitations::key($row->citation_text));
            foreach ($groups as $rows) {
                if ($rows->count() < 2 || RepeatedCitations::key($rows->first()->citation_text) === '') {
                    continue;
                }
                $rows = $rows->sortBy(fn ($row) => [self::RANK[$row->legacy_type] ?? 2, $row->id])->values();
                $keeper = $rows->first();
                foreach ($rows->slice(1)->whereNull('legacy_type') as $row) {
                    $place = RepeatedCitations::otherPlace($row, $keeper);
                    $reason = match (true) {
                        $linked->has($row->id) => 'linked',
                        ($adds = RepeatedCitations::adds($row, $keeper)) !== null => "holds {$adds}",
                        $place && $keeper->legacy_type === null => 'other place, kept entry not kept up to date',
                        default => null,
                    };
                    if ($reason) {
                        $kept[] = [$row->id, $row->citable_id, $row->citation_text, $keeper->id, $reason];
                    } else {
                        $drop[] = ['row' => $row, 'keeper' => $keeper, 'place' => $place];
                    }
                }
            }
        }

        $other = array_values(array_filter($drop, fn ($d) => $d['place']));
        $this->line(sprintf('  %-26s %5d', 'repeats at the same place', count($drop) - count($other)));
        $this->line(sprintf('  %-26s %5d', 'repeats at an older place', count($other)));
        $this->line(sprintf('  %-26s %5d', 'kept', count($kept)));
        if ($other) {
            $this->table(['entry', 'copy', 'citation', 'its place', 'kept entry', 'kept place'], array_map(fn ($d) => [
                $d['row']->id, $d['row']->citable_id, $d['row']->citation_text,
                $this->place($d['row'], $d['place']), $d['keeper']->id, $this->place($d['keeper'], $d['place']),
            ], $other));
        }
        if ($kept) {
            $this->table(['entry', 'copy', 'citation', 'kept entry', 'why it stays'], $kept);
        }
        if (! $apply || ! $drop) {
            $this->info($apply ? 'Nothing to remove.' : 'Dry run: nothing changed.');

            return self::SUCCESS;
        }

        $path = $this->option('backup') ?: storage_path('app/private/backups/drop-repeated-citations-' . now()->format('Ymd_His') . '.jsonl');
        is_dir(dirname($path)) || mkdir(dirname($path), 0775, true);
        $out = fopen($path, 'ab');
        $removed = 0;
        foreach ($drop as $d) {
            $row = (array) $d['row'];
            fwrite($out, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n");
            fflush($out);
            DB::transaction(function () use ($row, $d, &$removed) {
                if (! DB::table('source_entries')->where('id', $row['id'])->whereNull('legacy_type')->delete()) {
                    return;
                }
                AuditLog::create(['user_id' => null, 'auditable_type' => SourceEntry::class, 'auditable_id' => $row['id'], 'action' => 'delete',
                    'old_values' => $row,
                    'new_values' => ['corpus_fix' => self::FIX, 'manuscript_id' => (int) $row['citable_id'], 'kept_entry_id' => (int) $d['keeper']->id,
                        'other_place' => $d['place']]]);
                $removed++;
            });
        }
        fclose($out);
        $this->info("Removed {$removed} repeated entries. Backup: {$path}");

        return self::SUCCESS;
    }

    private function place(object $row, array $fields): string
    {
        return implode(' ', array_map(fn ($f) => "{$f}=" . ($row->{$f} ?? '∅'), $fields));
    }
}
