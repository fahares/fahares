<?php

namespace App\Suggestions;

use App\Helpers\TextNormalizer;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use Illuminate\Database\Eloquent\Model;

/**
 * The comparison step of a merge (backlog items 46 and 90). It lists every field where the removed record holds
 * something the kept one lacks or holds differently, and what the admin may do with it: keep the kept record's value,
 * take the removed record's, or, for lists and notes, keep both. The defaults lose nothing that does not conflict, so
 * a quick merge stays one click: an empty field is filled, lists and «yes» flags are combined, and a conflicting value
 * stays as the kept record has it. For a person, the removed name becomes another name, a hidden misspelt form, or
 * the kept person's own name.
 *
 * apply() writes the choices onto the kept record (unsaved) and returns them with each changed column's value before
 * and after, for the audit log; undo() puts back the columns not edited since.
 *
 * Not compared, since the merge itself carries them: titles (they become other titles), the person's other names,
 * a library's name and city, the Fankha address, the shelfmark, and the source text (raw_text), whose entry moves
 * to the kept record (see RecordMerger::moveAttachments).
 */
class MergeComparison
{
    public const KEEP = 'target';
    public const TAKE = 'source';
    public const BOTH = 'both';
    // The removed person's name only
    public const ALIAS = 'alias';
    public const WRONG = 'wrong';

    // Registry fields the merge carries itself, or that name the record's place rather than describe it
    private const SKIP = ['primary_title', 'alternative_titles', 'language_summary', 'fankha_page', 'shelfmark', 'name', 'aliases'];

    // Columns no suggestion field covers
    private const EXTRA = [
        Manuscript::class => [
            'metadata' => ['label' => 'داده‌های دیگر نسخه (مهرها و تملک‌ها، آغاز و انجام‌های چندگانه، فوائد و رسائل…)', 'kind' => 'map', 'columns' => ['metadata']],
        ],
        Person::class => [
            'is_translator' => ['label' => 'مترجم', 'kind' => 'flag', 'columns' => ['is_translator']],
            'is_donor' => ['label' => 'واقف', 'kind' => 'flag', 'columns' => ['is_donor']],
        ],
        Library::class => [
            'logo' => ['label' => 'نشان کتابخانه', 'kind' => 'value', 'columns' => ['logo_path', 'logo_width', 'logo_height', 'logo_on_dark']],
        ],
    ];

    // Keys of a manuscript's metadata, as the comparison names them
    private const META_LABELS = [
        'incipits' => 'آغازها', 'explicits' => 'انجام‌ها', 'ownership_and_seals' => 'مهرها و تملک‌ها',
        'residual_notes' => 'فوائد و رسائل', 'former_locations' => 'نشانی‌های پیشین',
    ];

    /** @return array<string, array{label: string, kind: string, columns: string[], bit_label?: string}> */
    public static function specs(string $class): array
    {
        $specs = [];
        foreach (SuggestionRegistry::fields($class) as $name => $field) {
            // A list kept in metadata (seals) is compared with the rest of the metadata
            if (in_array($name, self::SKIP, true) || ($field['input'] === 'list' && isset($field['meta_key']))) {
                continue;
            }
            $kind = match ($field['input']) {
                'boolean' => 'flag',
                'list' => 'list',
                'textarea', 'quote' => 'text',
                'person' => 'contributor',
                'catalog_page' => null,
                default => 'value',
            };
            if ($kind) {
                // A date with its year, a contributor with their link, and «بی‌کاتب» / «بی‌تاریخ» go together
                $columns = array_values(array_filter([$name, $field['id_column'] ?? null, $field['year_column'] ?? null, $field['bit_column'] ?? null]));
                $specs[$name] = ['label' => $field['label'], 'kind' => $kind, 'columns' => $columns] + array_intersect_key($field, ['bit_label' => 1, 'bit_column' => 1]);
            }
        }

        return $specs + (self::EXTRA[$class] ?? []);
    }

