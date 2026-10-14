<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Person;
use App\Models\Manuscript;
use App\Suggestions\RecordMerger;
use App\Suggestions\SuggestionApplier;
use App\Suggestions\SuggestionRegistry;
use App\Support\HijriYear;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SuggestionController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'pending');
        $type = $request->query('type');

        $query = FieldSuggestion::with(['user', 'reviewer']);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($type) {
            $query->where('suggestable_type', 'like', "%{$type}%");
        }

        $suggestions = $query->latest('id')->paginate(20)->withQueryString();

        $stats = [
            'pending' => FieldSuggestion::where('status', 'pending')->count(),
            'approved' => FieldSuggestion::where('status', 'approved')->count(),
            'rejected' => FieldSuggestion::where('status', 'rejected')->count(),
            'total' => FieldSuggestion::count(),
        ];

        return view('admin.suggestions.index', compact('suggestions', 'stats', 'status', 'type'));
    }

    public function show(FieldSuggestion $suggestion)
    {
        $suggestion->load(['user', 'reviewer']);
        $target = $suggestion->suggestable;
        $field = $suggestion->fieldDefinition();

        $people = ['candidates' => collect(), 'suggested' => null, 'current' => null];
        if ($field && $field['input'] === 'person') {
            $people['suggested'] = Person::find($suggestion->payload['person_id'] ?? null);
            $people['current'] = $target ? Person::find($target->{$field['id_column']}) : null;
            $people['candidates'] = $this->personCandidates($suggestion->suggested_value)
                ->reject(fn (Person $p) => in_array($p->id, [$people['suggested']?->id, $people['current']?->id], true));
        }

        $parsedYear = $field && $field['input'] === 'hijri_date' ? HijriYear::parse($suggestion->applied_value ?? $suggestion->suggested_value) : null;

        // The adopted reading an approval here would replace
        $previousAdopted = $field && $suggestion->standing !== 'adopted'
            ? FieldSuggestion::adoptedFor($suggestion->suggestable_type, $suggestion->suggestable_id, $suggestion->field_name)->whereKeyNot($suggestion->id)->first()
            : null;

        $relation = $suggestion->relationDefinition();
        $relationTarget = $suggestion->relationTarget();

        return view('admin.suggestions.show', compact('suggestion', 'target', 'field', 'people', 'parsedYear', 'relation', 'relationTarget', 'previousAdopted'));
    }

    /** Reviewer outcomes for a field suggestion and the standing each gives it. */
    public const OUTCOMES = [
        'adopt' => 'adopted',
        'alternative' => 'alternative',
        'ocr_fix' => 'edit',
        'parser_fix' => 'edit',
    ];

    public function approve(Request $request, FieldSuggestion $suggestion, SuggestionApplier $applier)
    {
        // An alternative reading can later be adopted; anything else is reviewed once
        $promoting = $suggestion->status === 'approved' && $suggestion->standing === 'alternative';
        abort_unless($suggestion->status === 'pending' || $promoting, 409, 'این پیشنهاد پیش‌تر داوری شده است.');

        if ($suggestion->kind === 'relation') {
            return $this->approveRelation($request, $suggestion, app(RecordMerger::class));
        }

        $field = $suggestion->fieldDefinition();
        $target = $suggestion->suggestable;

        // The reviewer form offers one radio group: "p:<id>", "manual" (typed id), "create" or "text"
        $pick = (string) $request->input('person_pick', '');
        if ($pick !== '') {
            $request->merge(match (true) {
                str_starts_with($pick, 'p:') => ['person_action' => 'link', 'person_id' => (int) substr($pick, 2)],
                $pick === 'manual' => ['person_action' => 'link', 'person_id' => (int) $request->input('person_manual_id')],
                default => ['person_action' => $pick],
            });
        }

        $validated = $request->validate([
            'applied_value' => 'nullable|string',
            'revision_category' => 'nullable|string|in:scholarly_correction,parser_fix,ocr_fix',
            'admin_notes' => 'nullable|string|max:1000',
            'hijri_year' => 'nullable|integer|min:' . HijriYear::MIN . '|max:' . HijriYear::MAX,
            'person_action' => ['nullable', Rule::in(SuggestionApplier::PERSON_ACTIONS)],
            'person_id' => 'nullable|integer',
            'outcome' => ['nullable', Rule::in(array_keys(self::OUTCOMES))],
            'previous_standing' => ['nullable', Rule::in(['alternative', 'superseded'])],
            'public_note' => 'nullable|string|max:2000',
        ]);

        // Older forms send only the category: a scholarly correction is adopted, the others are edits
        $outcome = $promoting ? 'adopt' : ($validated['outcome'] ?? match ($validated['revision_category'] ?? null) {
            'ocr_fix', 'parser_fix' => $validated['revision_category'],
            default => 'adopt',
        });
        $standing = self::OUTCOMES[$outcome];
        $publicNote = trim($validated['public_note'] ?? '') ?: null;

        $previous = $field && $target && $standing === 'adopted'
            ? FieldSuggestion::adoptedFor($suggestion->suggestable_type, $suggestion->suggestable_id, $suggestion->field_name)->whereKeyNot($suggestion->id)->first()
            : null;
        if ($previous && empty($validated['previous_standing'])) {
            return back()->withInput()->withErrors(['previous_standing' => 'وضعیت قول مختار پیشین را تعیین کنید: «قول دیگر» یا «منسوخ».']);
        }
        if ($field && ($standing === 'alternative' || $previous) && ! $publicNote) {
            return back()->withInput()->withErrors(['public_note' => $standing === 'alternative'
                ? 'برای «قول دیگر» یادداشت عمومی بنویسید: چرا این قول ترجیح داده نشد.'
                : 'یادداشت عمومی بنویسید: چرا این قول بر قول مختار پیشین ترجیح داده شد.']);
        }

        try {
            DB::transaction(function () use ($suggestion, $validated, $request, $field, $target, $applier, $standing, $publicNote, $previous, $promoting) {
                $result = ['old' => [], 'new' => [], 'resolution' => ['applied' => false]];
                // A rollback returns a promoted alternative to its old standing, not to the queue
                $logExtra = $promoting ? ['promoted' => true] : [];
                $appliedValue = $field ? (trim(str_replace(["\r\n", "\r"], "\n", (string) ($validated['applied_value'] ?? ''))) ?: null) : null;

                // General reports and retired fields are handled by hand; approving only records the decision.
                // An alternative reading is kept with its evidence and leaves the record as it is.
                if ($field && $target && $standing !== 'alternative') {
                    $result = $applier->apply($target, $field, $validated);
                    $result['resolution']['applied'] = true;

                    if ($previous) {
                        $logExtra['demoted'] = ['id' => $previous->id, 'standing' => 'adopted'];
                        $previous->update(['standing' => $validated['previous_standing'], 'superseded_by' => $suggestion->id]);
                    }
                    // A typo fixed in an adopted reading's value is fixed in the reading too
                    if ($standing === 'edit') {
                        $logExtra += self::editAdoptedReading($suggestion->suggestable_type, $suggestion->suggestable_id, $suggestion->field_name, $appliedValue);
                    }

                }

                AuditLog::create([
                    'user_id' => auth()->id(),
                    'auditable_type' => $suggestion->suggestable_type,
                    'auditable_id' => $suggestion->suggestable_id,
                    'action' => 'verify',
                    'old_values' => $result['old'] ?: null,
                    'new_values' => ($result['new'] ?: []) + $logExtra + ['suggestion_id' => $suggestion->id],
                    'ip_address' => $request->ip(),
                ]);

                $suggestion->update([
                    'status' => 'approved',
                    'standing' => $field ? $standing : null,
                    'applied_value' => $appliedValue,
                    'superseded_by' => null,
                    // Promoting an alternative keeps its earlier notes unless the reviewer writes new ones
                    'admin_notes' => ($validated['admin_notes'] ?? null) ?: ($suggestion->standing ? $suggestion->admin_notes : null),
                    'public_note' => $publicNote ?? ($suggestion->standing ? $suggestion->public_note : null),
                    'resolution' => $result['resolution'],
                    'reviewed_by' => auth()->id(),
                    'reviewed_at' => now(),
                ]);
            });
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['applied_value' => $e->getMessage()]);
        }

        $message = match (true) {
            ! $field => "پیشنهاد #{$suggestion->id} به‌عنوان «رسیدگی‌شده» ثبت شد. تغییرات لازم را دستی اعمال کنید.",
            $standing === 'alternative' => "پیشنهاد #{$suggestion->id} به‌عنوان «قول دیگر» با مستندش ثبت شد. مقدار رکورد تغییر نکرد.",
            $standing === 'edit' => "ویرایش #{$suggestion->id} در شناسنامه‌ی رکورد اعمال شد.",
            default => "پیشنهاد اصلاحی #{$suggestion->id} به‌عنوان قول مختار تأیید و در شناسنامه‌ی رکورد اعمال شد.",
        };

        return redirect()->route('admin.suggestions.index')->with('success', $message);
    }

    /**
     * Moves or merges the records a relational suggestion names. Person duplicates go through
     * the people merge tool instead (see PersonController::merge), which closes the suggestion.
     */
    private function approveRelation(Request $request, FieldSuggestion $suggestion, RecordMerger $merger)
    {
        $validated = $request->validate([
            'keep' => 'nullable|in:target,source',
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        $relation = $suggestion->relationDefinition();
        $record = $suggestion->suggestable;
        $other = $suggestion->relationTarget();
        if (! $relation || ! $record || ! $other) {
            return back()->withErrors(['applied_value' => 'یکی از دو رکورد این پیشنهاد دیگر وجود ندارد.']);
        }
        abort_if($relation['target'] === 'person', 422, 'ادغام اشخاص از ابزار ادغام اشخاص انجام می‌شود.');

        try {
            DB::transaction(function () use ($suggestion, $relation, $record, $other, $merger, $validated, $request) {
                if ($relation['key'] === 'move_to_work') {
                    $result = $merger->moveManuscript($record, $other);
                    $log = ['action' => 'verify', 'type' => $record::class, 'id' => $record->id, 'old' => $result['old'], 'new' => $result['new']];
                    $resolution = ['applied' => true] + $result['new'];
                } else {
                    // The reviewer may keep either record; by default the one the suggestion points at
                    [$source, $kept] = ($validated['keep'] ?? 'target') === 'source' ? [$other, $record] : [$record, $other];
                    $result = $source instanceof Manuscript ? $merger->mergeManuscript($source, $kept) : $merger->mergeWork($source, $kept);
                    $log = [
                        'action' => 'merge', 'type' => $kept::class, 'id' => $kept->id,
                        'old' => ['source_id' => $source->id] + $result,
                        'new' => ['target_id' => $kept->id],
                    ];
                    $resolution = ['applied' => true, 'kept_id' => $kept->id, 'removed_id' => $source->id];
                }

                AuditLog::create([
                    'user_id' => auth()->id(),
                    'auditable_type' => $log['type'],
                    'auditable_id' => $log['id'],
                    'action' => $log['action'],
                    'old_values' => $log['old'],
                    'new_values' => $log['new'] + ['suggestion_id' => $suggestion->id],
                    'ip_address' => $request->ip(),
                ]);

                $suggestion->update([
                    'status' => 'approved',
                    'admin_notes' => $validated['admin_notes'] ?? null,
                    'resolution' => $resolution,
                    'reviewed_by' => auth()->id(),
                    'reviewed_at' => now(),
                ]);
            });
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['applied_value' => $e->getMessage()]);
        }

        return redirect()->route('admin.suggestions.index')
            ->with('success', "پیشنهاد #{$suggestion->id} ({$relation['action']}) تأیید و اعمال شد.");
    }

    /** People whose name matches the suggested scribe/author, best match first. */
    private function personCandidates(string $name)
    {
        try {
            return Person::search($name)->take(8)->get();
        } catch (\Throwable) {
            return Person::where('name', 'like', '%' . $name . '%')->orderByDesc('manuscripts_count')->limit(8)->get();
        }
    }

    /**
     * Keeps an adopted reading's value in step with a typo fix to the same field.
     * Returns what the audit log needs to undo it.
     */
    public static function editAdoptedReading(string $type, int $id, string $field, ?string $value): array
    {
        $adopted = FieldSuggestion::adoptedFor($type, $id, $field)->first();
        if (! $adopted || $adopted->applied_value === $value) {
            return [];
        }
        $before = $adopted->applied_value;
        $adopted->update(['applied_value' => $value]);

        return ['edited_reading' => ['id' => $adopted->id, 'applied_value' => $before]];
    }

    /**
     * A reviewer's own typo fix from the record page: applied at once, logged for rollback,
     * and not a suggestion or a reading.
     */
    public function directEdit(Request $request, SuggestionApplier $applier)
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(array_keys(SuggestionRegistry::TYPES))],
            'id' => 'required|integer',
            'field_name' => 'required|string|max:100',
            'suggested_value' => 'nullable|string|max:20000',
            'person_id' => 'nullable|integer|exists:people,id',
        ]);

        $record = SuggestionRegistry::modelClass($validated['type'])::find($validated['id']);
        $field = SuggestionRegistry::field($validated['type'], $validated['field_name']);
        if (! $record || ! $field) {
            throw ValidationException::withMessages(['field_name' => 'رکورد یا فیلد پیدا نشد.']);
        }

        $value = trim(str_replace(["\r\n", "\r"], "\n", (string) ($validated['suggested_value'] ?? ''))) ?: null;
        $input = ['applied_value' => $value];
        if ($field['input'] === 'hijri_date') {
            $input['hijri_year'] = HijriYear::parse($value);
        }
        if ($field['input'] === 'person') {
            // A picked person is linked; otherwise only the name text changes and the link stays
            $input += ! empty($validated['person_id']) ? ['person_action' => 'link', 'person_id' => $validated['person_id']] : ['person_action' => 'text'];
        }

        try {
            DB::transaction(function () use ($record, $field, $input, $value, $applier, $request) {
                $result = $applier->apply($record, $field, $input);
                $extra = self::editAdoptedReading($record::class, $record->id, $field['name'], $value);
                AuditLog::create([
                    'user_id' => auth()->id(),
                    'auditable_type' => $record::class,
                    'auditable_id' => $record->id,
                    'action' => 'verify',
                    'old_values' => $result['old'] ?: null,
                    'new_values' => ($result['new'] ?: []) + $extra + ['direct_edit' => true],
                    'ip_address' => $request->ip(),
                ]);
            });
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['suggested_value' => $e->getMessage()]);
        }

        return response()->json(['message' => 'ویرایش اعمال شد.']);
    }

    /** Sets or clears the public credit, e.g. once the submitter has agreed by email. */
    public function credit(Request $request, FieldSuggestion $suggestion)
    {
        $validated = $request->validate(['credit_name' => 'nullable|string|max:150']);
        $name = trim($validated['credit_name'] ?? '') ?: null;
        $suggestion->update(['credit_name' => $name]);

        return back()->with('success', $name ? "نام «{$name}» برای پیشنهاد #{$suggestion->id} ثبت شد." : "نام پیشنهاد #{$suggestion->id} از نمایش عمومی برداشته شد.");
    }

    public function reject(Request $request, FieldSuggestion $suggestion)
    {
        $validated = $request->validate([
            'admin_notes' => 'nullable|string|max:1000',
            'public_note' => 'nullable|string|max:2000',
        ]);

        $suggestion->update([
            'status' => 'rejected',
            'admin_notes' => $validated['admin_notes'] ?? null,
            'public_note' => trim($validated['public_note'] ?? '') ?: null,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return redirect()->route('admin.suggestions.index')
            ->with('info', "پیشنهاد اصلاحی #{$suggestion->id} رد شد.");
    }
}
