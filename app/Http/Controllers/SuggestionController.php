<?php

namespace App\Http\Controllers;

use App\Models\FieldSuggestion;
use Illuminate\Http\Request;

class SuggestionController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'suggestable_type' => 'required|string|in:App\Models\Manuscript,App\Models\Work,App\Models\Person',
            'suggestable_id' => 'required|integer',
            'field_name' => 'required|string|max:100',
            'current_value' => 'nullable|string',
            'suggested_value' => 'required|string',
            'rationale_citation' => 'required|string|min:5',
            'guest_name' => 'nullable|string|max:100',
            'guest_email' => 'nullable|email|max:100',
        ]);

        $user = $request->user();

        FieldSuggestion::create([
            'suggestable_type' => $validated['suggestable_type'],
            'suggestable_id' => $validated['suggestable_id'],
            'field_name' => $validated['field_name'],
            'current_value' => $validated['current_value'] ?? null,
            'suggested_value' => $validated['suggested_value'],
            'rationale_citation' => $validated['rationale_citation'],
            'user_id' => $user?->id,
            'guest_name' => $user ? $user->name : ($validated['guest_name'] ?? 'پژوهشگر مهمان'),
            'guest_email' => $user ? $user->email : ($validated['guest_email'] ?? null),
            'status' => 'pending',
        ]);

        return back()->with('success', 'پیشنهاد اصلاحی شما با موفقیت ثبت شد و پس از بررسی تیم کتاب‌شناسی اعمال خواهد شد.');
    }
}
