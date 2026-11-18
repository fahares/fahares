<?php

namespace App\Console\Commands;

use App\Models\ManuscriptNote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Writes the copies' typed notes to manuscript_notes (backlog item 87, step 14.254) from a file the corpus makes
 * (fankha_v2_copy_notes_export.py): gzipped JSON lines, one copy a line, {"id", "shelfmark", "notes": [{"type",
 * "text", "typed_by", "also"?, "by_author"?, "by_author_hedge"?, "hedge"?}]}. A copy's rows are replaced as a whole,
 * in the file's order. Left alone: a copy the site lacks, one whose shelfmark is another than the file's, one with a
 * note an editor typed, and one whose rows are the file's already. The copy's own columns and metadata are not
 * touched, so nothing is logged or reindexed. Safe to run again.
 */
class ImportCopyNotesCommand extends Command
{
    protected $signature = 'fahares:import-copy-notes
        {--file= : The gzipped JSON lines file}
        {--apply : Write; without it the command only counts}';

    protected $description = "Write the copies' typed notes to manuscript_notes";

    private const FIELDS = ['type', 'text', 'also', 'by_author', 'by_author_hedge', 'hedge', 'typed_by'];

    public function handle(): int
    {
        $in = @gzopen((string) $this->option('file'), 'rb');
        if (! $in) {
            $this->error('File not found: ' . $this->option('file'));

            return 1;
        }
        $apply = (bool) $this->option('apply');
        $counts = ['new' => 0, 'changed' => 0, 'same' => 0, 'kept: an editor typed a note' => 0, 'skipped: no such copy' => 0, 'skipped: another shelfmark' => 0];
        $notes = 0;
        $batch = [];
        $flush = function () use (&$batch, &$counts, &$notes, $apply) {
            $notes += $this->write($batch, $counts, $apply);
            $batch = [];
        };
        // the whole file is checked before the first write
        for ($n = 1; ($line = gzgets($in)) !== false; $n++) {
            $rec = trim($line) === '' ? null : json_decode($line, true);
            if (trim($line) !== '' && (! isset($rec['id']) || empty($rec['notes']))) {
                $this->error("Line {$n}: no copy id or no notes.");
                gzclose($in);

                return 1;
            }
            foreach ($rec['notes'] ?? [] as $note) {
                if (! isset(ManuscriptNote::TYPES[$note['type'] ?? '']) || ! in_array($note['typed_by'] ?? '', ManuscriptNote::TYPED_BY, true) || trim((string) ($note['text'] ?? '')) === '') {
                    $this->error("Line {$n}: a note without a known type, source or text.");
                    gzclose($in);

                    return 1;
                }
            }
        }
        gzrewind($in);
        while (($line = gzgets($in)) !== false) {
            if (trim($line) === '') {
                continue;
            }
            $rec = json_decode($line, true);
            $batch[(int) $rec['id']] = $rec;
            if (count($batch) >= 1000) {
                $flush();
            }
        }
        gzclose($in);
        $flush();

        foreach ($counts as $kind => $count) {
            $this->line(sprintf('  %-32s %7d', $kind, $count));
        }
        $this->info(($apply ? 'Wrote ' : 'Would write ') . $notes . ' notes of ' . ($counts['new'] + $counts['changed']) . ' copies.');

        return 0;
    }

    /** @return int the notes written (or that would be) for this batch of copies */
    private function write(array $batch, array &$counts, bool $apply): int
    {
        if (! $batch) {
            return 0;
        }
        $copies = DB::table('manuscripts')->whereIn('id', array_keys($batch))->pluck('shelfmark', 'id');
        $held = DB::table('manuscript_notes')->whereIn('manuscript_id', array_keys($batch))->orderBy('position')
            ->get(['manuscript_id', ...self::FIELDS])->groupBy('manuscript_id');
        $rows = [];
        $replace = [];
        $now = now();
        foreach ($batch as $id => $rec) {
            if (! $copies->has($id)) {
                $counts['skipped: no such copy']++;
                continue;
            }
            if (trim((string) $copies[$id]) !== trim((string) ($rec['shelfmark'] ?? ''))) {
                $counts['skipped: another shelfmark']++;
                continue;
            }
            $new = array_map(fn ($n) => [
                'type' => $n['type'], 'text' => trim($n['text']),
                'also' => empty($n['also']) ? null : json_encode(array_values($n['also'])),
                'by_author' => (int) ! empty($n['by_author']), 'by_author_hedge' => $n['by_author_hedge'] ?? null,
                'hedge' => $n['hedge'] ?? null, 'typed_by' => $n['typed_by'],
            ], array_values($rec['notes']));
            $old = ($held[$id] ?? collect())->map(fn ($r) => [
                'type' => $r->type, 'text' => $r->text, 'also' => $r->also === null ? null : json_encode(json_decode($r->also, true)),
                'by_author' => (int) $r->by_author, 'by_author_hedge' => $r->by_author_hedge, 'hedge' => $r->hedge, 'typed_by' => $r->typed_by,
            ])->all();
            if (in_array('editor', array_column($old, 'typed_by'), true)) {
                $counts['kept: an editor typed a note']++;
                continue;
            }
            if ($old === $new) {
                $counts['same']++;
                continue;
            }
            $counts[$old ? 'changed' : 'new']++;
            $replace[] = $id;
            foreach ($new as $i => $note) {
                $rows[] = ['manuscript_id' => $id, 'position' => $i + 1, 'created_at' => $now, 'updated_at' => $now] + $note;
            }
        }
        if ($apply && $replace) {
            DB::transaction(function () use ($replace, $rows) {
                DB::table('manuscript_notes')->whereIn('manuscript_id', $replace)->delete();
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('manuscript_notes')->insert($chunk);
                }
            });
        }

        return count($rows);
    }
}
