<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\Source;
use App\Models\SourceEntry as Entry;
use App\Models\Work;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Review queue of the lives in the books of lives tied to people by machine (step 14.230): is this entry the life of
 * this person? Each row sets the person's name, dates and works beside the entry's heading and opening. A tie is
 * verified or rejected through the routes of the catalogue references, which serve every machine match.
 */
class PersonLifeController extends Controller
{
    /** The machine's methods in plain words. */
    public const METHODS = [
        'via_works' => 'از راه آثار شخص',
        'name_death_llm' => 'نام و سال وفات، داوری مدل',
        'name_llm' => 'نام و قرینه‌های متن، داوری مدل',
    ];

    /** Works of the person shown beside the entry. */
    private const WORKS = 8;

    public function index(Request $request)
    {
        $status = array_key_exists($request->query('status'), CatalogReferenceController::STATUSES) ? $request->query('status') : Entry::STATUS_UNVERIFIED;
        $filters = array_filter([
            'source' => Source::where('code', (string) $request->query('source'))->first(),
            'method' => array_key_exists($request->query('method'), self::METHODS) ? $request->query('method') : null,
        ]);
        $query = array_filter(['source' => ($filters['source'] ?? null)?->code, 'method' => $filters['method'] ?? null]);

        $sources = Source::whereIn('id', $this->ties()->select('source_id'))->orderBy('title')->get();
        $counts = $this->filtered($filters)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');
        // the least sure first
        $lives = $this->filtered($filters)->where('status', $status)->with(['citable', 'source', 'sourceItem', 'reviewer'])
            ->orderBy('confidence')->orderBy('source_id')->orderBy('volume_number')->orderBy('page_start')->orderBy('id')
            ->paginate(30)->withQueryString();
        $works = Work::whereIn('author_id', $lives->pluck('citable_id')->unique())->orderBy('primary_title')
            ->get(['id', 'author_id', 'primary_title'])->groupBy('author_id')->map(fn ($of) => $of->take(self::WORKS));

        return view('admin.person_lives.index', compact('lives', 'sources', 'counts', 'status', 'filters', 'query', 'works'));
    }

    public static function methodLabel(?string $method): string
    {
        return self::METHODS[$method] ?? (string) $method;
    }

    private function ties(): Builder
    {
        return Entry::where('origin', Entry::ORIGIN_MATCH)->where('citable_type', Person::class)->whereNotNull('source_item_id');
    }

    private function filtered(array $filters): Builder
    {
        return $this->ties()
            ->when($filters['source'] ?? null, fn ($q, $s) => $q->where('source_id', $s->id))
            ->when($filters['method'] ?? null, fn ($q, $m) => $q->where('match_method', $m));
    }
}
