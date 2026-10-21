<?php

namespace App\Suggestions;

use App\Helpers\TextNormalizer;
use App\Models\ManuscriptCatalogReference as Ref;
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

        // A catalogue reference lives in its own table: written there, and the record itself is left as it is
        if ($field['input'] === 'catalog_page' && isset($field['reference_id'])) {
            return $this->applyCatalogReference($record, $field, $value);
        }

        switch ($field['input']) {
            case 'catalog_page':
                $page = SuggestionRegistry::parseCatalogPage($value);
                if (! $page) {
                    throw new InvalidArgumentException('نشانی را به شکل «ج ۲، ص ۷، ردیف ۳» بنویسید.');
                }
                [$volumeColumn, $pageColumn, $rowColumn] = $field['columns'];
                $record->{$volumeColumn} = $page['volume'];
                $record->{$pageColumn} = $page['page'];
                if ($page['row'] !== null) {
                    $record->{$rowColumn} = $page['row'];
                }
                break;

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

    /**
     * The corrected address of a catalogue reference (backlog item 51): it becomes a manual, verified reference
     * at the new page, so the review badge goes away and catalogs:match-edition no longer rewrites it. «نیست»
     * rejects it, and the link leaves the record page.
     */
    private function applyCatalogReference(Model $record, array $field, ?string $value): array
    {
        $ref = Ref::find($field['reference_id']);
        if (! $ref || $ref->manuscript_id !== $record->getKey()) {
            throw new InvalidArgumentException('این ارجاع از این نسخه نیست.');
        }
        $reviewer = ['reviewed_by' => auth()->id(), 'reviewed_at' => now()];
        if ($value === SuggestionRegistry::ABSENT) {
            $ref->fill(['status' => Ref::STATUS_REJECTED] + $reviewer);
        } else {
            $page = SuggestionRegistry::parseCatalogPage($value);
            if (! $page) {
                throw new InvalidArgumentException('نشانی را به شکل «ج ۲، ص ۷» بنویسید، یا «نیست» اگر نسخه در این فهرست نیامده است.');
            }
            $ref->fill([
                'volume_number' => $page['volume'],
                'page_start' => $page['page'],
                // the entry still ends where it did when the new page falls inside it
                'page_end' => $ref->page_end && $page['page'] <= $ref->page_end && $page['page'] >= (int) $ref->page_start ? $ref->page_end : null,
                'source' => Ref::SOURCE_MANUAL,
                'status' => Ref::STATUS_VERIFIED,
            ] + $reviewer);
        }
        $old = array_intersect_key($ref->getOriginal(), $ref->getDirty());
        $ref->save();
        $new = $ref->getChanges();
        unset($new['updated_at'], $new['reviewed_at']);

        // The audit log entry is the manuscript's; the reference's before and after go with it
        return ['old' => ['catalog_reference' => ['id' => $ref->id] + $old], 'new' => ['catalog_reference' => ['id' => $ref->id] + $new], 'resolution' => ['reference_id' => $ref->id]];
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