    /**
     * The fields to decide, in the registry's order: the removed person's name first.
     *
     * @return array<int, array{key: string, label: string, kind: string, target: string[], source: string[], options: array<string, string>, default: string, note?: string}>
     */
    public static function rows(Model $source, Model $target): array
    {
        $rows = [];
        if ($source instanceof Person && TextNormalizer::normalize($source->name) !== TextNormalizer::normalize($target->name)) {
            $rows[] = [
                'key' => 'name', 'label' => 'نام', 'kind' => 'name',
                'target' => [$target->name], 'source' => [$source->name],
                'options' => [
                    self::ALIAS => 'نام رکورد حذف‌شونده «نام دیگر» شخص شود',
                    self::WRONG => 'نام رکورد حذف‌شونده نادرست است: فقط برای جستجو بماند و در صفحه‌ی شخص در فهرست نام‌ها نیاید',
                    self::TAKE => 'نام رکورد حذف‌شونده نام اصلی شود و نام کنونی «نام دیگر» شود',
                ],
                'default' => self::ALIAS,
            ];
        }

        foreach (self::specs($source::class) as $key => $spec) {
            $s = self::value($source, $spec);
            $t = self::value($target, $spec);
            if (self::blank($s)) {
                continue;
            }
            $row = ['key' => $key, 'label' => $spec['label'], 'kind' => $spec['kind'], 'target' => self::display($t, $spec), 'source' => self::display($s, $spec)];

            if ($spec['kind'] === 'flag') {
                if ($t) {
                    continue;
                }
                $rows[] = $row + ['options' => [self::TAKE => '«بله» شود', self::KEEP => '«خیر» بماند'], 'default' => self::TAKE];
                continue;
            }
            if (self::blank($t)) {
                $rows[] = $row + ['options' => [self::TAKE => 'از رکورد حذف‌شونده پر شود', self::KEEP => 'خالی بماند'], 'default' => self::TAKE];
                continue;
            }
            if (in_array($spec['kind'], ['list', 'map'], true)) {
                if (self::same(self::combine($t, $s), $t)) {
                    continue;
                }
                $rows[] = $row + ['options' => [self::BOTH => 'هر دو یکی شوند', self::KEEP => 'فقط مقدار رکورد ماندنی', self::TAKE => 'فقط مقدار رکورد حذف‌شونده'], 'default' => self::BOTH];
                continue;
            }
            if (self::same($s, $t)) {
                continue;
            }
            $options = [self::KEEP => 'مقدار رکورد ماندنی بماند', self::TAKE => 'مقدار رکورد حذف‌شونده جایگزین شود'];
            if ($spec['kind'] === 'text') {
                $options[self::BOTH] = 'هر دو، پشت هم';
            }
            if ($spec['kind'] === 'contributor') {
                // Contributors live in their own table too; a change goes through the contributor tools
                $options = [self::KEEP => 'مقدار رکورد ماندنی بماند'];
                $row['note'] = 'برای عوض کردن ' . $spec['label'] . '، پس از ادغام از ابزار «' . $spec['label'] . '» استفاده کنید.';
            }
            $rows[] = $row + ['options' => $options, 'default' => self::KEEP];
        }

        return $rows;
    }

    /**
     * Each row's choice: the submitted one when the row offers it, else the default.
     *
     * @return array<string, string>
     */
    public static function choices(array $rows, ?array $submitted): array
    {
        $chosen = [];
        foreach ($rows as $row) {
            $choice = $submitted[$row['key']] ?? null;
            $chosen[$row['key']] = is_string($choice) && isset($row['options'][$choice]) ? $choice : $row['default'];
        }

        return $chosen;
    }

    /**
     * Writes the choices onto $target without saving it. The person's name is left to PersonMerger.
     *
     * @return array{choices: array<string, string>, before: array<string, mixed>, after: array<string, mixed>}
     */
    public static function apply(Model $source, Model $target, ?array $submitted = null): array
    {
        $choices = self::choices(self::rows($source, $target), $submitted);
        $specs = self::specs($source::class);
        $before = [];
        $after = [];
        foreach ($choices as $key => $choice) {
            $spec = $specs[$key] ?? null;
            if (! $spec || $choice === self::KEEP) {
                continue;
            }
            $values = $choice === self::BOTH
                ? [$spec['columns'][0] => self::merged(self::value($target, $spec), self::value($source, $spec), $spec['kind'])]
                : array_combine($spec['columns'], array_map(fn ($c) => $source->{$c}, $spec['columns']));
            foreach ($values as $column => $value) {
                if (! self::same($target->{$column}, $value)) {
                    $before[$column] = $target->{$column};
                    $target->{$column} = $after[$column] = $value;
                }
            }
        }

        return ['choices' => $choices, 'before' => $before, 'after' => $after];
    }

