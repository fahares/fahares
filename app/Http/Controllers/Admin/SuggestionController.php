<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Person;
use App\Models\ScholarlyAnnotation;
use App\Models\Manuscript;
use App\Suggestions\RecordMerger;
use App\Suggestions\SuggestionApplier;
use App\Support\HijriYear;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
        $suggestion->load(['user', 'reviewer', 'annotations']);
        $target = $suggestion->suggestable;
        $field = $suggestion->fieldDefinition();

        $people = ['candidates' => collect(), 'suggested' => null, 'current' => null];
        if ($field && $field['input'] === 'person') {
            $people['suggested'] = Person::find($suggestion->payload['person_id'] ?? null);
            $people['current'] = $target ? Person::find($target->{$field['id_column']}) : null;
            $people['candidates'] = $this->personCandidates($suggestion->suggested_value)
                ->reject(fn (Person $p) => in_array($p->id, [$people['suggested']?->id, $people['current']?->id], true));
        }

        $parsedYear = $field && $field['input'] === 'hijri_date' ? HijriYear::parse($suggestion->suggested_value) : null;

        $relation = $suggestion->relationDefinition();
        $relationTarget = $suggestion->relationTarget();

        return view('admin.suggestions.show', compact('suggestion', 'target', 'field', 'people', 'parsedYear', 'relation', 'relationTarget'));
    }

    public function approve(Request $request, FieldSuggestion $suggestion, SuggestionApplier $applier)
    {
        abort_unless($suggestion->status === 'pending', 409, 'این پیشنهاد پیش‌تر داوری شده است.');

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
            'create_annotation' => 'nullable|boolean',
            'admin_notes' => 'nullable|string|max:1000',
            'hijri_year' => 'nullable|integer|min:' . HijriYear::MIN . '|max:' . HijriYear::MAX,
            'person_action' => ['nullable', Rule::in(SuggestionApplier::PERSON_ACTIONS)],
            'person_id' => 'nullable|integer',
        ]);

        try {
            DB::transaction(function () use ($suggestion, $validated, $request, $field, $target, $applier) {
                $result = ['old' => [], 'new' => [], 'resolution' => ['applied' => false]];

                // General reports and retired fields are handled by hand; approving only records the decision
                if ($field && $target) {
                    $result = $applier->apply($target, $field, $validated);
                    $result['resolution']['applied'] = true;

                    if ($request->boolean('create_annotation', true)) {
                        ScholarlyAnnotation::create([
                            'annotatable_type' => $suggestion->suggestable_type,
                            'annotatable_id' => $suggestion->suggestable_id,
                            'field_name' => $suggestion->field_name,
                            'original_fankha_value' => $suggestion->current_value,
                            'corrected_value' => $validated['applied_value'] ?? '',
                            'revision_category' => $validated['revision_category'] ?? 'scholarly_correction',
                            'citation_source' => $suggestion->rationale_citation ?? '',
                            'contributor_name' => $suggestion->guest_name ?: $suggestion->user?->name,
                            'user_id' => auth()->id(),
                            'suggestion_id' => $suggestion->id,
                            'is_public' => true,
                        ]);
                    }
                }

                AuditLog::create([
                    'user_id' => auth()->id(),
                    'auditable_type' => $suggestion->suggestable_type,
                    'auditable_id' => $suggestion->suggestable_id,
                    'action' => 'verify',
                    'old_values' => $result['old'] ?: null,
                    'new_values' => ($result['new'] ?: []) + ['suggestion_id' => $suggestion->id],
                    'ip_address' => $request->ip(),
                ]);

                $suggestion->update([
                    'status' => 'approved',
                    'admin_notes' => $validated['admin_notes'] ?? null,
                    'resolution' => $result['resolution'],
                    'reviewed_by' => auth()->id(),
                    'reviewed_at' => now(),
                ]);
            });
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['applied_value' => $e->getMessage()]);
        }

        $message = $field
            ? "پیشنهاد اصلاحی #{$suggestion->id} تأیید و در شناسنامه‌ی رکورد اعمال شد."
            : "پیشنهاد #{$suggestion->id} به‌عنوان «رسیدگی‌شده» ثبت شد. تغییرات لازم را دستی اعمال کنید.";

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

    public function reject(Request $request, FieldSuggestion $suggestion)
    {
        $validated = $request->validate([
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        $suggestion->update([
            'status' => 'rejected',
            'admin_notes' => $validated['admin_notes'] ?? null,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return redirect()->route('admin.suggestions.index')
            ->with('info', "پیشنهاد اصلاحی #{$suggestion->id} رد شد.");
    }
}
