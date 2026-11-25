<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Takes catalog_citation out of the copies' metadata (step 14.413, backlog item 170): Fankha's citation of a copy's
 * catalogue place is its citation entry's citation_text, which every reader and writer uses since that step. A key
 * goes only where the entry holds the same text, or the key is empty; any other copy is listed and left, for a look.
 *
 * By ranges of 5,000 copies, each its own short statement, so no long transaction holds the table. Running it again
 * finds nothing left. --backup writes each key that goes (id and value, JSON lines), flushed before its range is
 * changed. updated_at is left: the copy's own data did not change.
 */
class DropCitationKeyCommand extends Command
{
    protected $signature = 'sources:drop-citation-key
        {--dry-run : Count the keys that would go and the copies that would be left}
        {--backup= : A .jsonl file to write the keys into before they go}';

    protected $description = "Remove catalog_citation from the copies' metadata where the citation entry holds it";

    private const RANGE = 5000;

    /** A key that may go: empty, or the text the copy's citation entry holds. */
    private const SAME = "(JSON_TYPE(JSON_EXTRACT(m.metadata, '$.catalog_citation')) = 'NULL'
        OR TRIM(JSON_UNQUOTE(JSON_EXTRACT(m.metadata, '$.catalog_citation'))) = ''
        OR TRIM(JSON_UNQUOTE(JSON_EXTRACT(m.metadata, '$.catalog_citation'))) = TRIM(ce.citation_text))";

    public function handle(): int
    {
        DB::disableQueryLog();
        $dry = (bool) $this->option('dry-run');
        $backup = $this->option('backup');
        if (! $dry && $backup && file_exists($backup)) {
            $this->error("The backup file exists already: {$backup}");

            return self::FAILURE;
        }
        $out = ! $dry && $backup ? fopen($backup, 'wb') : null;
        $max = (int) DB::table('manuscripts')->max('id');
        $removed = 0;
        $left = [];
        for ($from = 1; $from <= $max; $from += self::RANGE) {
            $to = $from + self::RANGE - 1;
            $rows = DB::table('manuscripts as m')
                ->leftJoin('source_entries as ce', fn ($j) => $j->on('ce.legacy_id', '=', 'm.id')->where('ce.legacy_type', 'fankha_citation')->where('ce.legacy_index', 0))
                ->whereBetween('m.id', [$from, $to])->whereRaw("JSON_CONTAINS_PATH(m.metadata, 'one', '$.catalog_citation')")
                ->selectRaw("m.id, JSON_UNQUOTE(JSON_EXTRACT(m.metadata, '$.catalog_citation')) as k, JSON_TYPE(JSON_EXTRACT(m.metadata, '$.catalog_citation')) as t, ce.citation_text, " . self::SAME . ' as same')
                ->get();
            $ids = [];
            foreach ($rows as $row) {
                if ((int) $row->same === 1) {
                    $ids[] = $row->id;
                    $out && fwrite($out, json_encode(['id' => $row->id, 'catalog_citation' => $row->t === 'NULL' ? null : $row->k], JSON_UNESCAPED_UNICODE) . "\n");
                } else {
                    $left[] = [$row->id, $row->k, $row->citation_text];
                }
            }
            if ($ids && ! $dry) {
                $out && fflush($out);
                // NULLIF: a copy whose metadata held only the citation keeps none
                DB::table('manuscripts')->whereIn('id', $ids)
                    ->update(['metadata' => DB::raw("NULLIF(JSON_REMOVE(metadata, '$.catalog_citation'), '{}')")]);
            }
            $removed += count($ids);
        }
        $out && fclose($out);

        $this->info(($dry ? 'Would remove ' : 'Removed ') . number_format($removed) . ' keys.');
        if ($left) {
            $this->warn(count($left) . ' copies keep the key: their citation entry holds another text.');
            $this->table(['copy', 'metadata', 'entry'], array_slice($left, 0, 50));
        }

        return self::SUCCESS;
    }
}
