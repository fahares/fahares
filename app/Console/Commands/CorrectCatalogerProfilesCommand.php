<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Cataloger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Replaces the unsourced generated profile data of catalogers (catalogers_profiles_phase1…3.json)
 * with fact-checked values (backlog item 44).
 *
 * Each correction names the generated value it replaces ("from"). A field is written only while
 * it still holds that value, so anything an editor changed in the admin panel is left alone.
 */
class CorrectCatalogerProfilesCommand extends Command
{
    protected $signature = 'catalogers:correct-profiles
                            {--file=database/data/catalogers_profiles_corrections.json : Corrections file}
                            {--dry-run : Report without saving}';

    protected $description = 'Replace generated cataloger profile fields with fact-checked values, skipping fields edited by hand';

    private const COLUMNS = ['title_prefix', 'nickname', 'bio', 'birth_year_solar', 'death_year_solar', 'birth_year_hijri', 'death_year_hijri'];

    public function handle(): int
    {
        $path = base_path($this->option('file'));
        if (!File::exists($path)) {
            $this->error("Corrections file not found: {$path}");
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $entries = json_decode(File::get($path), true);

        $written = 0;
        $kept = 0;
        $missing = [];

        foreach ($entries as $entry) {
            $cataloger = Cataloger::where('slug', $entry['slug'])->first();
            if (!$cataloger) {
                $missing[] = $entry['slug'];
                continue;
            }

            $old = $cataloger->only(array_merge(self::COLUMNS, ['metadata']));
            $metadata = $cataloger->metadata ?? [];
            $changed = [];

            foreach ($entry['fields'] as $field => $change) {
                $isMeta = str_starts_with($field, 'metadata.');
                $key = $isMeta ? substr($field, 9) : $field;
                $current = $isMeta ? ($metadata[$key] ?? null) : $cataloger->{$key};

                if ($this->same($current, $change['to'])) {
                    continue;
                }
                if (!$this->same($current, $change['from'])) {
                    $kept++;
                    $this->line("  [=] {$cataloger->name}: {$field} was edited by hand; kept");
                    continue;
                }

                if ($isMeta) {
                    if ($change['to'] === null) {
                        unset($metadata[$key]);
                    } else {
                        $metadata[$key] = $change['to'];
                    }
                } else {
                    $cataloger->{$key} = $change['to'];
                }
                $changed[] = $field;
            }

            if (!empty($entry['sources']) && ($metadata['sources'] ?? null) !== $entry['sources']) {
                $metadata['sources'] = $entry['sources'];
                $changed[] = 'metadata.sources';
            }

            if (empty($changed)) {
                continue;
            }

            $cataloger->metadata = $metadata ?: null;
            $written++;
            $this->line("  [+] {$cataloger->name}: " . implode(', ', $changed));

            if ($dryRun) {
                continue;
            }

            $cataloger->save();
            AuditLog::create([
                'user_id' => null,
                'auditable_type' => Cataloger::class,
                'auditable_id' => $cataloger->id,
                'action' => 'profile_correction',
                'old_values' => $old,
                'new_values' => $cataloger->only(array_merge(self::COLUMNS, ['metadata'])),
                'ip_address' => null,
            ]);
        }

        $this->newLine();
        $this->info("Catalogers corrected: {$written}");
        $this->info("Fields kept because they were edited by hand: {$kept}");
        if ($missing) {
            $this->warn('Slugs not found: ' . implode(', ', $missing));
        }
        if ($dryRun) {
            $this->warn('[DRY RUN] No database changes were saved.');
        }

        return self::SUCCESS;
    }

    /** Loose equality: numbers stored as strings and arrays compare by content; empty means null. */
    private function same(mixed $a, mixed $b): bool
    {
        if ($a === '' || $a === []) {
            $a = null;
        }
        if ($b === '' || $b === []) {
            $b = null;
        }
        if (is_array($a) || is_array($b)) {
            return json_encode($a, JSON_UNESCAPED_UNICODE) === json_encode($b, JSON_UNESCAPED_UNICODE);
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (int) $a === (int) $b;
        }
        return $a === $b;
    }
}
