<?php

namespace App\Http\Controllers;

use App\Messaging\Conversations;
use App\Models\Message;
use App\Models\MessageThread;
use Illuminate\Http\Request;

/**
 * A signed-in user's «پیام‌ها» (backlog item 91): reviewers' questions on their suggestions and the admin's
 * direct messages, each with its answers. A user reaches only threads that are their own.
 */
class MessagesController extends Controller
{
    public function index(Request $request)
    {
        $threads = MessageThread::where('user_id', $request->user()->id)
            ->with(['messages', 'suggestion'])
            ->orderByDesc('last_message_at')->paginate(20);

        return view('my.messages.index', compact('threads'));
    }

    public function show(Request $request, MessageThread $thread)
    {
        $this->own($request, $thread);
        $thread->load(['messages', 'suggestion.suggestable']);
        $thread->update(['user_read_at' => now()]);

        return view('my.messages.show', compact('thread'));
    }

    public function reply(Request $request, MessageThread $thread)
    {
        $this->own($request, $thread);
        abort_unless($thread->acceptsReplies(), 409, 'این گفت‌وگو بسته شده است.');
        $validated = $request->validate(['body' => 'required|string|max:' . Message::MAX_LENGTH]);

        Conversations::userReply($thread, $validated['body']);

        return redirect()->route('my.messages.show', $thread)->with('success', $thread->kind === MessageThread::KIND_SUGGESTION
            ? 'پاسخ شما فرستاده شد و پیشنهاد به صف داوری برگشت.'
            : 'پاسخ شما فرستاده شد.');
    }

    public function report(Request $request, MessageThread $thread)
    {
        $this->own($request, $thread);
        Conversations::report($thread);

        return back()->with('success', 'گزارش شما به مدیر کل فهارس رسید. ایمیل این نوع پیام را در تنظیمات اعلان هم می‌توانید خاموش کنید.');
    }

    /** Another user's thread is answered as missing, so its existence is not revealed */
    private function own(Request $request, MessageThread $thread): void
    {
        abort_unless($thread->user_id === $request->user()->id, 404);
    }
}