    /** Puts back the columns apply() changed, unless they were edited since. Leaves $target unsaved. */
    public static function undo(Model $target, array $applied): void
    {
        foreach ((array) ($applied['before'] ?? []) as $column => $value) {
            if (self::same($target->{$column}, $applied['after'][$column] ?? null)) {
                $target->{$column} = $value;
            }
        }
    }

    /** The columns' values, the first one standing for the field. */
    private static function value(Model $record, array $spec): mixed
    {
        $value = $record->{$spec['columns'][0]};
        // «بی‌کاتب» / «بی‌تاریخ» is a value of its own
        if (self::blank($value) && isset($spec['bit_column']) && $record->{$spec['bit_column']}) {
            return $spec['bit_label'];
        }

        return $value;
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || $value === false;
    }

    /** Equal up to spelling variants, list order and numeric type. */
    private static function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return self::key((array) ($a ?? [])) === self::key((array) ($b ?? []));
        }
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }

        return TextNormalizer::normalize((string) $a) === TextNormalizer::normalize((string) $b);
    }

    private static function key(array $value): string
    {
        if (array_is_list($value)) {
            $value = array_map(fn ($v) => is_array($v) ? self::key($v) : TextNormalizer::normalize((string) $v), $value);
            sort($value);
        } else {
            ksort($value);
            $value = array_map(fn ($v) => is_array($v) ? self::key($v) : TextNormalizer::normalize((string) $v), $value);
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /** Both values kept: a list or map combined, a note written after the other. */
    private static function merged(mixed $target, mixed $source, string $kind): mixed
    {
        return $kind === 'text' ? rtrim((string) $target) . "\n\n" . ltrim((string) $source) : self::combine($target, $source);
    }

    /** Lists united without repeats; in a map each key combined, a differing text kept after the other. */
    private static function combine(mixed $target, mixed $source): mixed
    {
        if (self::blank($target)) {
            return $source;
        }
        if (self::blank($source) || self::same($target, $source)) {
            return $target;
        }
        if (! is_array($target) || ! is_array($source)) {
            return is_string($target) && is_string($source) ? rtrim($target) . "\n\n" . ltrim($source) : $target;
        }
        if (array_is_list($target) && array_is_list($source)) {
            $known = array_map(fn ($v) => self::key((array) $v), $target);
            foreach ($source as $item) {
                if (! in_array(self::key((array) $item), $known, true)) {
                    $target[] = $item;
                    $known[] = self::key((array) $item);
                }
            }

            return $target;
        }
        foreach ($source as $k => $v) {
            $target[$k] = array_key_exists($k, $target) ? self::combine($target[$k], $v) : $v;
        }

        return $target;
    }

    /** @return string[] the value as lines for the comparison table */
    private static function display(mixed $value, array $spec): array
    {
        if (self::blank($value)) {
            return [];
        }
        if ($spec['kind'] === 'flag') {
            return [$value ? 'بله' : 'خیر'];
        }
        if ($spec['kind'] === 'map') {
            $lines = [];
            foreach ((array) $value as $k => $v) {
                if (! self::blank($v)) {
                    $lines[] = (self::META_LABELS[$k] ?? $k) . ': ' . self::text($v);
                }
            }

            return $lines;
        }
        if (is_array($value)) {
            return array_values(array_filter(array_map(fn ($v) => self::text($v), $value), 'strlen'));
        }
        if ($spec['columns'][0] === 'logo_path') {
            return [basename((string) $value)];
        }

        return [is_int($value) ? \App\Support\Fa::digits($value) : (string) $value];
    }

    private static function text(mixed $v): string
    {
        if (is_array($v)) {
            $parts = array_filter(array_map(fn ($x) => is_array($x) ? ($x['text'] ?? $x['title'] ?? self::text($x)) : (string) $x, $v), fn ($x) => is_string($x) && trim($x) !== '');

            return \Illuminate\Support\Str::limit(implode(' · ', $parts), 300);
        }

        return \Illuminate\Support\Str::limit((string) $v, 300);
    }
}
