<?php

namespace App\Http\Controllers\Admin;

use App\Auth\Permissions;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\User;
use App\Suggestions\RelationInput;
use App\Suggestions\StructurePreview;
use App\Suggestions\SuggestionApplier;
use App\Suggestions\SuggestionRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The admin's structural tools without a suggestion (backlog item 45): merge, split, move, remove, relocate, link…
 * on one record, chosen from SuggestionRegistry::RELATIONS. The admin picks the tool, sees what it would change,
 * fills the reviewer's own approval form and confirms. Applying files the action as a relational suggestion in the
 * admin's name, approved at once through SuggestionController::approve, so the tool, its audit log entry, the
 * redirects, the counters, the public history («ویراستار فهارس», see Contributions) and the undo are all the
 * approval's own. Undoing it leaves the row «undone» instead of sending it to the review queue.
 *
 * Needs structure.direct, and for each tool the permission an approval of it needs (Permissions::FOR_APPLY).
 * What the removed record's own differing values become in a merge is decided in the comparison table of the
 * approval form (MergeComparison, backlog item 46).
 */
class StructureController extends Controller
{
    /** Pick the record: its type and a pasted link or id. */
    public function index(Request $request)
    {
        $type = SuggestionRegistry::typeKey((string) $request->query('type', ''));
        $ref = trim((string) $request->query('ref', ''));
        if ($type && $ref !== '') {
            $id = SuggestionController::recordId($type, $ref);
            if ($id && SuggestionRegistry::modelClass($type)::find($id)) {
                return redirect()->route('admin.structure.show', [$type, $id]);
            }

            return back()->withInput()->withErrors(['ref' => 'رکوردی با این پیوند یا شناسه پیدا نشد.']);
        }

        return view('admin.structure.index');
    }

    /** The record's tools; with a tool chosen, what it would change and the form that applies it. */
    public function show(Request $request, string $type, int $id)
    {
        $record = self::record($type, $id);
        $choices = self::choices($request->user(), $type);
        $draft = null;
        $preview = null;
        $draftErrors = [];
        if ($request->filled('relation')) {
            try {
                $draft = self::draft($request, $type, $record);
                $preview = StructurePreview::of($draft['relation'], $record, $draft['other']);
            } catch (ValidationException $e) {
                $draftErrors = $e->errors();
            }
        }

        return view('admin.structure.show', compact('type', 'record', 'choices', 'draft', 'preview', 'draftErrors'));
    }

    public function apply(Request $request, string $type, int $id)
    {
        $record = self::record($type, $id);
        ['suggestion' => $suggestion, 'relation' => $relation] = self::draft($request, $type, $record);
        abort_if(($relation['apply'] ?? null) === 'merge_people', 422, 'ادغام اشخاص از ابزار ادغام اشخاص انجام می‌شود.');

        $suggestion->save();
        try {
            $response = app(SuggestionController::class)->approve($request, $suggestion, app(SuggestionApplier::class));
        } catch (Throwable $e) {
            $suggestion->delete();
            throw $e;
        }
        if ($suggestion->fresh()?->status !== 'approved') {
            // The tool refused (e.g. the record still has copies); nothing was applied
            $suggestion->delete();

            return $response;
        }
        if ($log = AuditLog::activeForSuggestion($suggestion->id)) {
            $log->update(['new_values' => $log->new_values + ['direct' => true]]);
        }

        return redirect()->route('admin.suggestions.show', $suggestion)
            ->with('success', "«{$relation['action']}» مستقیم اعمال شد. از همین صفحه («بازگرداندن») یا از لاگ ممیزی بازگرداندنی است.");
    }

    private static function record(string $type, int $id): Model
    {
        return SuggestionRegistry::modelClass($type)::findOrFail($id);
    }

    /** The tools of a record type the user may run: those whose approval permission they hold. */
    public static function choices(User $user, string $type): array
    {
        return array_values(array_filter(SuggestionRegistry::relationChoices($type), fn (array $r) => self::allowed($user, $r)));
    }

    private static function allowed(User $user, array $relation): bool
    {
        $permission = Permissions::FOR_APPLY[$relation['apply'] ?? ''] ?? null;

        return ! $permission || $user->can($permission);
    }

    /**
     * The action as the relational suggestion it is filed as, unsaved, checked like any submitted one (RelationInput).
     *
     * @return array{suggestion: FieldSuggestion, relation: array, other: ?Model}
     */
    private static function draft(Request $request, string $type, Model $record): array
    {
        $input = $request->validate([
            'relation' => 'required|string|max:100',
            'target' => 'nullable|string|max:500',
            'suggested_value' => 'nullable|string|max:20000',
            'role' => 'nullable|string|max:30',
            'role_name' => 'nullable|string|max:600',
            'shelfmark' => 'nullable|string|max:255',
        ]);
        $relation = SuggestionRegistry::relation($type, $input['relation']);
        if ($relation && ! self::allowed($request->user(), $relation)) {
            abort(403, '«' . Permissions::label(Permissions::FOR_APPLY[$relation['apply']]) . '» برای نقش شما مجاز نیست.');
        }
        $ref = trim((string) ($input['target'] ?? ''));
        $targetId = $relation && $relation['target'] && $ref !== '' ? SuggestionController::recordId($relation['target'], $ref) : null;
        if ($relation && $relation['target'] && $ref !== '' && ! $targetId) {
            throw ValidationException::withMessages(['target' => 'پیوند یا شناسه‌ی رکورد مقصد خوانا نیست.']);
        }
        $value = trim(str_replace(["\r\n", "\r"], "\n", (string) ($input['suggested_value'] ?? '')));
        try {
            ['relation' => $relation, 'payload' => $payload] = RelationInput::validate($type, $record, ['field_name' => $input['relation'], 'target_id' => $targetId, 'suggested_value' => $value] + $input);
        } catch (ValidationException $e) {
            // The form names the other record «target»
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [$k === 'target_id' ? 'target' : $k => $m])->all());
        }

        $user = $request->user();
        $suggestion = new FieldSuggestion([
            'suggestable_type' => $record::class,
            'suggestable_id' => $record->id,
            'kind' => 'relation',
            'field_name' => $relation['key'],
            'suggested_value' => $value !== '' ? $value : $relation['label'],
            'payload' => $payload + ['direct' => true],
            'user_id' => $user->id,
            'guest_name' => $user->name,
            'guest_email' => $user->email,
            'notify_submitter' => false,
            'status' => 'pending',
        ]);
        $suggestion->setRelation('user', $user);
        $other = $relation['target'] && ! empty($payload['target_id']) ? SuggestionRegistry::modelClass($relation['target'])::find($payload['target_id']) : null;

        return ['suggestion' => $suggestion, 'relation' => $relation, 'other' => $other];
    }
}
