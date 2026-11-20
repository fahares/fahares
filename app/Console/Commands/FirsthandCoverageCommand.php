<?php

namespace App\Console\Commands;

use App\Models\Claim;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * How far the copies stand on their libraries' own catalogues (backlog item 147, step 14.249; plan
 * docs/plans/fankha-retirement.md): per library and in all, how many copies have a first-hand text, how many were
 * read against it, and for each field how many records fill it, how many a first-hand entry confirms, and how many
 * have a candidate claim. «complete» is the plan's finish criterion for a copy: read, and each of its filled core
 * fields confirmed or settled by an accepted claim. The report is kept in the cache (CACHE_KEY) for the pages.
 */
class FirsthandCoverageCommand extends Command
{
    public const CACHE_KEY = 'firsthand-coverage';

    /** The record's column each read field fills. */
    public const COLUMNS = [
        'shelfmark' => 'shelfmark', 'folios' => 'folios', 'lines' => 'lines', 'dimensions' => 'dimensions',
        'copy_date' => 'copy_date_raw', 'scribe_name' => 'scribe_name', 'script_names' => 'script_names',
    ];

    /** The fields the finish criterion asks of each copy (with its library and work, which the entry itself gives). */
    public const CORE = ['shelfmark', 'folios', 'copy_date', 'scribe_name'];

    protected $signature = 'fahares:firsthand-coverage
        {--libraries=20 : Show this many libraries, those with the most copies read first}
        {--json= : Also write the report to this JSON file}';

    protected $description = 'Measure how far copies stand on first-hand catalogue entries (item 147)';

    public function handle(): int
    {
        $m = Manuscript::class;
        $firstHand = [SourceEntry::ORIGIN_FANKHA_CITATION, SourceEntry::ORIGIN_MATCH, SourceEntry::ORIGIN_MANUAL];

        $entries = DB::table('source_entries as e')->join('sources as s', 's.id', '=', 'e.source_id')
            ->where('e.citable_type', $m)->whereIn('e.origin', $firstHand)->where('s.kind', Source::KIND_LIBRARY_CATALOGUE)
            ->where('e.status', '!=', SourceEntry::STATUS_REJECTED)
            ->groupBy('e.citable_id')
            ->selectRaw("e.citable_id as id, MAX(COALESCE(e.copy_text, '') <> '' OR COALESCE(e.entry_text, '') <> '') as has_text, MAX(e.fields_read_at IS NOT NULL) as was_read, GROUP_CONCAT(e.confirmed_fields) as cf");
        $claims = DB::table('claims')->where('subject_type', $m)->whereIn('status', [Claim::STATUS_CANDIDATE, Claim::STATUS_ACCEPTED])
            ->groupBy('subject_id')
            ->selectRaw("subject_id as id, GROUP_CONCAT(CASE WHEN status = 'candidate' THEN field END) as cand, GROUP_CONCAT(CASE WHEN status = 'accepted' THEN field END) as acc");

        $select = ['m.library_id', 'COUNT(*) as copies', 'SUM(COALESCE(f.has_text, 0)) as with_text', 'SUM(COALESCE(f.was_read, 0)) as was_read'];
        $complete = ['f.was_read = 1'];
        foreach (self::COLUMNS as $field => $column) {
            $filled = "m.{$column} IS NOT NULL AND m.{$column} <> ''";
            $conf = "f.cf LIKE '%\"{$field}\"%'";
            $acc = "FIND_IN_SET('{$field}', c.acc) > 0";
            $select[] = "SUM({$filled}) as {$field}_filled";
            $select[] = "SUM(COALESCE({$conf}, 0)) as {$field}_confirmed";
            $select[] = "SUM(COALESCE(FIND_IN_SET('{$field}', c.cand) > 0, 0)) as {$field}_claimed";
            if (in_array($field, self::CORE, true)) {
                $complete[] = "(NOT ({$filled}) OR COALESCE({$conf}, 0) OR COALESCE({$acc}, 0))";
            }
        }
        $select[] = 'SUM(COALESCE(' . implode(' AND ', $complete) . ', 0)) as complete';

        $rows = DB::table('manuscripts as m')
            ->leftJoinSub($entries, 'f', 'f.id', '=', 'm.id')
            ->leftJoinSub($claims, 'c', 'c.id', '=', 'm.id')
            ->groupBy('m.library_id')
            ->selectRaw(implode(', ', $select))
            ->get();

        $names = DB::table('libraries')->pluck('name', 'id');
        $cities = DB::table('libraries')->pluck('city', 'id');
        $sum = fn (string $key) => (int) $rows->sum($key);
        $total = ['copies' => $sum('copies'), 'with_text' => $sum('with_text'), 'was_read' => $sum('was_read'), 'complete' => $sum('complete'), 'fields' => []];
        foreach (array_keys(self::COLUMNS) as $field) {
            $total['fields'][$field] = ['filled' => $sum("{$field}_filled"), 'confirmed' => $sum("{$field}_confirmed"), 'claimed' => $sum("{$field}_claimed")];
        }
        $libraries = $rows->filter(fn ($r) => $r->was_read > 0 || $r->with_text > 0)->sortByDesc('was_read')->map(fn ($r) => [
            'library_id' => $r->library_id, 'name' => $names[$r->library_id] ?? '—', 'city' => $cities[$r->library_id] ?? null,
            'copies' => (int) $r->copies, 'with_text' => (int) $r->with_text, 'was_read' => (int) $r->was_read, 'complete' => (int) $r->complete,
        ])->values();

        $report = ['measured_at' => now()->toIso8601String(), 'total' => $total, 'libraries' => $libraries->all()];
        Cache::forever(self::CACHE_KEY, $report);
        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        $pct = fn ($n, $of) => $of ? round($n * 100 / $of, 1) . '%' : '—';
        $this->info("Copies: {$total['copies']}; with a first-hand text {$total['with_text']} ({$pct($total['with_text'], $total['copies'])}); read {$total['was_read']}; complete {$total['complete']} ({$pct($total['complete'], $total['copies'])})");
        $this->table(['field', 'filled', 'confirmed', '% of filled', 'claimed'], collect($total['fields'])->map(fn ($f, $k) => [$k, $f['filled'], $f['confirmed'], $pct($f['confirmed'], $f['filled']), $f['claimed']])->values());
        $this->table(['library', 'copies', 'with text', 'read', 'complete', '%'], $libraries->take((int) $this->option('libraries'))
            ->map(fn ($l) => [trim($l['name'] . '، ' . $l['city'], '، '), $l['copies'], $l['with_text'], $l['was_read'], $l['complete'], $pct($l['complete'], $l['copies'])]));

        return self::SUCCESS;
    }
}
