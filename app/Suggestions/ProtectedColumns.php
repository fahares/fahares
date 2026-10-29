<?php

namespace App\Suggestions;

use App\Models\AuditLog;
use Illuminate\Console\Command;

/**
 * Columns changed by reviewed corrections (approved suggestions and reviewers' direct edits),
 * which the corpus sync commands must not overwrite. Corrections live only in this database
 * until they are exported to the corpus, so a sync from the corpus JSON would undo them.
 *
 * The source is the audit log: every applied correction writes a "verify" log with the columns
 * it changed. A correction that was later rolled back stays protected; the sync reports it,
 * which errs on the side of keeping data.
 */
class ProtectedColumns
{
    /** Keys a verify log carries beside the changed columns. */
    private const META_KEYS = ['suggestion_id', 'direct_edit', 'demoted', 'edited_reading', 'promoted', 'updated_at', 'added_contributor', 'removed_name_row', 'added_person_role', 'added_record_relation', 'corpus_fix'];

    /** @var array<int, array<string, true>> record id => protected columns */
    private array $columns = [];

    /** @var array<int, string[]> record id => columns a sync left alone */
    private array $skipped = [];

    public static function for(string $modelClass): self
    {
        $guard = new self;
        AuditLog::where('auditable_type', $modelClass)->where('action', 'verify')
            ->select(['auditable_id', 'new_values'])
            ->orderBy('id')
            ->each(function (AuditLog $log) use ($guard) {
                foreach (array_keys($log->new_values ?? []) as $column) {
                    if (! in_array($column, self::META_KEYS, true)) {
                        $guard->columns[$log->auditable_id][$column] = true;
                    }
                }
            });

        return $guard;
    }

    /** The update without the protected columns of that record; the dropped ones are remembered for the report. */
    public function strip(int $id, array $payload): array
    {
        $protected = array_intersect_key($payload, $this->columns[$id] ?? []);
        if ($protected) {
            $this->skipped[$id] = array_merge($this->skipped[$id] ?? [], array_keys($protected));
        }

        return array_diff_key($payload, $protected);
    }

    public function isEmpty(): bool
    {
        return $this->columns === [];
    }

    /** @return array<int, string[]> */
    public function skipped(): array
    {
        return array_map(fn ($cols) => array_values(array_unique($cols)), $this->skipped);
    }

    /** Lines for a command's output: one per record whose corrected columns were kept. */
    public function report(string $label): array
    {
        $lines = [];
        foreach ($this->skipped() as $id => $columns) {
            $lines[] = "{$label} #{$id}: " . implode(', ', $columns);
        }

        return $lines;
    }

    /** Tells the operator which corrected columns the sync kept, so they can be checked against the corpus. */
    public function printTo(Command $command, string $label): void
    {
        $lines = $this->report($label);
        if (! $lines) {
            return;
        }
        $command->warn(count($lines) . " record(s) have reviewed corrections; these columns were kept instead of being overwritten from the corpus:");
        foreach (array_slice($lines, 0, 50) as $line) {
            $command->line('  ' . $line);
        }
        if (count($lines) > 50) {
            $command->line('  … and ' . (count($lines) - 50) . ' more');
        }
    }
}
