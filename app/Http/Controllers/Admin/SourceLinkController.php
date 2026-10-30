<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\LinkSourceItemsCommand;
use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Models\SourceEntry as Entry;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use App\Models\Work;
use App\Sources\Extractors\Extractors;
use App\Support\Fa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Review queue of the works tied by machine to a printed entry of a bibliography (step 13.72): al-Dhari'a, Monzavi's
 * «فهرستواره» and the others of sources:link-items. Each tie is verified, rejected, or moved to another entry on its
 * page or a neighbouring one; a source without page images is read in its entry text and its page text.
 */
class SourceLinkController extends Controller
{
    /** The machine's methods in plain words; «near_*» is the same method on a neighbouring page. */
    public const METHODS = [
        'ambiguous' => 'مبهم (چند مدخل هم‌عنوان)',
        'author_death' => 'مؤلف و سال وفات، بی عنوان',
        'near' => 'در صفحه‌ی همسایه',
        'title_only' => 'فقط عنوان',
        'title_in_text' => 'عنوان در متن مدخل دیگر',
        'author_in_entry' => 'مؤلف در متن مدخل دیگر',
        'derivative_of_heading' => 'شرح یا ترجمه‌ی سرمدخل',
        'llm' => 'مدل زبانی',
        'other_title' => 'عنوان دیگر اثر',
        'title_exact' => 'عنوان یکسان',
        'full_title' => 'عنوان کامل',
        'title_author' => 'عنوان و مؤلف',
        'cited_number' => 'شماره‌ی مدخل',
    ];

    /** The uncertain ties first, in this order; the rest after them, the least sure first. */
    private const UNCERTAIN = ['ambiguous', 'author_death', 'near', 'title_only', 'title_in_text'];

    public const ROLES = [Entry::ROLE_ENTRY => 'مدخل', Entry::ROLE_MENTION => 'یادکرد در متن'];

    /** Pages shown before and after the tie's page and the cited one. */
    private const NEIGHBOURS = 1;

    public function index(Request $request)
    {
        $status = array_key_exists($request->query('status'), CatalogReferenceController::STATUSES) ? $request->query('status') : Entry::STATUS_UNVERIFIED;
        $filters = $this->filters($request);
        $sources = Source::whereIn('id', $this->ties()->select('source_id'))->orderBy('title')->get();
        $volumes = isset($filters['source']) ? SourceVolume::where('source_id', $filters['source']->id)->orderBy('volume_number')->get() : collect();
        $counts = $this->filtered($filters, ['status'])->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');
        $methods = $this->filtered($filters, ['method'])->where('status', $status)
            ->selectRaw("IF(match_method LIKE 'near\\_%', 'near', match_method) m, count(*) n")->groupBy('m')->pluck('n', 'm');

        $links = $this->order($this->filtered($filters)->where('status', $status))
            ->with(['citable', 'source', 'sourceItem', 'reviewer'])->paginate(30)->withQueryString();

        return view('admin.source_links.index', compact('links', 'sources', 'volumes', 'counts', 'methods', 'status', 'filters') + ['query' => $this->query($filters)]);
    }

    /** One tie with the entry's text, the text of its pages and the entries a reviewer may move it to. */
    public function show(Request $request, Entry $entry)
    {
        abort_unless($this->isTie($entry), 404);
        $entry->load(['citable', 'source', 'sourceVolume', 'sourceItem', 'reviewer']);
        $cited = $this->citedPage($entry);
        $pages = array_filter([$entry->page_start, $entry->page_end, $cited]);
        $from = max(1, min($pages) - self::NEIGHBOURS);
        $to = max($pages) + self::NEIGHBOURS;
        $candidates = SourceItem::where('source_volume_id', $entry->source_volume_id)->where('kind', '!=', 'preface')
            ->where('page_start', '<=', $to)->whereRaw('COALESCE(page_end, page_start) >= ?', [$from])
            ->orderBy('position')->limit(80)->get();
        $pageTexts = Extractors::pageRange($entry->source_volume_id, $from, $to);
        $filters = $this->filters($request);
        $next = $this->next($entry, $filters);

        return view('admin.source_links.show', compact('entry', 'cited', 'from', 'to', 'candidates', 'pageTexts', 'next')
            + ['query' => $this->query($filters)]);
    }

    /** «تأیید» or «رد» from the tie's page, and on to the next tie of the queue. */
    public function review(Request $request, Entry $entry)
    {
        abort_unless($this->isTie($entry), 404);
        $status = $request->validate(['decision' => 'required|in:' . Entry::STATUS_VERIFIED . ',' . Entry::STATUS_REJECTED])['decision'];
        $next = $this->next($entry, $this->filters($request));
        $entry->review($request->user(), $status, $request->ip());

        return $this->onward($request, $next, 'پیوند «' . $entry->citable?->primary_title . '» ' . ($status === Entry::STATUS_VERIFIED ? 'تأیید شد.' : 'رد شد.'));
    }

