<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Person;
use App\Models\PersonReview;
use App\Review\CollectiveReview;
use App\Review\MixedRecordQueue;
use App\Review\NamesakeQueue;
use App\Services\PersonMerger;
use App\Services\PersonReviewQueue;
use App\Suggestions\RecordSplitter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The admin queue «بازبینی اشخاص» (backlog item 78): namesake groups to merge or keep apart
 * (people.merge) and records that hold several people to split (structure.split).
 */
class PersonReviewController extends Controller
{
    public const VIEWS = [
        'open' => ['label' => 'در انتظار', 'statuses' => ['open']],
        'deferred' => ['label' => 'بعداً', 'statuses' => ['deferred']],
        'auto' => ['label' => 'جدا به داوری خودکار', 'statuses' => ['distinct']],
        'done' => ['label' => 'تصمیم‌گرفته', 'statuses' => ['merged', 'distinct', 'split', 'dismissed']],
    ];

    public function index(Request $request)
    {
        $type = $request->query('type') === 'mixed' ? 'mixed' : 'duplicate';
        Gate::authorize($this->permission($type));
        $view = array_key_exists($request->query('view'), self::VIEWS) ? $request->query('view') : 'open';

        $query = PersonReview::where('person_reviews.type', $type)->whereIn('person_reviews.status', self::VIEWS[$view]['statuses']);
        if ($view === 'auto') {
            $query->whereNull('decided_by');
        } elseif ($view === 'done') {
            $query->whereNotNull('decided_by');
        }
        // Items the researchers of «تصحیح جمعی» agree on come first (backlog item 80)
        $queue = $type === 'mixed' ? 'mixed' : 'namesake';
        if ($view === 'open') {
            $query->leftJoinSub(CollectiveReview::agreement($queue), 'agreement', 'agreement.review_item_id', '=', 'person_reviews.id')
                ->select('person_reviews.*')->orderByRaw('COALESCE(agreement.agreement, 0) DESC');
        }
        $reviews = $query->orderByRaw("person_reviews.tier = 'likely' desc")->orderBy('person_reviews.id')->paginate(15)->withQueryString();
        $opinions = CollectiveReview::opinionsOn($reviews->getCollection()->map(fn ($r) => [$queue, $r->id])->all(), true);

        $ids = $reviews->getCollection()->flatMap(fn($r) => $r->person_ids)->unique()->values()->all();
        $people = app(PersonReviewQueue::class)->profiles($ids, $type === 'mixed');

        $counts = PersonReview::selectRaw('type, status, decided_by IS NULL auto, COUNT(*) n')->groupBy('type', 'status', DB::raw('decided_by IS NULL'))->get();

        return view('admin.person-reviews.index', compact('type', 'view', 'reviews', 'people', 'counts', 'queue', 'opinions'));
    }

    public function merge(Request $request, PersonReview $review)
    {
        Gate::authorize('people.merge');
        abort_unless($review->type === 'duplicate', 404);
        $validated = $request->validate([
            'merge_ids' => 'required|array|min:2',
            'merge_ids.*' => 'integer',
            'target_id' => 'required|integer',
            'note' => 'nullable|string|max:2000',
        ], ['merge_ids.min' => 'دست‌کم دو رکورد را برای ادغام انتخاب کنید.']);

        $ids = array_values(array_intersect(array_map('intval', $validated['merge_ids']), $review->person_ids));
        $targetId = (int) $validated['target_id'];
        if (count($ids) < 2 || ! in_array($targetId, $ids, true)) {
            return back()->withErrors(['merge_ids' => 'رکورد اصلی باید یکی از رکوردهای انتخاب‌شده باشد.']);
        }
        $target = Person::find($targetId);
        $sources = Person::whereIn('id', array_diff($ids, [$targetId]))->get();
        if (! $target || $sources->count() !== count($ids) - 1) {
            return back()->withErrors(['merge_ids' => 'یکی از رکوردها دیگر وجود ندارد؛ صف را تازه کنید.']);
        }

        DB::transaction(function () use ($review, $target, $sources, $ids, $validated, $request) {
            foreach ($sources as $source) {
                app(PersonMerger::class)->merge($source, $target, auth()->id(), $request->ip(),
                    ['reason' => 'person review', 'review_id' => $review->id], keepPrintedNames: true);
            }
            $this->decide($review, 'merged', ['merge_ids' => $ids, 'target_id' => $target->id], $validated['note'] ?? null);
        });

        return back()->with('success', sprintf('%d رکورد در «%s» (#%d) ادغام شد.', $sources->count(), $target->name, $target->id));
    }

