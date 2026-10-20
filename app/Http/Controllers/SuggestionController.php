<?php

namespace App\Http\Controllers;

use App\Models\FieldSuggestion;
use App\Models\Person;
use App\Notifications\SuggestionNotifier;
use App\Suggestions\NoteTypes;
use App\Suggestions\RelationInput;
use App\Suggestions\SuggestionRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
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
            'suggested_value' => 'required_if:kind,field,general|nullable|string|max:20000',
            'rationale_citation' => 'nullable|string|max:5000',
            'person_id' => 'nullable|integer|exists:people,id',
            'target_id' => 'nullable|integer',
            'guest_name' => 'nullable|string|max:100',
            'guest_email' => 'nullable|email|max:100',
            'credit_name' => 'nullable|string|max:150',
            'notify_submitter' => 'nullable|boolean',
            'role' => 'nullable|string|max:30',
            'role_name' => 'nullable|string|max:600',
            'shelfmark' => 'nullable|string|max:255',
            'note' => 'nullable|array',
            'files' => 'nullable|array|max:' . NoteTypes::MAX_FILES,
            'files.*' => 'image|mimes:jpg,jpeg,png,webp|max:2048',
            'rights_confirmed' => 'nullable|boolean',
            'website' => 'nullable|max:0', // honeypot, hidden from people
        ], [
            'suggested_value.required' => 'متن پیشنهاد را وارد کنید.',
            'website.max' => 'ارسال پذیرفته نشد.',
            'files.max' => 'حداکثر ' . NoteTypes::MAX_FILES . ' تصویر می‌توانید بارگذاری کنید.',
            'files.*.image' => 'فقط تصویر (JPG، PNG یا WebP) پذیرفته می‌شود.',
            'files.*.mimes' => 'فقط تصویر (JPG، PNG یا WebP) پذیرفته می‌شود.',
            'files.*.max' => 'حجم هر تصویر حداکثر ۲ مگابایت است.',
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

        if ($validated['kind'] === 'note') {
            return $this->storeNote($request, $record, $validated);
        }

        $relation = null;
        $relationPayload = [];
        if ($validated['kind'] === 'relation') {
            ['relation' => $relation, 'payload' => $relationPayload] = RelationInput::validate($validated['type'], $record, $validated);
            if (mb_strlen(trim($validated['rationale_citation'] ?? '')) < 5) {
                throw ValidationException::withMessages(['rationale_citation' => 'مستند یا منبع پیشنهاد را بنویسید (دست‌کم ۵ نویسه).']);
            }
        }

        $user = $request->user();
        $payload = array_filter([
            'person_id' => $field && $field['input'] === 'person' ? ($validated['person_id'] ?? null) : null,
        ]) + $relationPayload;

        $suggestion = FieldSuggestion::create([
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
            // Sent only when the submitter ticked the consent box
            'credit_name' => trim($validated['credit_name'] ?? '') ?: null,
            // Guests opt in to hear the outcome by email; signed-in users follow their notification settings
            'notify_submitter' => ! $user && ! empty($validated['guest_email']) && $request->boolean('notify_submitter'),
            'status' => 'pending',
        ]);

        SuggestionNotifier::submitted($suggestion);

        $message = 'پیشنهاد شما ثبت شد و پس از بررسی هیئت علمی اعمال خواهد شد. سپاس از همراهی‌تان.';

        return $request->expectsJson()
            ? response()->json(['message' => $message])
            : back()->with('success', $message);
    }

    /**
     * A research note: its typed fields in payload and, for images, uploaded files. Files only come
     * from signed-in users who confirm they may publish them; they stay unlisted until approval.
     */
    private function storeNote(Request $request, $record, array $validated)
    {
        $type = SuggestionRegistry::typeKey($record::class);
        $noteType = $validated['field_name'] ?? null;
        if (! NoteTypes::allowed($type, $noteType)) {
            throw ValidationException::withMessages(['field_name' => 'این نوع یادداشت برای این رکورد تعریف نشده است.']);
        }

        $user = $request->user();
        $files = empty(NoteTypes::TYPES[$noteType]['files']) ? [] : $request->file('files', []);
        if ($files && ! $user) {
            throw ValidationException::withMessages(['files' => 'برای بارگذاری تصویر وارد حساب کاربری شوید. پیوند تصویر را بدون ورود هم می‌توانید بفرستید.']);
        }
        if ($files && ! $request->boolean('rights_confirmed')) {
            throw ValidationException::withMessages(['rights_confirmed' => 'تأیید کنید که تصویر را خودتان تهیه کرده‌اید یا اجازه‌ی انتشار آن را دارید.']);
        }
        $values = NoteTypes::clean($noteType, (array) ($validated['note'] ?? []), (bool) $files);

        $suggestion = FieldSuggestion::create([
            'suggestable_type' => $record::class,
            'suggestable_id' => $record->id,
            'kind' => 'note',
            'field_name' => $noteType,
            'suggested_value' => NoteTypes::summary($noteType, $values),
            'rationale_citation' => trim($validated['rationale_citation'] ?? '') ?: null,
            'payload' => $values,
            'user_id' => $user?->id,
            'guest_name' => $user ? $user->name : ($validated['guest_name'] ?? 'پژوهشگر مهمان'),
            'guest_email' => $user ? $user->email : ($validated['guest_email'] ?? null),
            'credit_name' => trim($validated['credit_name'] ?? '') ?: null,
            'notify_submitter' => ! $user && ! empty($validated['guest_email']) && $request->boolean('notify_submitter'),
            'status' => 'pending',
        ]);

        if ($files) {
            $paths = [];
            foreach ($files as $file) {
                // Unguessable names on the persistent public volume; served through NoteFileController
                $paths[] = $file->storeAs("notes/{$suggestion->id}", Str::random(40) . '.' . $file->extension(), 'public');
            }
            $suggestion->update(['payload' => $values + ['files' => $paths]]);
        }

        SuggestionNotifier::submitted($suggestion);

        $message = 'یادداشت شما ثبت شد و پس از بررسی هیئت علمی در صفحه منتشر می‌شود. سپاس از همراهی‌تان.';

        return $request->expectsJson() ? response()->json(['message' => $message]) : back()->with('success', $message);
    }

    /**
     * Target picker for relational suggestions: a pasted fahares link or id, or a title/name search.
     */
    public function lookup(Request $request)
    {
        $type = SuggestionRegistry::typeKey((string) $request->query('type', ''));
        $class = $type ? SuggestionRegistry::modelClass($type) : null;
        $q = trim((string) $request->query('q', ''));
        if (! $class || mb_strlen($q) < 1) {
            return response()->json([]);
        }

        $prefix = ['manuscript' => 'm|manuscripts', 'work' => 'w|works', 'person' => 'p|people', 'library' => 'libraries'][$type];
        if (preg_match('~/(?:' . $prefix . ')/(\d+)~u', $q, $m) || preg_match('/^\s*#?(\d+)\s*$/u', strtr($q, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']), $m)) {
            $records = $class::whereKey((int) $m[1])->get();
        } elseif ($type === 'manuscript' || mb_strlen($q) < 2) {
            // Manuscripts have no useful text search here; they are picked by link or id
            $records = collect();
        } elseif ($type === 'library') {
            // Libraries are not in the search index; a few hundred rows, matched by name or city
            $records = $class::where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('full_name', 'like', "%{$q}%")->orWhere('aliases', 'like', "%{$q}%")->orWhere('city', 'like', "%{$q}%"))
                ->orderByDesc('manuscripts_count')->limit(10)->get();
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
        return $p->life_span;
    }
}
