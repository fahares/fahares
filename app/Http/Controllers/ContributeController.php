<?php

namespace App\Http\Controllers;

use App\Models\FieldSuggestion;
use App\Models\User;
use App\Review\CollectiveReview;
use App\Review\ReviewQueue;
use Illuminate\Http\Request;

/**
 * «تصحیح جمعی» (backlog item 80): one card per item of a review queue, a clear question, the evidence, a few answers,
 * then the next item. Anyone may look; an answer needs an account and is filed as a pending suggestion that only a
 * reviewer's decision turns into a change. The researcher's skipped items are kept in the session.
 */
class ContributeController extends Controller
{
    /** Skipped items remembered per queue, at most this many. */
    private const SKIPPED = 300;

    public function index()
    {
        return redirect()->to(route('contributions.index') . '#review');
    }

    public function next(Request $request, string $queue)
    {
        $queue = $this->queue($queue);
        $filters = $queue->filters($request->query());
        $item = CollectiveReview::next($queue, $request->user(), $filters, $this->skipped($request, $queue));

        if (! $item) {
            return view('contribute.done', $this->frame($request, $queue, $filters));
        }

        return redirect()->route('contribute.show', [$queue->key(), $item->getKey()] + $filters);
    }

    public function show(Request $request, string $queue, int $item)
    {
        $queue = $this->queue($queue);
        $filters = $queue->filters($request->query());
        $open = $queue->find($item);
        $user = $request->user();

        return view('contribute.show', $this->frame($request, $queue, $filters) + [
            'item' => $open,
            'itemId' => $item,
            'card' => $open ? $queue->card($open) : null,
            'opinion' => $user ? CollectiveReview::opinionOf($user, $queue->key(), $item) : null,
        ]);
    }

    public function answer(Request $request, string $queue, int $item)
    {
        $queue = $this->queue($queue);
        $filters = $queue->filters($request->input('filters', []));
        $open = $queue->find($item);
        if (! $open) {
            return redirect()->route('contribute.next', [$queue->key()] + $filters)
                ->with('info', 'داوران درباره‌ی این مورد تصمیم گرفته‌اند. مورد دیگری آمد.');
        }

        $validated = $request->validate([
            'answer' => 'required|string|max:20',
            'page' => 'nullable|string|max:50',
            'note' => 'nullable|string|max:2000',
            'credit' => 'nullable|boolean',
        ], ['answer.required' => 'یکی از پاسخ‌ها را برگزینید.']);
        $request->session()->put('contribute.credit', $request->boolean('credit'));

        $user = $request->user();
        $replaced = CollectiveReview::opinionOf($user, $queue->key(), $item)?->status === 'pending';
        CollectiveReview::answer($queue, $open, $user, $validated['answer'], $validated + ['credit_name' => $request->boolean('credit') ? $this->creditName($user) : null]);

        return redirect()->route('contribute.next', [$queue->key()] + $filters)
            ->with('success', $replaced ? 'پاسخ تازه‌ی شما جای پاسخ پیشین نشست. سپاس.' : 'پاسخ شما ثبت شد و به داوران رسید. سپاس.');
    }

    public function skip(Request $request, string $queue, int $item)
    {
        $queue = $this->queue($queue);
        $key = 'contribute.skipped.' . $queue->key();
        $skipped = array_slice(array_values(array_unique([...$this->skipped($request, $queue), $item])), -self::SKIPPED);
        $request->session()->put($key, $skipped);

        return redirect()->route('contribute.next', [$queue->key()] + $queue->filters($request->input('filters', [])));
    }

    public function signIn(Request $request, string $queue, int $item)
    {
        return redirect()->route('contribute.show', [$this->queue($queue)->key(), $item]);
    }

    private function queue(string $key): ReviewQueue
    {
        return CollectiveReview::queue($key) ?? abort(404);
    }

    private function skipped(Request $request, ReviewQueue $queue): array
    {
        return (array) $request->session()->get('contribute.skipped.' . $queue->key(), []);
    }

    /** The name a researcher chose last time beside a correction, or their account's name. */
    private function creditName(User $user): string
    {
        return FieldSuggestion::where('user_id', $user->id)->whereNotNull('credit_name')->where('credit_name', '!=', '')
            ->latest('id')->value('credit_name') ?: $user->name;
    }

    /** What every card page shows around the card: the queue, its counts, the filters and the researcher's share. */
    private function frame(Request $request, ReviewQueue $queue, array $filters): array
    {
        $user = $request->user();

        return [
            'queue' => $queue,
            'filters' => $filters,
            'filterOptions' => $queue->filterOptions(),
            'waiting' => CollectiveReview::waiting($queue, $filters)->count(),
            'mine' => $user ? CollectiveReview::contributionsOf($user) : null,
            'credit' => (bool) $request->session()->get('contribute.credit', $user && FieldSuggestion::where('user_id', $user->id)->whereNotNull('credit_name')->where('credit_name', '!=', '')->exists()),
            'creditName' => $user ? $this->creditName($user) : null,
        ];
    }
}
