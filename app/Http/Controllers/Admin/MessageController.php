<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Messaging\Conversations;
use App\Models\AuditLog;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\MessageThread;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The admin's direct messages to users (backlog item 91), behind users.message: the threads and their answers,
 * writing to one user from a ready-made template, and editing the templates. Questions on suggestions live
 * on the suggestion's own page of the review desk.
 */
class MessageController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->query('filter');
        $threads = MessageThread::where('kind', MessageThread::KIND_DIRECT)
            ->with(['user', 'starter', 'messages'])
            ->when($filter === 'reported', fn ($q) => $q->whereNotNull('reported_at'))
            ->orderByDesc('last_message_at')->paginate(25)->withQueryString();

        return view('admin.messages.index', compact('threads', 'filter'));
    }

    public function show(MessageThread $thread)
    {
        abort_unless($thread->kind === MessageThread::KIND_DIRECT, 404);
        $thread->load(['user', 'messages.author']);
        $thread->update(['staff_read_at' => now()]);

        return view('admin.messages.show', compact('thread'));
    }

    public function create(Request $request)
    {
        $recipient = $request->query('user') ? User::findOrFail($request->query('user')) : null;
        $q = trim((string) $request->query('q'));
        // Found by name only: the page never shows users' addresses
        $matches = ! $recipient && mb_strlen($q) >= 2 ? User::where('name', 'like', '%' . $q . '%')->orderBy('name')->limit(20)->get(['id', 'name']) : collect();
        $templates = MessageTemplate::orderBy('sort')->get();
        $filled = $recipient ? $templates->mapWithKeys(fn ($t) => [$t->key => [
            'subject' => MessageTemplate::render($t->subject, $recipient),
            'body' => MessageTemplate::render($t->body, $recipient),
        ]]) : collect();

        return view('admin.messages.create', compact('recipient', 'q', 'matches', 'templates', 'filled'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:' . Message::MAX_LENGTH,
        ]);
        $thread = Conversations::sendDirect(User::findOrFail($validated['user_id']), $request->user(), $validated['subject'], $validated['body']);

        return redirect()->route('admin.messages.show', $thread)->with('success', 'پیام فرستاده شد.');
    }

    public function reply(Request $request, MessageThread $thread)
    {
        abort_unless($thread->kind === MessageThread::KIND_DIRECT && ! $thread->closed, 404);
        $validated = $request->validate(['body' => 'required|string|max:' . Message::MAX_LENGTH]);
        Conversations::staffWrite($thread, $request->user(), $validated['body']);

        return redirect()->route('admin.messages.show', $thread)->with('success', 'پیام فرستاده شد.');
    }

    /** Closed, the user can read it but not answer; opened again the same way */
    public function toggle(MessageThread $thread)
    {
        abort_unless($thread->kind === MessageThread::KIND_DIRECT, 404);
        $thread->update(['closed' => ! $thread->closed]);

        return back()->with('success', $thread->closed ? 'گفت‌وگو بسته شد.' : 'گفت‌وگو دوباره باز شد.');
    }

    public function templates()
    {
        return view('admin.messages.templates', ['templates' => MessageTemplate::orderBy('sort')->get()]);
    }

    public function updateTemplate(Request $request, MessageTemplate $template)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:100',
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:' . Message::MAX_LENGTH,
        ]);
        $old = $template->only(array_keys($validated));
        $template->update($validated);
        AuditLog::create([
            'user_id' => $request->user()->id,
            'auditable_type' => MessageTemplate::class,
            'auditable_id' => $template->id,
            'action' => 'message_template',
            'old_values' => $old,
            'new_values' => $validated,
            'ip_address' => $request->ip(),
        ]);

        return back()->with('success', "قالب «{$template->title}» ذخیره شد.");
    }
}
