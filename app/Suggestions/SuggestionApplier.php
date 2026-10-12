<?php

namespace App\Suggestions;

use App\Helpers\TextNormalizer;
use App\Models\Person;
use App\Support\HijriYear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Writes an approved field suggestion to its record, following the field's input type
 * in SuggestionRegistry. Runs inside the reviewer's transaction.
 */
class SuggestionApplier
{
    public const PERSON_ACTIONS = ['link', 'create', 'text'];

    /**
     * @param  array  $input  applied_value, plus hijri_year (hijri_date) or person_action/person_id (person)
     * @return array{old: array, new: array, resolution: array}
     */
    public function apply(Model $record, array $field, array $input): array
    {
        $value = $this->clean($input['applied_value'] ?? null);
        $name = $field['name'];
        $resolution = [];
        $previousPersonId = null;

        if ($value === null && ! empty($field['required'])) {
            throw new InvalidArgumentException('این فیلد نمی‌تواند خالی بماند.');
        }

        switch ($field['input']) {
            case 'integer':
                if ($value !== null && ! ctype_digit($value = $this->latinDigits($value))) {
                    throw new InvalidArgumentException('مقدار این فیلد باید عدد صحیح باشد.');
                }
                if ($value !== null && (isset($field['min']) && (int) $value < $field['min'] || isset($field['max']) && (int) $value > $field['max'])) {
                    throw new InvalidArgumentException("مقدار باید بین {$field['min']} و {$field['max']} باشد.");
                }
                $record->{$name} = $value === null ? null : (int) $value;
                break;

            case 'boolean':
                $record->{$name} = in_array($value, ['1', 'true', 'بله'], true);
                break;

            case 'hijri_date':
                $year = $input['hijri_year'] ?? null;
                $year = $year === null || $year === '' ? null : (int) $year;
                if ($year !== null && ($year < HijriYear::MIN || $year > HijriYear::MAX)) {
                    throw new InvalidArgumentException('سال هجری خارج از بازه‌ی مجاز است.');
                }
                $record->{$name} = $value;
                // Set explicitly so the model's saving hook keeps the reviewer's year
                $record->{$field['year_column']} = $year;
                if ($value !== null && ! empty($field['bit_column'])) {
                    $record->{$field['bit_column']} = false;
                }
                $resolution['hijri_year'] = $year;
                break;

            case 'quote':
                $record->{$name} = $value;
                $meta = $record->metadata ?? [];
                $meta[$field['meta_key']] = $value === null ? [] : [['label' => null, 'text' => $value]];
                $record->metadata = $meta;
                break;

            case 'list':
                $items = $value === null ? [] : array_values(array_filter(array_map('trim', preg_split('/\R/u', $value)), 'strlen'));
                if (isset($field['meta_key'])) {
                    $meta = $record->metadata ?? [];
                    $meta[$field['meta_key']] = $items;
                    $record->metadata = $meta;
                } else {
                    $record->{$name} = $this->listColumn((array) ($record->{$name} ?? []), $items, $field['item_key'] ?? null) ?: null;
                }
                break;

            case 'person':
                $previousPersonId = $record->{$field['id_column']};
                $resolution = $this->resolvePerson($record, $field, $value, $input);
                $record->{$name} = $value;
                if ($value !== null && ! empty($field['bit_column'])) {
                    $record->{$field['bit_column']} = false;
                }
                break;

            default:
                $record->{$name} = $value;
        }

        foreach ($field['mirror'] ?? [] as $column) {
            $record->{$column} = $record->{$name};
        }

        $old = array_intersect_key($record->getOriginal(), $record->getDirty());
        $record->save();
        $new = $record->getChanges();
        unset($new['updated_at']);

        if ($field['input'] === 'person') {
            $this->recount($previousPersonId, $record->{$field['id_column']});
        }

        return ['old' => $old, 'new' => $new, 'resolution' => $resolution];
    }

    private function resolvePerson(Model $record, array $field, ?string $name, array $input): array
    {
        $action = $input['person_action'] ?? 'text';
        if (! in_array($action, self::PERSON_ACTIONS, true)) {
            throw new InvalidArgumentException('گزینه‌ی پیوند شخص نامعتبر است.');
        }

        if ($action === 'link') {
            $person = Person::find($input['person_id'] ?? null);
            if (! $person) {
                throw new InvalidArgumentException('شخص انتخاب‌شده پیدا نشد.');
            }
            $record->{$field['id_column']} = $person->id;

            return ['person_action' => 'link', 'person_id' => $person->id];
        }

        if ($action === 'create') {
            if ($name === null) {
                throw new InvalidArgumentException('برای ساخت شخص تازه، نام لازم است.');
            }
            $person = Person::create([
                'name' => $name,
                'normalized_name' => mb_substr(TextNormalizer::normalize($name), 0, 600),
                'slug' => 'person-' . Str::random(12),
                'is_author' => $field['role'] === 'author',
                'is_scribe' => $field['role'] === 'scribe',
            ]);
            $person->update(['slug' => (Str::slug($name, '-', null) ?: 'person') . '-' . $person->id]);
            $record->{$field['id_column']} = $person->id;

            return ['person_action' => 'create', 'person_id' => $person->id];
        }

        return ['person_action' => 'text'];
    }

    /**
     * Rebuilds a JSON list column from one item per line. For object items (e.g. alternative titles)
     * an unchanged item keeps its other keys, such as its transliteration.
     */
    private function listColumn(array $existing, array $lines, ?string $itemKey): array
    {
        if ($itemKey === null) {
            return $lines;
        }
        $byText = [];
        foreach ($existing as $item) {
            if (is_array($item) && isset($item[$itemKey])) {
                $byText[trim((string) $item[$itemKey])] = $item;
            }
        }

        return array_map(fn ($line) => $byText[$line] ?? [$itemKey => $line], $lines);
    }

    private function recount(?int $previousId, ?int $currentId): void
    {
        foreach (array_unique(array_filter([$previousId, $currentId])) as $id) {
            Person::find($id)?->recountContributions();
        }
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));

        return $value === '' ? null : $value;
    }

    private function latinDigits(string $value): string
    {
        return strtr($value, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }
}
