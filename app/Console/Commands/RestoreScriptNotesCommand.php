<?php

namespace App\Console\Commands;

use App\Models\Manuscript;
use App\Support\ScriptNameCleaner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Follow-up of fahares:clean-script-names (backlog item 50). Two kinds of note in the old script names
 * had no place to go once those records were gone, so they are read again from the «خط:» line of the
 * manuscript's raw text:
 *  - a copy date written into the script value («تحریری، بیکاتی 1241ق»، «مختلف کاتب = مؤلف: تا 1300ق»)
 *    fills an empty copy date;
 *  - «کاتب = مؤلف (گویا/احتمالاً)» sets the autograph flag of a copy without a scribe, as the parser
 *    already does for every other hedged «کاتب = مؤلف».
 * Safe to run again.
 */
class RestoreScriptNotesCommand extends Command
{
    protected $signature = 'fahares:restore-script-notes
        {--dry-run : Report the changes without saving them}
        {--sync-search : Re-index the changed manuscripts in Meilisearch}';

    protected $description = 'Restore copy dates and hedged autograph flags that were written into script names';

    /** The «خط:» line up to the next field separator. */
    private const SCRIPT_LINE = '/خط:([^؛\n\[]*)/u';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $dates = [];
        $autographs = [];
        $candidates = DB::table('manuscripts')
            ->where(fn ($q) => $q->where('raw_text', 'like', '%بیکاتی%')->orWhere('raw_text', 'like', '%بيكاتي%')
                ->orWhere('raw_text', 'like', '%مؤلف (%')->orWhere('raw_text', 'like', '%مؤلف: تا%'))
            ->get(['id', 'raw_text', 'copy_date_raw', 'copy_date_hijri_year', 'is_autograph', 'scribe_id', 'scribe_name']);

        foreach ($candidates as $m) {
            if (! preg_match(self::SCRIPT_LINE, ScriptNameCleaner::normalize((string) $m->raw_text), $line)) {
                continue;
            }
            $value = $line[1];

            if (! $m->copy_date_raw && preg_match('/(?:بیکاتی|کاتب\s*=\s*مؤلف\s*:\s*تا)\s*(\d{3,4})\s*ق/u', $value, $d)) {
                $dates[$m->id] = (int) $d[1];
            }
            if (! $m->is_autograph && ! $m->scribe_id && ! $m->scribe_name
                && preg_match('/کاتب\s*=\s*مؤلف\s*\((?:گویا|احتمالاً|احتمالا)\)/u', $value)) {
                $autographs[] = $m->id;
            }
        }

        $this->line('Step 1: copy dates written into the script name');
        foreach ($dates as $id => $year) {
            $this->line("  manuscript {$id}: {$year}ق");
        }
        $this->line('Step 2: hedged «کاتب = مؤلف» without the autograph flag');
        foreach ($autographs as $id) {
            $this->line("  manuscript {$id}");
        }

        $ids = array_values(array_unique([...array_keys($dates), ...$autographs]));
        if (! $ids) {
            $this->info('Nothing to restore.');

            return self::SUCCESS;
        }
        if ($dry) {
            $this->info('Dry run: nothing saved.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($dates, $autographs) {
            foreach ($dates as $id => $year) {
                DB::table('manuscripts')->where('id', $id)->update(['copy_date_raw' => "{$year}ق", 'copy_date_hijri_year' => $year, 'updated_at' => now()]);
            }
            DB::table('manuscripts')->whereIn('id', $autographs)->update(['is_autograph' => true, 'updated_at' => now()]);
        });
        $this->info('Saved: ' . count($dates) . ' dates, ' . count($autographs) . ' autograph flags.');

        if ($this->option('sync-search')) {
            (new Manuscript)->makeAllSearchableUsing(Manuscript::whereIn('id', $ids))->get()->searchable();
            $this->info('Re-indexed ' . count($ids) . ' manuscripts.');
        }

        return self::SUCCESS;
    }
}
