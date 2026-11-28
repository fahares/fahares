<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\ManuscriptNote;
use App\Models\SourceEntry;
use App\Models\Work;
use App\Suggestions\ProtectedColumns;
use App\Support\DerivedData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Patches words corrected in a catalogue's text into the text fields the site made from it (step 14.449: Fankha's
 * OCR words put back as printed, backlog item 124). The decision file, written by the corpus side, gives for each
 * field of a work, copy, copy note or entry the stretches to replace: each stretch occurs once in the value the site
 * had. A stretch is replaced only where it still occurs exactly once; a field where the new stretch already stands and
 * the old does not is «already»; anything else is a conflict and stays. A column a reviewed correction protects, and
 * an entry text written by hand, are left. Each changed record gets an «update» in the audit log; the copies and works
 * then have their derived data and search documents rebuilt. Without --apply it only reports.
 */
class PatchTextWordsCommand extends Command
{
    protected $signature = 'fahares:patch-text-words
                            {--file= : The decision file (database/data/fankha_ocr_patches_step14_449.json)}
                            {--apply : Write the changes; without it the command only reports}';

    protected $description = 'Patch corrected words of a catalogue text into the site\'s text fields where they are unedited';

    /** The tables a patch may touch, and their text columns. */
    private const TABLES = [
        'work' => ['table' => 'works', 'model' => Work::class, 'columns' => ['description', 'incipit_text', 'explicit_text', 'print_info', 'dedication', 'composition_place', 'composition_date_raw', 'raw_text']],
        'copy' => ['table' => 'manuscripts', 'model' => Manuscript::class, 'columns' => ['incipit_text', 'explicit_text', 'binding', 'paper', 'copy_place', 'copy_date_raw', 'copy_date_note', 'commissioned_by', 'raw_text']],
        'note' => ['table' => 'manuscript_notes', 'model' => ManuscriptNote::class, 'columns' => ['text']],
        'entry' => ['table' => 'source_entries', 'model' => SourceEntry::class, 'columns' => ['entry_text', 'copy_text']],
    ];

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path('data/fankha_ocr_patches_step14_449.json');
        $file = File::exists($path) ? json_decode(File::get($path), true) : null;
        if (! $file || ! isset($file['patches'])) {
            $this->error("Decision file missing or invalid: {$path}");

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $step = $file['step'] ?? null;
        $guards = ['work' => ProtectedColumns::for(Work::class), 'copy' => ProtectedColumns::for(Manuscript::class)];
        $counts = array_fill_keys(['patched', 'already', 'conflict', 'protected', 'edited entry', 'missing', 'refused'], 0);
        $problems = [];
        $updates = [];

        foreach ($file['patches'] as $item) {
            $kind = $item['table'] ?? '';
            $column = $item['column'] ?? '';
            if (! isset(self::TABLES[$kind]) || ! in_array($column, self::TABLES[$kind]['columns'], true)) {
                $counts['refused']++;
                $problems[] = "{$kind} #{$item['id']}: {$column} is not a text column this command writes";

                continue;
            }
            // a column that has left the table (works.raw_text and manuscripts.raw_text, phase 5 of item 170) is refused
            if (! \Illuminate\Support\Facades\Schema::hasColumn(self::TABLES[$kind]['table'], $column)) {
                $counts['refused']++;

                continue;
            }
            $row = DB::table(self::TABLES[$kind]['table'])->find($item['id']);
            if (! $row) {
                $counts['missing']++;

                continue;
            }
            if ($kind === 'entry' && $row->text_status === SourceEntry::TEXT_EDITED) {
                $counts['edited entry']++;

                continue;
            }
            if ($this->isProtected($kind, $row, $column, $guards)) {
                $counts['protected']++;
                $problems[] = "{$kind} #{$row->id}: {$column} has a reviewed correction, left as is";

                continue;
            }
            [$value, $outcome] = $this->resolve($row->$column ?? '', $item['patches']);
            $counts[$outcome]++;
            if ($outcome === 'conflict') {
                $problems[] = "{$kind} #{$row->id}: {$column} changed on the site; the patch can't be placed";
            } elseif ($outcome === 'patched') {
                $updates["{$kind}:{$row->id}"]['row'] = $row;
                $updates["{$kind}:{$row->id}"]['kind'] = $kind;
                $updates["{$kind}:{$row->id}"]['old'][$column] = $row->$column;
                $updates["{$kind}:{$row->id}"]['new'][$column] = $value;
            }
        }

        if ($apply && $updates) {
            $copies = [];
            $works = [];
            DB::transaction(function () use ($updates, $step, &$copies, &$works) {
                foreach ($updates as $u) {
                    $def = self::TABLES[$u['kind']];
                    DB::table($def['table'])->where('id', $u['row']->id)->update($u['new'] + ['updated_at' => now()]);
                    AuditLog::create([
                        'user_id' => null,
                        'auditable_type' => $def['model'],
                        'auditable_id' => $u['row']->id,
                        'action' => 'update',
                        'old_values' => $u['old'],
                        'new_values' => $u['new'] + ['command' => 'fahares:patch-text-words', 'step' => $step],
                    ]);
                    match ($u['kind']) {
                        'work' => $works[] = $u['row']->id,
                        'copy' => $copies[] = $u['row']->id,
                        'note' => $copies[] = $u['row']->manuscript_id,
                        'entry' => $u['row']->legacy_type === 'work' ? $works[] = $u['row']->legacy_id
                            : ($u['row']->legacy_type === 'manuscript' ? $copies[] = $u['row']->legacy_id : null),
                    };
                }
            });
            // the dates, extents and excerpts read from these texts, and the search documents (DB::table runs no hook)
            $copies && DerivedData::syncCopies(array_values(array_unique($copies)), $this);
            $works && DerivedData::syncWorks(array_values(array_unique($works)), $this);
        }

        $this->table(['outcome', 'fields'], collect($counts)->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->info(($apply ? 'Updated ' : 'Would update ') . count($updates) . ' records.');
        foreach (array_slice($problems, 0, 200) as $line) {
            $this->warn($line);
        }
        if (count($problems) > 200) {
            $this->warn('… and ' . (count($problems) - 200) . ' more.');
        }

        return self::SUCCESS;
    }

    /** A copy note is protected when reviewers changed the copy's notes; a work or copy column by its own corrections. */
    private function isProtected(string $kind, object $row, string $column, array $guards): bool
    {
        return match ($kind) {
            'work', 'copy' => $guards[$kind]->strip($row->id, [$column => true]) === [],
            'note' => $guards['copy']->strip($row->manuscript_id, ['notes' => true]) === [],
            default => false,
        };
    }

    /**
     * The patched value and how it was reached: «patched» when every old stretch occurs once, «already» when no old
     * stretch is left and every new one is there, otherwise «conflict».
     *
     * @return array{0: ?string, 1: string}
     */
    private function resolve(string $value, array $patches): array
    {
        // a short new stretch («المعلول») may stand elsewhere in the field too, so it needs only to be there
        if (collect($patches)->every(fn ($p) => ! str_contains($value, $p['old']) && str_contains($value, $p['new']))) {
            return [null, 'already'];
        }
        foreach ($patches as $p) {
            if (substr_count($value, $p['old']) !== 1) {
                return [null, 'conflict'];
            }
            $value = str_replace($p['old'], $p['new'], $value);
        }

        return [$value, 'patched'];
    }
}
