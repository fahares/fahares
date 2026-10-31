<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Sources\SourceEntrySync;
use App\Suggestions\ProtectedColumns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Rebuilds copies whose content on the site came from a neighbouring copy (item 87, step 13.70): the record's
 * identity (work, sequence, city, library, shelfmark, pages) is right, but its raw text, date, scribe, size and
 * citation are another copy's, or whose shelfmark was misread (step 13.87). The decision file, written by the corpus script `fankha_v2_rebuild_export.py`,
 * gives every content column from parser v2 with the value the site had. A copy is rebuilt only when its shelfmark
 * is unchanged, every listed column still holds that value and no reviewed correction protects any of them; then
 * what was made from the old content follows: its Fankha entry and cited page, the legacy catalogue reference,
 * its catalogue volume and cataloger (cleared for catalogs:link-volumes to relink) and a scribe link that named
 * the other copy's scribe. Run fahares:rebuild-scripts afterwards for the scripts. Without --apply it only reports.
 */
class RebuildCopiesCommand extends Command
{
    protected $signature = 'fahares:rebuild-copies
                            {--file= : Decision file (database/data/fankha_rebuild_copies_20261030.json)}
                            {--apply : Write the changes; without it the command only reports what it would do}';

    protected $description = 'Rebuild the content of copies that carry a neighbouring copy\'s text, from parser v2';

    private const COLUMNS = ['raw_text', 'scribe_name', 'has_other_scribes', 'is_bika', 'is_bita', 'is_autograph',
        'copy_date_raw', 'copy_date_hijri_year', 'copy_place', 'commissioned_by', 'folios', 'lines', 'dimensions',
        'paper', 'binding', 'incipit_text', 'explicit_text', 'is_corrected', 'has_marginal_notes', 'is_ruled',
        'has_catchwords', 'is_facsimile', 'is_collated', 'is_illuminated', 'is_illustrated', 'has_author_marginalia',
        'metadata', 'shelfmark', 'sequence_number'];

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path('data/fankha_rebuild_copies_20261030.json');
        $plan = File::exists($path) ? json_decode(File::get($path), true) : null;
        if (! $plan || ! isset($plan['records'])) {
            $this->error("Decision file missing or invalid: {$path}");
            return 1;
        }
        $apply = (bool) $this->option('apply');
        $guard = ProtectedColumns::for(Manuscript::class);
        $rows = DB::table('manuscripts')->whereIn('id', array_column($plan['records'], 'id'))
            ->get(['id', 'shelfmark_key', 'catalog_volume_id', 'cataloger_id', 'scribe_id', 'script_names', 'script_style', ...self::COLUMNS])->keyBy('id');
        $counts = [];
        $problems = [];
        $writes = [];

        foreach ($plan['records'] as $rec) {
            $outcome = $this->check($rec, $rows[$rec['id']] ?? null, $guard);
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            if ($outcome === 'write') {
                $writes[] = $rec;
            } elseif ($outcome !== 'already') {
                $problems[] = "#{$rec['id']} ({$rec['v2']}): {$outcome}";
            }
        }

        $this->table(['outcome', 'copies'], collect($counts)->map(fn ($n, $k) => [$k, $n])->values()->all());
        foreach ($problems as $line) {
            $this->warn($line);
        }
        $this->info(($apply ? 'Rebuilding ' : 'Would rebuild ') . count($writes) . ' copies.');
        if (! $apply || ! $writes) {
            return 0;
        }

