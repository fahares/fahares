<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Suggestions\Contributions;
use App\Suggestions\SuggestionRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * «اصلاحات پژوهشگران» (backlog item 63): the approved corrections of the whole site, newest first, and
 * the page of each researcher who let their name be shown. What is public is decided by Contributions.
 */
class ContributionsController extends Controller
{
    public const PERIODS = ['30' => '۳۰ روز اخیر', '365' => 'یک سال اخیر'];

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $query = $this->filtered(Contributions::publicQuery(), $filters);
        if ($filters['researcher']) {
            $query->where('user_id', $filters['researcher'])->whereNotNull('credit_name')->where('credit_name', '!=', '');
        }

        $all = Contributions::publicQuery();
        $stats = [
            'total' => (clone $all)->count(),
            'contributors' => (clone $all)->whereNotNull('user_id')->distinct()->count('user_id'),
            'records' => (clone $all)->distinct()->count(DB::raw("CONCAT(suggestable_type, ':', suggestable_id)")),
            'recent' => (clone $all)->where('reviewed_at', '>=', now()->subDays(30))->count(),
        ];

        return view('contributions.index', [
            'items' => $this->page($query),
            'stats' => $stats,
            'researchers' => $this->researchers(),
            'filters' => $filters,
            'researcher' => null,
        ]);
    }

    public function show(Request $request, User $user)
    {
        $credited = Contributions::creditedTo($user);
        abort_unless((clone $credited)->exists(), 404);

        $filters = $this->filters($request);
        $latest = (clone $credited)->latest('reviewed_at')->first();

        return view('contributions.index', [
            'items' => $this->page($this->filtered(clone $credited, $filters)),
            'stats' => null,
            'researchers' => collect(),
            'filters' => $filters,
            'researcher' => [
                'user' => $user,
                'name' => $latest->credit_name,
                'count' => (clone $credited)->count(),
                'records' => (clone $credited)->distinct()->count(DB::raw("CONCAT(suggestable_type, ':', suggestable_id)")),
                'first' => (clone $credited)->min('reviewed_at'),
            ],
        ]);
    }

    private function filters(Request $request): array
    {
        return [
            'researcher' => $request->integer('researcher') ?: null,
            'type' => array_key_exists($request->query('type'), SuggestionRegistry::TYPES) ? $request->query('type') : null,
            'kind' => array_key_exists($request->query('kind'), Contributions::KINDS) ? $request->query('kind') : null,
            'period' => array_key_exists($request->query('period'), self::PERIODS) ? $request->query('period') : null,
        ];
    }

    private function filtered(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['type'], fn ($q, $type) => $q->where('suggestable_type', SuggestionRegistry::TYPES[$type]))
            ->when($filters['kind'], fn ($q, $kind) => $kind === 'note'
                ? $q->whereIn('kind', ['note', 'general'])
                : $q->where('kind', $kind))
            ->when($filters['period'], fn ($q, $days) => $q->where('reviewed_at', '>=', now()->subDays((int) $days)));
    }

    private function page(Builder $query)
    {
        $page = $query->with(['user', 'suggestable'])->orderByDesc('reviewed_at')->orderByDesc('id')->paginate(30)->withQueryString();
        $page->setCollection(Contributions::summarize($page->getCollection()));

        return $page;
    }

    /** Researchers shown by name, most corrections first: the thanks strip and the filter. */
    private function researchers()
    {
        $rows = Contributions::publicQuery()
            ->whereNotNull('user_id')->whereNotNull('credit_name')->where('credit_name', '!=', '')
            ->selectRaw('user_id, COUNT(*) AS total, MAX(reviewed_at) AS last_at')
            ->groupBy('user_id')
            ->orderByDesc('total')->orderByDesc('last_at')
            ->get();
        $users = User::whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');
        // The name each researcher chose most recently
        $names = Contributions::publicQuery()->whereIn('user_id', $rows->pluck('user_id'))
            ->whereNotNull('credit_name')->where('credit_name', '!=', '')
            ->orderBy('reviewed_at')->orderBy('id')->pluck('credit_name', 'user_id');

        return $rows->filter(fn ($r) => $users->has($r->user_id))->map(fn ($r) => [
            'user' => $users[$r->user_id],
            'name' => $names[$r->user_id] ?? '',
            'count' => (int) $r->total,
        ])->values();
    }
}
