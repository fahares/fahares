<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes printed-page markers («<!-- page: 360 -->») that leaked into person names and into the
 * scribe and author names of records, where a name ran over a page break in the printed catalog.
 * Safe to re-run.
 */
class CleanPageMarkersCommand extends Command
{
    protected $signature = 'fahares:clean-page-markers {--dry-run : Report what would change without writing}';

    protected $description = 'Remove leaked page markers from person, scribe and author names';

    public const MARKER = '/\s*<!--\s*page:\s*\d+\s*-->\s*/u';

    public static function strip(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return trim(preg_replace([self::MARKER, '/\s+/u'], [' ', ' '], $text));
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $targets = [
            ['people', 'name', Person::class],
            ['manuscripts', 'scribe_name', Manuscript::class],
            ['works', 'author_name', Work::class],
        ];

        foreach ($targets as [$table, $column, $model]) {
            $rows = DB::table($table)->where($column, 'like', '%<!--%')->get(['id', $column]);
            foreach ($rows->take(3) as $row) {
                $this->line("{$table} #{$row->id}: «{$row->{$column}}» → «" . self::strip($row->{$column}) . '»');
            }
            $this->info("{$table}.{$column}: {$rows->count()} values");

            if ($dry || $rows->isEmpty()) {
                continue;
            }

            DB::transaction(function () use ($rows, $table, $column) {
                foreach ($rows as $row) {
                    $clean = self::strip($row->{$column});
                    $values = [$column => $clean];
                    if ($table === 'people') {
                        $values['normalized_name'] = mb_substr(TextNormalizer::normalize($clean), 0, 600);
                    }
                    DB::table($table)->where('id', $row->id)->update($values);
                }
            });
            $model::whereIn('id', $rows->pluck('id'))->searchable();
        }

        return self::SUCCESS;
    }
}
