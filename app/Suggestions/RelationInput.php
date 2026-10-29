<?php

namespace App\Suggestions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Checks a relational suggestion (see SuggestionRegistry::RELATIONS) before it is stored, whether a
 * researcher sends it or a reviewer converts another suggestion into one.
 */
class RelationInput
{
    /**
     * $input: field_name (the relation key), suggested_value, target_id, role, role_name, shelfmark.
     * Returns the relation and its payload; throws ValidationException with the form's field names.
     *
     * @return array{relation: array, payload: array}
     */
    public static function validate(string $type, Model $record, array $input): array
    {
        $relation = SuggestionRegistry::relation($type, $input['field_name'] ?? null);
        if (! $relation || ! empty($relation['queue_only'])) {
            throw ValidationException::withMessages(['field_name' => 'این نوع پیشنهاد برای این رکورد تعریف نشده است.']);
        }
        $apply = $relation['apply'] ?? null;
        // Another name, a library's new name or what belongs to another record is carried by the submitted text
        if (! empty($relation['needs_text'])) {
            if (trim($input['suggested_value'] ?? '') === '') {
                throw ValidationException::withMessages(['suggested_value' => match ($apply) {
                    'rename_library' => 'نام تازه‌ی کتابخانه را بنویسید.',
                    'split' => 'بنویسید کدام بخش‌ها از رکورد دیگری‌اند.',
                    default => 'نام دیگر را بنویسید.',
                }]);
            }
            if ($apply === 'rename_library' && trim($input['suggested_value']) === $record->name) {
                throw ValidationException::withMessages(['suggested_value' => 'نام تازه همان نام کنونی کتابخانه است.']);
            }
        }
        $target = $relation['target'] ? SuggestionRegistry::modelClass($relation['target'])::find($input['target_id'] ?? 0) : null;
        // A person connected with the copy: a role, and a person from the list or only a name
        if ($apply === 'record_relation' && ! array_key_exists($input['role'] ?? '', RecordRelations::options($type))) {
            throw ValidationException::withMessages(['role' => 'نوع رابطه را انتخاب کنید.']);
        }
        if ($apply === 'person_role') {
            if (! array_key_exists($input['role'] ?? '', SuggestionRegistry::personRoles($type))) {
                throw ValidationException::withMessages(['role' => 'نقش این شخص را انتخاب کنید.']);
            }
            if (! $target && trim($input['role_name'] ?? '') === '') {
                throw ValidationException::withMessages(['role_name' => 'شخص را از فهرست انتخاب کنید یا نامش را بنویسید.']);
            }
        }
        // A new library, a new shelfmark, or both
        if ($apply === 'relocate') {
            $shelfmark = trim($input['shelfmark'] ?? '');
            if (! $target && $shelfmark === '') {
                throw ValidationException::withMessages(['shelfmark' => 'کتابخانه‌ی تازه را انتخاب کنید یا شماره‌ی تازه‌ی نسخه را بنویسید.']);
            }
            if ((! $target || $target->id === (int) $record->library_id) && ($shelfmark === '' || $shelfmark === $record->shelfmark)) {
                throw ValidationException::withMessages(['shelfmark' => 'کتابخانه و شماره‌ی تازه همان نشانی کنونی نسخه است.']);
            }
        }
        if ($relation['target'] && ! $target && empty($relation['target_optional'])) {
            throw ValidationException::withMessages(['target_id' => 'رکورد مقصد را انتخاب کنید.']);
        }
        if ($target?->is($record)) {
            throw ValidationException::withMessages(['target_id' => 'رکورد مقصد نمی‌تواند خود همین رکورد باشد.']);
        }

        return ['relation' => $relation, 'payload' => array_filter([
            'target_id' => $target?->id,
            'role' => in_array($apply, ['person_role', 'record_relation'], true) ? $input['role'] : null,
            'name' => $apply === 'person_role' ? (trim($input['role_name'] ?? '') ?: null) : null,
            // The row a removal points at, when it was sent from that row's own button
            'row_id' => ! empty($relation['remove']) && method_exists($record, 'workPeople') && $record->workPeople()->whereKey((int) ($input['role_row_id'] ?? 0))->exists() ? (int) $input['role_row_id'] : null,
            'shelfmark' => $apply === 'relocate' ? (trim($input['shelfmark'] ?? '') ?: null) : null,
        ])];
    }
}
