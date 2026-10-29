<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use App\Sources\SourceEntrySync;
use App\Suggestions\ProtectedColumns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes printed-page markers («<!-- page: 360 -->») that leaked into record fields where a value ran over a page
 * break in the printed catalog: names (step 10.2) and, with --fields, the text, physical and JSON fields of works and
 * manuscripts (backlog item 19). raw_text keeps its markers: it is the Fankha text as printed. A column with a
 * reviewed correction is left as is and reported. Safe to re-run.
 */
class CleanPageMarkersCommand extends Command
{
    protected $signature = 'fahares:clean-page-markers
                            {--fields : Also clean the text, physical and JSON fields of works and manuscripts}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Remove leaked page markers from person, scribe and author names and, with --fields, from work and manuscript fields';

    public const MARKER = '/\s*<!--\s*page:\s*\d+\s*-->\s*/u';

    /** A marker with the spaces around it, leaving line breaks of a multi-line text alone. */
    private const MARKER_INLINE = '/[ \t]*<!--\s*page:\s*\d+\s*-->[ \t]*/u';

    private const NAMES = [
        ['people', 'name', Person::class],
        ['manuscripts', 'scribe_name', Manuscript::class],
        ['works', 'author_name', Work::class],
    ];

    /** Text columns cleaned with --fields; JSON columns are walked string by string. */
    private const FIELDS = [
        'works' => [
            'model' => Work::class,
            'text' => ['description', 'incipit_text', 'explicit_text', 'print_info', 'dedication', 'related_work',
                'composition_date_raw', 'composition_place'],
            'json' => ['bibliography', 'commentaries_and_glosses'],
        ],
        'manuscripts' => [
            'model' => Manuscript::class,
            'text' => ['incipit_text', 'explicit_text', 'dimensions', 'binding', 'paper', 'copy_place', 'copy_date_raw',
                'commissioned_by'],
            'json' => ['metadata'],
        ],
    ];

    public static function strip(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return trim(preg_replace([self::MARKER, '/\s+/u'], [' ', ' '], $text));
    }

    /** A text without its markers: a marker becomes one space, a line left with nothing goes, and so does an empty value. */
    public static function stripText(?string $text): ?string
    {
        if ($text === null || ! str_contains($text, '<!--')) {
            return $text;
        }
        $lines = array_map('trim', explode("\n", preg_replace(self::MARKER_INLINE, ' ', $text)));
        $clean = trim(implode("\n", array_filter($lines, fn ($l) => $l !== '')));

        return $clean === '' ? null : preg_replace('/ {2,}/u', ' ', $clean);
    }

    /** A decoded JSON value with every string stripped. */
    public static function stripJson(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::stripText($value);
        }
        if (is_array($value)) {
            $out = array_map(fn ($v) => self::stripJson($v), $value);

            return array_is_list($value) ? array_values(array_filter($out, fn ($v) => $v !== null)) : $out;
        }

        return $value;
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        foreach (self::NAMES as [$table, $column, $model]) {
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

        if ($this->option('fields')) {
            foreach (self::FIELDS as $table => $spec) {
                $this->cleanFields($table, $spec, $dry);
            }
        }

        return self::SUCCESS;
    }

    private function cleanFields(string $table, array $spec, bool $dry): void
    {
        $columns = [...$spec['text'], ...$spec['json']];
        $guard = ProtectedColumns::for($spec['model']);
        $updates = [];
        $counts = array_fill_keys($columns, 0);
        $kept = [];

        DB::table($table)->where(fn ($q) => array_map(fn ($c) => $q->orWhere($c, 'like', '%<!--%'), $columns))
            ->select(['id', ...$columns])->orderBy('id')
            ->chunk(2000, function ($rows) use ($table, $spec, $guard, &$updates, &$counts, &$kept) {
                foreach ($rows as $row) {
                    $payload = [];
                    foreach ($spec['text'] as $c) {
                        if ($row->$c !== null && str_contains($row->$c, '<!--')) {
                            $payload[$c] = self::stripText($row->$c);
                        }
                    }
                    foreach ($spec['json'] as $c) {
                        if ($row->$c !== null && str_contains($row->$c, '<!--')) {
                            $clean = self::stripJson(json_decode($row->$c, true));
                            $payload[$c] = $clean === null ? null : json_encode($clean, JSON_UNESCAPED_UNICODE);
                        }
                    }
                    $allowed = $guard->strip($row->id, $payload);
                    foreach (array_diff_key($payload, $allowed) as $c => $_) {
                        $kept[] = "{$table} #{$row->id}: {$c} has a reviewed correction, left as is";
                    }
                    foreach ($allowed as $c => $_) {
                        $counts[$c]++;
                    }
                    if ($allowed) {
                        $updates[$row->id] = $allowed;
                    }
                }
            });

        foreach (array_slice($updates, 0, 3, true) as $id => $payload) {
            foreach ($payload as $c => $v) {
                $this->line("{$table} #{$id} {$c}: «" . mb_substr(DB::table($table)->where('id', $id)->value($c), 0, 120) . '» → «' . mb_substr((string) $v, 0, 120) . '»');
            }
        }
        $this->table(["{$table} column", 'values'], collect($counts)->map(fn ($n, $c) => [$c, $n])->values()->all());
        $this->info(($dry ? 'Would update ' : 'Updated ') . count($updates) . " {$table}.");
        foreach ($kept as $line) {
            $this->warn($line);
        }
        if ($dry || ! $updates) {
            return;
        }

        $model = $spec['model'];
        foreach (array_chunk($updates, 500, true) as $chunk) {
            DB::transaction(function () use ($chunk, $table) {
                foreach ($chunk as $id => $payload) {
                    DB::table($table)->where('id', $id)->update($payload);
                }
            });
        }
        // the Fankha entries, bibliographies and cited catalogue pages follow their columns (step 13.4)
        $synced = array_keys(array_filter($updates, fn ($p) => array_intersect_key($p, array_flip($spec['json']))));
        SourceEntrySync::syncIds($model, $synced);
        foreach (array_chunk(array_keys($updates), 500) as $ids) {
            $model::whereIn('id', $ids)->get()->searchable();
        }
    }
}
