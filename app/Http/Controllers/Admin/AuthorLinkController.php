<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Person;
use App\Models\PersonReview;
use App\Models\Work;
use App\Review\AuthorLinkQueue;
use App\Review\CollectiveReview;
use App\Suggestions\ContributorRelations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The panel's «پیوند پدیدآور» (step 14.17): works whose author is a name only, each with the one person of the site
 * it may be (PersonReview type author_link). «پیوند بده» makes that person the work's author (ContributorRelations::set);
 * «شخص دیگری است» keeps the name as text. Either settles the opinions of «تصحیح جمعی» on the item.
 */
class AuthorLinkController extends Controller
{
    public function index(Request $request)
    {
        $done = $request->query('view') === 'done';
        $reviews = PersonReview::where('person_reviews.type', 'author_link')
            ->whereIn('person_reviews.status', $done ? ['linked', 'not_linked'] : ['open', 'deferred'])
            ->leftJoinSub(CollectiveReview::agreement('author'), 'agreement', 'agreement.review_item_id', '=', 'person_reviews.id')
            ->select('person_reviews.*')->orderByRaw('COALESCE(agreement.agreement, 0) DESC')->orderBy('person_reviews.id')
            ->paginate(25)->withQueryString();
        $queue = app(AuthorLinkQueue::class);
        $cards = $reviews->getCollection()->mapWithKeys(fn ($r) => [$r->id => $queue->card($r)]);
        $opinions = CollectiveReview::opinionsOn($reviews->getCollection()->map(fn ($r) => ['author', $r->id])->all(), true);
        $counts = PersonReview::where('type', 'author_link')->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return view('admin.author-links.index', compact('reviews', 'cards', 'opinions', 'counts', 'done'));
    }

    public function link(Request $request, PersonReview $review, ContributorRelations $relations)
    {
        abort_unless($review->type === 'author_link' && $review->isOpen(), 404);
        $work = Work::find($review->suggestion['work_id'] ?? 0);
        $person = Person::find($review->person_ids[0] ?? 0);
        if (! $work || ! $person) {
            return back()->withErrors(['link' => 'اثر یا شخص دیگر وجود ندارد.']);
        }
        try {
            DB::transaction(function () use ($review, $work, $person, $relations, $request) {
                $change = $relations->set($work, $person);
                AuditLog::create(['user_id' => auth()->id(), 'auditable_type' => Work::class, 'auditable_id' => $work->id,
                    'action' => 'update', 'old_values' => $change['old'] ?? null,
                    'new_values' => ['author_id' => $person->id, 'review_id' => $review->id], 'ip_address' => $request->ip()]);
                $this->decide($review, 'linked', ['work_id' => $work->id, 'person_id' => $person->id]);
            });
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['link' => $e->getMessage()]);
        }

        return back()->with('success', "«{$work->primary_title}» به {$person->name} پیوند خورد.");
    }

    public function reject(Request $request, PersonReview $review)
    {
        abort_unless($review->type === 'author_link' && $review->isOpen(), 404);
        $this->decide($review, 'not_linked', null);

        return back()->with('success', 'ثبت شد: شخص دیگری است؛ نام پدیدآور همان‌طور که هست می‌ماند.');
    }

    private function decide(PersonReview $review, string $status, ?array $decision): void
    {
        $review->update(['status' => $status, 'decision' => $decision, 'decided_by' => auth()->id(), 'decided_at' => now()]);
        CollectiveReview::settle('author', $review->id, AuthorLinkQueue::decision($review), auth()->user());
    }
}