    /** «مدخل دیگری است»: the tie moves to another entry of the same volume, as an entry or a mention, and is verified. */
    public function move(Request $request, Entry $entry)
    {
        abort_unless($this->isTie($entry), 404);
        $data = $request->validate(['item' => 'required|integer', 'role' => 'required|in:' . implode(',', array_keys(self::ROLES))]);
        $item = SourceItem::where('source_volume_id', $entry->source_volume_id)->find($data['item']);
        if (! $item) {
            return back()->with('error', 'این مدخل در همان جلد مأخذ نیست.');
        }
        $taken = Entry::where('citable_type', $entry->citable_type)->where('citable_id', $entry->citable_id)
            ->where('source_item_id', $item->id)->whereKeyNot($entry->id)->where('status', '!=', Entry::STATUS_REJECTED)->exists();
        if ($taken) {
            return back()->with('error', 'این اثر به آن مدخل پیوند دیگری دارد؛ این پیوند را رد کنید.');
        }
        $next = $this->next($entry, $this->filters($request));
        $entry->moveToItem($item, $data['role'], $request->user(), $request->ip());

        return $this->onward($request, $next, 'پیوند «' . $entry->citable?->primary_title . '» به ' . $item->placeLabel() . ' برده و تأیید شد.');
    }

    private function onward(Request $request, ?Entry $next, string $message)
    {
        $url = $next ? route('admin.source-links.show', [$next] + $this->query($this->filters($request)))
            : route('admin.source-links.index', $this->query($this->filters($request)));

        return redirect()->to($url)->with('success', $message);
    }

    /** The ties of sources:link-items: a work and a printed entry. */
    private function ties(): Builder
    {
        return Entry::where('origin', Entry::ORIGIN_MATCH)->where('citable_type', Work::class)->whereNotNull('source_item_id');
    }

    private function isTie(Entry $entry): bool
    {
        return $entry->origin === Entry::ORIGIN_MATCH && $entry->citable_type === Work::class && $entry->source_item_id;
    }

    /**
     * The queue's filters: source (by code; Monzavi's when none is asked, «all» for every source), volume, method
     * (a «near» stands for all of near_*), role and confidence band.
     */
    private function filters(Request $request): array
    {
        $code = (string) $request->input('source', 'monzavi');
        $source = $code === 'all' ? null : Source::where('code', $code)->first();
        $volume = $source && $request->integer('volume') ? $request->integer('volume') : null;

        return array_filter([
            'source' => $source,
            'volume' => $volume,
            'method' => array_key_exists($request->input('method'), self::METHODS) ? $request->input('method') : null,
            'role' => array_key_exists($request->input('role'), self::ROLES) ? $request->input('role') : null,
            'confidence' => array_key_exists($request->input('confidence'), CatalogReferenceController::CONFIDENCE_BANDS) ? $request->input('confidence') : null,
            'status' => array_key_exists($request->input('status'), CatalogReferenceController::STATUSES) ? $request->input('status') : null,
        ]);
    }

    /** The filters as the address writes them. */
    private function query(array $filters): array
    {
        return ['source' => isset($filters['source']) ? $filters['source']->code : 'all'] + array_diff_key($filters, ['source' => 1]);
    }

    private function filtered(array $filters, array $except = []): Builder
    {
        $filters = array_diff_key($filters, array_flip($except));

        return $this->ties()
            ->when($filters['source'] ?? null, fn ($q, $s) => $q->where('source_id', $s->id))
            ->when($filters['volume'] ?? null, fn ($q, $v) => $q->where('source_volume_id', $v))
            ->when($filters['method'] ?? null, fn ($q, $m) => $m === 'near' ? $q->where('match_method', 'like', 'near\_%') : $q->where('match_method', $m))
            ->when($filters['role'] ?? null, fn ($q, $r) => $q->where('role', $r))
            ->when($filters['confidence'] ?? null, fn ($q, $b) => $q->whereBetween('confidence', CatalogReferenceController::CONFIDENCE_BANDS[$b]['range']));
    }

    private function order(Builder $query): Builder
    {
        $rank = "CASE WHEN match_method LIKE 'near\\_%' THEN " . (array_search('near', self::UNCERTAIN) + 1);
        foreach (self::UNCERTAIN as $i => $method) {
            if ($method !== 'near') {
                $rank .= " WHEN match_method = '{$method}' THEN " . ($i + 1);
            }
        }
        $rank .= ' ELSE ' . (count(self::UNCERTAIN) + 1) . ' END';

        return $query->orderByRaw($rank)->orderBy('confidence')->orderBy('source_volume_id')->orderBy('page_start')->orderBy('id');
    }

    /** The tie after this one in the queue of the same filters, undecided. */
    private function next(Entry $entry, array $filters): ?Entry
    {
        return $this->order($this->filtered($filters)->where('status', Entry::STATUS_UNVERIFIED)->whereKeyNot($entry->id))->first();
    }

    /** The page the work's citation names in this volume: a near_* tie was found off it. */
    private function citedPage(Entry $entry): ?int
    {
        if ($entry->legacy_type !== LinkSourceItemsCommand::LEGACY_TYPE || ! ($citation = Entry::find($entry->legacy_id))) {
            return null;
        }
        $place = array_values($citation->locations ?: [['page' => $citation->page_start]])[$entry->legacy_index] ?? null;

        return isset($place['page']) ? (int) $place['page'] : null;
    }

    public static function methodLabel(?string $method): string
    {
        if ($method && str_starts_with($method, 'near_')) {
            return self::METHODS['near'] . '، ' . (self::METHODS[substr($method, 5)] ?? substr($method, 5));
        }

        return self::METHODS[$method] ?? (string) $method;
    }

    public static function excerpt(?string $text, int $length = 400): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

        return Fa::digits(mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text);
    }
}
