<?php

namespace App\Console\Commands;

use App\Models\SourceEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the second ties of a record to one printed entry (step 14.438). sources:link-items made a row for each place
 * of a citation, so «الذریعة 290/11 و 292», two pages of one entry, tied the work to it twice and its page showed the
 * entry twice; two citations of one entry did the same. Of the rows that tie a record to an entry in one role, a
 * reviewed one, or else the first, is kept; only unreviewed ones go. Rows that cite the ones removed are moved to the
 * row kept.
 */
class DedupeItemTiesCommand extends Command
{
    /** The tables whose rows cite a source entry (their source_entry_id). */
    private const CITING = ['work_people', 'person_periods', 'claims'];

    protected $signature = 'sources:dedupe-item-ties {--dry-run : Count without removing}';

    protected $description = 'Remove the second ties of a record to one printed entry';

    public function handle(): int
    {
        $groups = DB::table('source_entries')->whereNotNull('source_item_id')
            ->groupBy('citable_type', 'citable_id', 'source_item_id', 'role')->havingRaw('COUNT(*) > 1')
            ->get(['citable_type', 'citable_id', 'source_item_id', 'role']);

        $moves = [];
        foreach ($groups as $g) {
            $rows = SourceEntry::where((array) $g)->orderBy('id')->get(['id', 'status']);
            $keep = $rows->first(fn ($r) => $r->status !== SourceEntry::STATUS_UNVERIFIED) ?? $rows->first();
            foreach ($rows as $r) {
                if ($r->id !== $keep->id && $r->status === SourceEntry::STATUS_UNVERIFIED) {
                    $moves[$r->id] = $keep->id;
                }
            }
        }
        $citing = array_values(array_filter(self::CITING, fn ($t) => Schema::hasColumn($t, 'source_entry_id')));
        $cited = collect($citing)->sum(fn ($t) => DB::table($t)->whereIn('source_entry_id', array_keys($moves))->count());
        $this->line(sprintf('%d records tied twice to one entry: %d rows to remove, %d rows citing them to move.',
            $groups->count(), count($moves), $cited));
        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing is removed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($moves, $citing) {
            foreach ($moves as $from => $to) {
                foreach ($citing as $table) {
                    DB::table($table)->where('source_entry_id', $from)->update(['source_entry_id' => $to]);
                }
            }
            foreach (array_chunk(array_keys($moves), 500) as $ids) {
                SourceEntry::whereIn('id', $ids)->delete();
            }
        });
        $this->info(sprintf('Removed %d rows.', count($moves)));

        return self::SUCCESS;
    }
}
