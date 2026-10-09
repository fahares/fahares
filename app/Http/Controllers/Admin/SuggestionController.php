<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\ScholarlyAnnotation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        return view('admin.suggestions.show', compact('suggestion', 'target'));
    }

    public function approve(Request $request, FieldSuggestion $suggestion)
    {
        $validated = $request->validate([
            'applied_value' => 'required|string',
            'revision_category' => 'required|string|in:scholarly_correction,parser_fix,ocr_fix',
            'create_annotation' => 'nullable|boolean',
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($suggestion, $validated, $request) {
            $target = $suggestion->suggestable;
            $fieldName = $suggestion->field_name;
            $appliedValue = $validated['applied_value'];
            $oldValue = $target ? ($target->{$fieldName} ?? $suggestion->current_value) : $suggestion->current_value;

            // 1. Update the underlying model if target exists
            if ($target) {
                $target->forceFill([
                    $fieldName => $appliedValue,
                ])->save();

                // Sync with Meilisearch if indexable
                if (method_exists($target, 'searchable')) {
                    $target->searchable();
                }
            }

            // 2. Insert into scholarly_annotations if requested
            if ($request->boolean('create_annotation', true)) {
                ScholarlyAnnotation::create([
                    'annotatable_type' => $suggestion->suggestable_type,
                    'annotatable_id' => $suggestion->suggestable_id,
                    'field_name' => $suggestion->field_name,
                    'original_fankha_value' => $suggestion->current_value,
                    'corrected_value' => $appliedValue,
                    'revision_category' => $validated['revision_category'],
                    'citation_source' => $suggestion->rationale_citation,
                    'contributor_name' => $suggestion->guest_name ?: $suggestion->user?->name,
                    'user_id' => auth()->id(),
                    'suggestion_id' => $suggestion->id,
                    'is_public' => true,
                ]);
            }

            // 3. Insert audit log
            AuditLog::create([
                'user_id' => auth()->id(),
                'auditable_type' => $suggestion->suggestable_type,
                'auditable_id' => $suggestion->suggestable_id,
                'action' => 'verify',
                'old_values' => [$fieldName => $oldValue],
                'new_values' => [$fieldName => $appliedValue],
                'ip_address' => $request->ip(),
            ]);

            // 4. Update the suggestion record
            $suggestion->update([
                'status' => 'approved',
                'admin_notes' => $validated['admin_notes'] ?? null,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);
        });

        return redirect()->route('admin.suggestions.index')
            ->with('success', "پیشنهاد اصلاحی #{$suggestion->id} با موفقیت تایید و در شناسنامه رکورد اعمال شد.");
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
