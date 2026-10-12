<?php

namespace App\Http\Controllers;

use App\Models\FieldSuggestion;
use App\Models\Person;
use App\Suggestions\SuggestionRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SuggestionController extends Controller
{
    public function store(Request $request)
    {
        // Pages cached before the per-field form still post the class name and the old "other" field
        if (! $request->filled('type') && $request->filled('suggestable_type')) {
            $request->merge([
                'type' => SuggestionRegistry::typeKey((string) $request->input('suggestable_type')),
                'id' => $request->input('suggestable_id'),
                'kind' => $request->input('field_name') === 'other' ? 'general' : 'field',
            ]);
        }

        $validated = $request->validate([
            'type' => ['required', Rule::in(array_keys(SuggestionRegistry::TYPES))],
            'id' => 'required|integer',
            'kind' => ['required', Rule::in(SuggestionRegistry::KINDS)],
            'field_name' => 'nullable|string|max:100',
            'suggested_value' => 'required_unless:kind,relation|nullable|string|max:20000',
            'rationale_citation' => 'nullable|string|max:5000',
            'person_id' => 'nullable|integer|exists:people,id',
            'target_id' => 'nullable|integer',
            'guest_name' => 'nullable|string|max:100',
            'guest_email' => 'nullable|email|max:100',
            'website' => 'nullable|max:0', // honeypot, hidden from people
        ], [
            'suggested_value.required' => 'متن پیشنهاد را وارد کنید.',
            'website.max' => 'ارسال پذیرفته نشد.',
        ]);

        $record = SuggestionRegistry::modelClass($validated['type'])::find($validated['id']);
        if (! $record) {
            throw ValidationException::withMessages(['id' => 'رکورد مورد نظر پیدا نشد.']);
        }

        $field = null;
        if ($validated['kind'] === 'field') {
            $field = SuggestionRegistry::field($validated['type'], $validated['field_name'] ?? null);
            if (! $field) {
                throw ValidationException::withMessages(['field_name' => 'این فیلد برای پیشنهاد اصلاح تعریف نشده است.']);
            }
            if (mb_strlen(trim($validated['rationale_citation'] ?? '')) < 5) {
                throw ValidationException::withMessages(['rationale_citation' => 'مستند یا منبع پیشنهاد را بنویسید (دست‌کم ۵ نویسه).']);
            }
        }

        $relation = null;
        if ($validated['kind'] === 'relation') {
            $relation = SuggestionRegistry::relation($validated['type'], $validated['field_name'] ?? null);
            if (! $relation) {
                throw ValidationException::withMessages(['field_name' => 'این نوع پیشنهاد برای این رکورد تعریف نشده است.']);
            }
            $target = SuggestionRegistry::modelClass($relation['target'])::find($validated['target_id'] ?? 0);
            if (! $target) {
                throw ValidationException::withMessages(['target_id' => 'رکورد مقصد را انتخاب کنید.']);
            }
            if ($target->is($record)) {
                throw ValidationException::withMessages(['target_id' => 'رکورد مقصد نمی‌تواند خود همین رکورد باشد.']);
            }
            if (mb_strlen(trim($validated['rationale_citation'] ?? '')) < 5) {
                throw ValidationException::withMessages(['rationale_citation' => 'مستند یا منبع پیشنهاد را بنویسید (دست‌کم ۵ نویسه).']);
            }
        }

        $user = $request->user();
        $payload = array_filter([
            'person_id' => $field && $field['input'] === 'person' ? ($validated['person_id'] ?? null) : null,
            'target_id' => $relation ? (int) $validated['target_id'] : null,
        ]);

        FieldSuggestion::create([
            'suggestable_type' => $record::class,
            'suggestable_id' => $record->id,
            'kind' => $validated['kind'],
            'field_name' => $field['name'] ?? $relation['key'] ?? null,
            'current_value' => $field ? SuggestionRegistry::currentValue($record, $field) : null,
            // A relation needs no extra text; its label stands in when the submitter adds none
            'suggested_value' => trim($validated['suggested_value'] ?? '') ?: $relation['label'],
            'rationale_citation' => $validated['rationale_citation'] ?? null,
            'payload' => $payload ?: null,
            'user_id' => $user?->id,
            'guest_name' => $user ? $user->name : ($validated['guest_name'] ?? 'پژوهشگر مهمان'),
            'guest_email' => $user ? $user->email : ($validated['guest_email'] ?? null),
            'status' => 'pending',
        ]);

        $message = 'پیشنهاد شما ثبت شد و پس از بررسی هیئت علمی اعمال خواهد شد. سپاس از همراهی‌تان.';

        return $request->expectsJson()
            ? response()->json(['message' => $message])
            : back()->with('success', $message);
    }

    /**
     * Target picker for relational suggestions: a pasted fahares link or id, or a title/name search.
     */
    public function lookup(Request $request)
    {
        $type = SuggestionRegistry::typeKey((string) $request->query('type', ''));
        $class = $type ? SuggestionRegistry::modelClass($type) : null;
        $q = trim((string) $request->query('q', ''));
        if (! $class || $type === 'library' || mb_strlen($q) < 1) {
            return response()->json([]);
        }

        $prefix = ['manuscript' => 'm|manuscripts', 'work' => 'w|works', 'person' => 'p|people'][$type];
        if (preg_match('~/(?:' . $prefix . ')/(\d+)~u', $q, $m) || preg_match('/^\s*#?(\d+)\s*$/u', strtr($q, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']), $m)) {
            $records = $class::whereKey((int) $m[1])->get();
        } elseif ($type === 'manuscript' || mb_strlen($q) < 2) {
            // Manuscripts have no useful text search here; they are picked by link or id
            $records = collect();
        } else {
            try {
                $records = $class::search($q)->take(10)->get();
            } catch (\Throwable) {
                $column = $type === 'work' ? 'primary_title' : 'name';
                $records = $class::where($column, 'like', '%' . $q . '%')->limit(10)->get();
            }
        }

        return response()->json($records->map(fn ($r) => SuggestionRegistry::describe($r))->values());
    }

    /** Name lookup for the scribe/author picker in the suggestion form. */
    public function people(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        try {
            $people = Person::search($q)->take(10)->get();
        } catch (\Throwable) {
            $people = Person::where('name', 'like', '%' . $q . '%')->orderByDesc('manuscripts_count')->limit(10)->get();
        }

        return response()->json($people->map(fn (Person $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'years' => self::lifeYears($p),
            'manuscripts_count' => (int) $p->manuscripts_count,
            'works_count' => (int) $p->works_count,
            'url' => route('people.show', $p),
        ])->values());
    }

    public static function lifeYears(Person $p): ?string
    {
        if ($p->death_year_hijri) {
            return $p->birth_year_hijri ? "{$p->birth_year_hijri}–{$p->death_year_hijri} ق" : "د. {$p->death_year_hijri} ق";
        }

        return $p->century_hijri ? "سده‌ی {$p->century_hijri} ق" : null;
    }
}
