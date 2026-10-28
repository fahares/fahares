<?php

namespace App\Suggestions;

use App\Models\FieldSuggestion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The readings of a record's fields built from approved suggestions, as the record page shows them:
 * the main value (an adopted reading, or the catalog's own value), the alternative readings, and a
 * history of superseded readings and credited edits.
 */
class SuggestionReadings
{
    /**
     * @return Collection<string, array{label: string, adopted: ?FieldSuggestion, current: ?string,
     *     alternatives: Collection, baseline: ?string, history: Collection, count: int}>
     */
    public static function for(Model $record): Collection
    {
        $type = SuggestionRegistry::typeKey($record::class);

        $sections = FieldSuggestion::where('suggestable_type', $record::class)
            ->where('suggestable_id', $record->getKey())
            ->where('kind', 'field')
            ->where('status', 'approved')
            ->whereNotNull('standing')
            ->with('supersededBy')
            ->orderBy('reviewed_at')->orderBy('id')
            ->get()
            ->groupBy('field_name')
            ->map(function (Collection $items, string $name) use ($record, $type) {
                $field = SuggestionRegistry::field($type, $name);
                $adopted = $items->firstWhere('standing', 'adopted');
                $alternatives = $items->where('standing', 'alternative')->values();
                $history = $items->filter(fn ($s) => $s->standing === 'superseded' || ($s->standing === 'edit' && $s->credit_name))->values();

                // The value before the first reading was approved, normally the printed catalog's;
                // listed as a reading of its own when it differs from every approved one
                $baseline = $items->where('standing', '!=', 'edit')->first()?->current_value;
                $readings = $items->whereIn('standing', ['adopted', 'alternative'])->map(fn ($s) => self::same($s->display_value));
                if ($baseline !== null && $readings->contains(self::same($baseline))) {
                    $baseline = null;
                }
                // Without an adopted reading the record still shows the catalog's value, which is then the main one
                $current = ! $adopted && $field ? SuggestionRegistry::currentValue($record, ['name' => $name] + $field) : null;
                if ($current !== null && self::same($current) === self::same((string) $baseline)) {
                    $baseline = null;
                }

                return [
                    'label' => $field['label'] ?? $name,
                    'adopted' => $adopted,
                    'current' => $current,
                    'alternatives' => $alternatives,
                    'baseline' => $baseline,
                    'history' => $history,
                    'count' => ($adopted || $current !== null ? 1 : 0) + $alternatives->count() + ($baseline !== null ? 1 : 0),
                ];
            })
            ->filter(fn ($r) => $r['adopted'] || $r['alternatives']->isNotEmpty() || $r['history']->isNotEmpty());

        return self::joinConverted($sections);
    }

    /**
     * One suggestion converted into edits of several fields (death year unknown, century 4) reads as a single
     * statement: the fields whose adopted readings come from the same original are shown as one section, under
     * the first field; the other fields keep the section for their badges and are marked merged_into.
     */
    private static function joinConverted(Collection $sections): Collection
    {
        // The original itself may be approved too (the death year cleared), so it groups by its own id
        $groups = $sections->filter(fn ($r) => $r['adopted'] !== null)
            ->groupBy(fn ($r) => $r['adopted']->payload['derived_from'] ?? $r['adopted']->id, preserveKeys: true)
            ->filter(fn ($g) => $g->count() > 1);

        foreach ($groups as $group) {
            $line = fn (array $r, ?string $value) => $r['label'] . ': ' . ($value === null || trim($value) === '' ? 'نامشخص' : $value);
            $baselines = $group->filter(fn ($r) => $r['baseline'] !== null)->map(fn ($r) => $line($r, $r['baseline']));
            $joined = [
                'label' => $group->pluck('label')->implode(' و '),
                'adopted' => $group->first()['adopted'],
                'value' => $group->map(fn ($r) => $line($r, $r['adopted']->display_value))->implode("\n"),
                'current' => null,
                'alternatives' => $group->pluck('alternatives')->flatten(1)->values(),
                'baseline' => $baselines->isEmpty() ? null : $baselines->implode("\n"),
                'history' => $group->pluck('history')->flatten(1)->values(),
            ];
            $joined['count'] = 1 + $joined['alternatives']->count() + ($joined['baseline'] !== null ? 1 : 0);
            $first = $group->keys()->first();
            foreach ($group->keys() as $key) {
                $sections[$key] = $joined + ($key === $first ? [] : ['merged_into' => $first]);
            }
        }

        return $sections;
    }

    /** Names credited for the main reading of a field, for the badge beside it. */
    public static function badge(Collection $readings, string $field): ?array
    {
        $r = $readings->get($field);
        if (! $r || (! $r['adopted'] && $r['alternatives']->isEmpty())) {
            return null;
        }
        $name = $r['adopted']?->credit_name;

        return [
            'count' => $r['count'],
            'title' => $r['count'] > 1
                ? "{$r['count']} قول درباره‌ی این فیلد؛ جزئیات و مستندات در پایین صفحه"
                : ($name ? "اصلاح به پیشنهاد {$name}" : 'اصلاح‌شده بر پایه‌ی پیشنهاد پژوهشگران؛ مستند در پایین صفحه'),
        ];
    }

    private static function same(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value));
    }
}
