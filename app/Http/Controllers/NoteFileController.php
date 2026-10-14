<?php

namespace App\Http\Controllers;

use App\Models\FieldSuggestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Images uploaded with a research note. Public once the note is published; before that,
 * only reviewers and the uploader may see them.
 */
class NoteFileController extends Controller
{
    public function show(Request $request, FieldSuggestion $suggestion, int $index)
    {
        $path = in_array($suggestion->kind, ['note', 'feedback'], true) ? ($suggestion->payload['files'][$index] ?? null) : null;
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        // Feedback screenshots are never public
        $user = $request->user();
        $visible = ($suggestion->kind === 'note' && $suggestion->status === 'approved') || $user?->canAccessAdmin() || ($user && $user->id === $suggestion->user_id);
        abort_unless($visible, 404);

        return Storage::disk('public')->response($path, null, [
            'Cache-Control' => $suggestion->kind === 'note' && $suggestion->status === 'approved' ? 'public, max-age=604800' : 'private, no-store',
        ]);
    }
}
