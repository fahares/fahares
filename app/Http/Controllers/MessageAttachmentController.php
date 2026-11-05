<?php

namespace App\Http\Controllers;

use App\Models\MessageAttachment;
use App\Models\MessageThread;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * A picture attached to an email reply (step 14.36). Only the thread's user and the staff who see the thread
 * (reviewers for a suggestion's thread, those who write direct messages for the others) may open it.
 */
class MessageAttachmentController extends Controller
{
    public function show(Request $request, MessageAttachment $attachment)
    {
        $thread = $attachment->message?->thread;
        $user = $request->user();
        $visible = $thread && ($user->id === $thread->user_id
            || $user->can($thread->kind === MessageThread::KIND_SUGGESTION ? 'suggestions.review' : 'users.message'));
        abort_unless($visible && Storage::disk('public')->exists($attachment->path), 404);

        return Storage::disk('public')->response($attachment->path, $attachment->filename, [
            'Content-Type' => $attachment->content_type,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