        $note = basename($path);
        DB::transaction(function () use ($writes, $rows, $note) {
            foreach ($writes as $rec) {
                $row = $rows[$rec['id']];
                $new = [];
                $old = [];
                foreach ($rec['changes'] as $field => $change) {
                    $new[$field] = $field === 'metadata' ? json_encode($change['to'], JSON_UNESCAPED_UNICODE) : $change['to'];
                    $old[$field] = $row->$field;
                }
                $citation = $rec['changes']['metadata']['to']['catalog_citation'] ?? null;
                $citationChanged = isset($rec['changes']['metadata'])
                    && ($rec['changes']['metadata']['from']['catalog_citation'] ?? null) !== $citation;
                $extra = [];
                if ($citationChanged) {
                    // the catalogue volume came from the other copy's citation: catalogs:link-volumes relinks it
                    $extra = ['catalog_volume_id' => null, 'cataloger_id' => null];
                    $parsed = $citation ? ImportFankhaCatalogReferencesCommand::parse($citation) : ['volume' => null, 'page' => null];
                    DB::table('manuscript_catalog_references')->where('manuscript_id', $rec['id'])
                        ->where('source', 'fankha')->where('status', 'unverified')
                        ->update(['raw_text' => $citation, 'volume_number' => $parsed['volume'], 'page_start' => $parsed['page'], 'updated_at' => now()]);
                }
                // fahares:rebuild-scripts leaves a copy with no «خط:» line alone, so its scripts would stay the other copy's
                if (isset($new['raw_text']) && ! preg_match(RebuildScriptsCommand::LINE, (string) $new['raw_text'])) {
                    $extra += ['script_names' => null, 'script_style' => null];
                    DB::table('manuscript_script')->where('manuscript_id', $rec['id'])->delete();
                }
                if (array_key_exists('scribe_name', $new)) {
                    $extra['scribe_id'] = null;
                    DB::table('manuscript_scribe')->where('manuscript_id', $rec['id'])->delete();
                }
                // a misread shelfmark (the printed page has another digit) moves its sort key with it
                if (array_key_exists('shelfmark', $new)) {
                    $extra['shelfmark_key'] = $new['shelfmark'] === null ? null : mb_substr($new['shelfmark'], 0, 100);
                }
                DB::table('manuscripts')->where('id', $rec['id'])->update($new + $extra + ['updated_at' => now()]);
                AuditLog::create([
                    'user_id' => null,
                    'auditable_type' => Manuscript::class,
                    'auditable_id' => $rec['id'],
                    'action' => 'corpus_update',
                    'old_values' => $old + array_intersect_key((array) $row, $extra),
                    'new_values' => $new + $extra + ['corpus_fix' => $note, 'category' => 'rebuild', 'v2' => $rec['v2']],
                ]);
            }
        });
        $ids = array_column($writes, 'id');
        SourceEntrySync::syncIds(Manuscript::class, $ids);
        foreach (array_chunk($ids, 100) as $chunk) {
            Manuscript::whereIn('id', $chunk)->get()->searchable();
            gc_collect_cycles();
        }
        $this->info('Done. Now run catalogs:link-volumes and fahares:rebuild-scripts.');

        return 0;
    }

    private function check(array $rec, ?object $row, ProtectedColumns $guard): string
    {
        if (! $row) {
            return 'copy gone (merged or deleted)';
        }
        // a shelfmark this file corrects is the record's own on a second run
        if ($row->shelfmark !== $rec['shelfmark'] && $row->shelfmark !== ($rec['changes']['shelfmark']['to'] ?? null)) {
            return 'shelfmark differs';
        }
        $already = true;
        foreach ($rec['changes'] as $field => $change) {
            if (! in_array($field, self::COLUMNS, true)) {
                return "field {$field} not allowed";
            }
            $current = $field === 'metadata' ? json_decode($row->metadata ?? 'null', true) : $row->$field;
            if ($this->same($current, $change['to'])) {
                continue;
            }
            $already = false;
            if (! $this->same($current, $change['from'])) {
                return "{$field} changed on the site since the export";
            }
        }
        if ($already) {
            return 'already';
        }
        $fields = array_fill_keys(array_keys($rec['changes']), true);
        if (array_diff_key($fields, $guard->strip($rec['id'], $fields))) {
            return 'protected by a reviewed correction';
        }

        return 'write';
    }

    private function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return $a == $b;
        }
        if ($a === null || $b === null) {
            return $a === $b || ((string) $a === '' && (string) $b === '');
        }

        return (string) $a === (string) $b;
    }
}