    public function split(Request $request, PersonReview $review)
    {
        Gate::authorize('structure.split');
        abort_unless($review->type === 'mixed', 404);
        $validated = $request->validate([
            'new_name' => 'required|string|max:600',
            'death_year_hijri' => 'nullable|integer|min:1|max:1500',
            'manuscript_ids' => 'required|array|min:1',
            'manuscript_ids.*' => 'integer',
            'note' => 'nullable|string|max:2000',
        ], ['manuscript_ids.required' => 'نسخه‌هایی را که مال نفر دیگرند علامت بزنید.', 'new_name.required' => 'نام شخص تازه را بنویسید.']);

        $person = Person::find($review->person_ids[0] ?? 0);
        if (! $person) {
            return back()->withErrors(['new_name' => 'این شخص دیگر وجود ندارد.']);
        }

        try {
            $result = DB::transaction(function () use ($review, $person, $validated, $request) {
                $result = app(RecordSplitter::class)->splitPerson($person,
                    ['name' => $validated['new_name'], 'death_year_hijri' => $validated['death_year_hijri'] ?? null],
                    ['manuscripts' => $validated['manuscript_ids']]);
                AuditLog::create([
                    'user_id' => auth()->id(),
                    'auditable_type' => Person::class,
                    'auditable_id' => $person->id,
                    'action' => 'split',
                    'old_values' => $result,
                    'new_values' => ['created_id' => $result['created_id'], 'review_id' => $review->id],
                    'ip_address' => $request->ip(),
                ]);
                $this->decide($review, 'split', ['created_id' => $result['created_id'], 'manuscript_ids' => $result['manuscript_ids']], $validated['note'] ?? null);

                return $result;
            });
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['new_name' => $e->getMessage()]);
        }

        return back()->with('success', sprintf('%d نسخه به شخص تازه‌ی «%s» (#%d) منتقل شد.', count($result['manuscript_ids']), $validated['new_name'], $result['created_id']));
    }

    /** distinct (namesakes, keep apart), dismissed (one person after all), deferred, or reopen */
    public function status(Request $request, PersonReview $review)
    {
        Gate::authorize($this->permission($review->type));
        $validated = $request->validate([
            'status' => 'required|in:distinct,dismissed,deferred,open',
            'note' => 'nullable|string|max:2000',
        ]);
        if ($validated['status'] === 'distinct' && $review->type !== 'duplicate' || $validated['status'] === 'dismissed' && $review->type !== 'mixed') {
            abort(422);
        }

        $this->decide($review, $validated['status'], null, $validated['note'] ?? null);

        return back()->with('success', 'وضعیت «' . PersonReview::STATUSES[$validated['status']] . '» ثبت شد.');
    }

    protected function decide(PersonReview $review, string $status, ?array $decision, ?string $note): void
    {
        $review->update([
            'status' => $status,
            'decision' => $decision,
            'note' => trim((string) $note) ?: $review->note,
            'decided_by' => $status === 'open' ? null : auth()->id(),
            'decided_at' => $status === 'open' ? null : now(),
        ]);

        // The researchers' opinions on the item in «تصحیح جمعی» (backlog item 80)
        [$queue, $answer] = $review->type === 'mixed'
            ? ['mixed', MixedRecordQueue::decision($review)]
            : ['namesake', NamesakeQueue::decision($review)];
        if ($answer) {
            CollectiveReview::settle($queue, $review->id, $answer, auth()->user());
        }
    }

    protected function permission(string $type): string
    {
        return $type === 'mixed' ? 'structure.split' : 'people.merge';
    }
}
