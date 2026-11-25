<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Source;
use App\Models\SourceVolume;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Fills the printed-book record of sources and their volumes from a reviewed decision file (step 14.394, backlog item
 * 171): compiler, edition, place, publisher and year, which the citation page (/cite) is built from. What a whole book
 * shares goes on the source, what one volume's printing has on the volume. A volume is found by its source's code, its
 * number and, where the number is shared (Majlis ج ۲۴ in three parts), its facsimile_code; a row that finds none or
 * several is skipped and reported.
 *
 * Each field named in the file is set to its value, and null clears it (a volume's «مخطوط» that hid the source's year).
 * `metadata` is merged key by key: a journal's `periodical` flag and each issue's `articles` (first page, title,
 * author, «تحقيق» role), which SourceReference cites a journal's page by; other keys stay as they are.
 * A field already holding the value is left alone, so a run can be repeated. Without --apply it only reports.
 */
class FillSourceImprintsCommand extends Command
{
    /** The fields the file may set; any other key is refused. */
    public const SOURCE_FIELDS = ['compiler', 'edition_label', 'publication_place', 'publisher', 'publication_year', 'metadata'];

    public const VOLUME_FIELDS = ['edition_label', 'publication_place', 'publisher', 'publication_year', 'metadata'];

    protected $signature = 'fahares:fill-source-imprints
        {--file=database/data/source_imprints_step14_394.json : The decision file}
        {--apply : Write; without it the command only reports}';

    protected $description = 'Fill the printed-book record (compiler, edition, place, publisher, year) of sources and volumes';

    private array $counts = ['sources_changed' => 0, 'volumes_changed' => 0, 'fields_changed' => 0, 'unchanged' => 0, 'not_found' => 0];

    public function handle(): int
    {
        $path = $this->option('file');
        $path = str_starts_with($path, '/') ? $path : base_path($path);
        $data = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        if (! is_array($data['sources'] ?? null)) {
            $this->error("No decisions in {$path}");

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');

        foreach ($data['sources'] as $code => $decision) {
            $source = Source::where('code', $code)->first();
            if (! $source) {
                $this->counts['not_found']++;
                $this->warn("No source «{$code}»");

                continue;
            }
            $this->write($source, $decision['set'] ?? [], self::SOURCE_FIELDS, $code, $apply);
            foreach ($decision['volumes'] ?? [] as $row) {
                $query = SourceVolume::where('source_id', $source->id)->where('volume_number', $row['volume']);
                if (array_key_exists('facsimile_code', $row)) {
                    $query->where('facsimile_code', $row['facsimile_code']);
                }
                $volumes = $query->get();
                if ($volumes->count() !== 1) {
                    $this->counts['not_found']++;
                    $this->warn("{$code} ج {$row['volume']}" . (array_key_exists('facsimile_code', $row) ? " ({$row['facsimile_code']})" : '')
                        . ': ' . ($volumes->isEmpty() ? 'no volume' : $volumes->count() . ' volumes') . ', skipped');

                    continue;
                }
                $this->write($volumes->first(), $row['set'] ?? [], self::VOLUME_FIELDS, "{$code} ج {$row['volume']}", $apply);
            }
        }

        $this->info($apply ? 'Written.' : 'Dry run: nothing written.');
        foreach ($this->counts as $kind => $n) {
            $this->line(sprintf('  %-16s %5d', $kind, $n));
        }

        return self::SUCCESS;
    }

    private function write(Model $record, array $set, array $allowed, string $label, bool $apply): void
    {
        if ($unknown = array_diff(array_keys($set), $allowed)) {
            $this->warn("{$label}: unknown field " . implode(', ', $unknown) . ', skipped');
            $this->counts['not_found']++;

            return;
        }
        $old = [];
        $new = [];
        foreach ($set as $field => $value) {
            if ($field === 'metadata') {
                $merged = array_merge((array) ($record->metadata ?? []), (array) $value);
                foreach ((array) $value as $key => $v) {
                    if ($v === null) {
                        unset($merged[$key]);
                    }
                }
                if ($merged != (array) ($record->metadata ?? [])) {
                    $old['metadata'] = $record->metadata;
                    $new['metadata'] = $merged;
                }

                continue;
            }
            $value = is_string($value) && trim($value) !== '' ? trim($value) : null;
            if ($record->{$field} === $value) {
                continue;
            }
            $old[$field] = $record->{$field};
            $new[$field] = $value;
        }
        if (! $new) {
            $this->counts['unchanged']++;

            return;
        }
        $this->counts[$record instanceof Source ? 'sources_changed' : 'volumes_changed']++;
        $this->counts['fields_changed'] += count($new);
        foreach ($new as $field => $value) {
            $show = fn ($v) => is_array($v) ? json_encode(array_diff_key($v, ['articles' => 1]) + (isset($v['articles']) ? ['articles' => count($v['articles'])] : []), JSON_UNESCAPED_UNICODE) : ($v ?? '∅');
            $this->line("{$label}  {$field}: " . $show($old[$field] ?? null) . ' → ' . $show($value));
        }
        if ($apply) {
            DB::transaction(function () use ($record, $old, $new) {
                $record->forceFill($new)->save();
                AuditLog::create(['user_id' => null, 'auditable_type' => $record::class, 'auditable_id' => $record->id, 'action' => 'corpus_update',
                    'old_values' => $old, 'new_values' => $new + ['corpus_fix' => 'printed-book record (item 171, step 14.394)']]);
            });
        }
    }
}
