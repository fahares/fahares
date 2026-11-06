<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\CatalogVolumePart;
use App\Models\Library;
use App\Models\Source;
use App\Models\SourceVolume;
use App\Sources\SourceMerger;
use App\Sources\VolumeEditor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The sources registry in the admin panel (step 14.50, backlog items 70 and 111): the list of sources, a source's
 * name, kind and rank, its volumes' library and people, and the parts of a volume. A source volume and its catalog
 * volume share one id.
 */
class SourceController extends Controller
{
    public const ROLES = ['cataloger' => 'فهرست‌نگار', 'supervisor' => 'زیر نظر', 'translator' => 'مترجم'];

    private const SORTS = ['title', 'volumes', 'entries', 'copies', 'rank'];

    public function index(Request $request)
    {
        $filters = $request->only(['q', 'kind', 'rank', 'library', 'volumes', 'entries', 'text', 'sort']);
        $sort = in_array($filters['sort'] ?? '', self::SORTS, true) ? $filters['sort'] : 'entries';
        $volumes = fn () => DB::table('source_volumes')->whereColumn('source_volumes.source_id', 'sources.id')->select('id');

        $query = Source::query()->with('library:id,name,city')
            ->withCount('entries')
            ->selectSub(DB::table('catalog_volumes')->whereIn('id', $volumes())->selectRaw('coalesce(sum(manuscripts_count), 0)'), 'copies_count')
            ->selectSub(DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $volumes())->selectRaw('count(*)'), 'parts_count')
            ->selectSub(DB::table('source_pages')->whereIn('source_volume_id', $volumes())->selectRaw('count(*)'), 'pages_count');

        if ($q = trim((string) ($filters['q'] ?? ''))) {
            $query->where(fn ($w) => $w->where('title', 'like', "%{$q}%")->orWhere('short_name', 'like', "%{$q}%")
                ->orWhere('code', $q)->orWhere('metadata->merged_codes', 'like', "%\"{$q}\"%")
                ->when(ctype_digit($q), fn ($w) => $w->orWhere('id', (int) $q)));
        }
        if (isset(Source::KIND_LABELS[$filters['kind'] ?? ''])) {
            $query->where('kind', $filters['kind']);
        }
        if (is_numeric($filters['rank'] ?? null)) {
            $query->where('rank', (int) $filters['rank']);
        }
        if (is_numeric($filters['library'] ?? null)) {
            $query->where('library_id', (int) $filters['library']);
        }
        match ($filters['volumes'] ?? '') {
            'none' => $query->where('volumes_count', 0),
            'one' => $query->where('volumes_count', 1),
            'many' => $query->where('volumes_count', '>', 1),
            default => null,
        };
        match ($filters['entries'] ?? '') {
            'none' => $query->having('entries_count', 0),
            'some' => $query->having('entries_count', '>', 0),
            default => null,
        };
        match ($filters['text'] ?? '') {
            'yes' => $query->having('pages_count', '>', 0),
            'no' => $query->having('pages_count', 0),
            default => null,
        };
        match ($sort) {
            'title' => $query->orderBy('title'),
            'volumes' => $query->orderByDesc('volumes_count'),
            'copies' => $query->orderByDesc('copies_count'),
            'rank' => $query->orderBy('rank')->orderBy('title'),
            default => $query->orderByDesc('entries_count'),
        };

        $sources = $query->orderBy('id')->paginate(25)->withQueryString();
        $ranks = Source::distinct()->orderBy('rank')->pluck('rank');
        $libraries = Library::whereIn('id', Source::whereNotNull('library_id')->select('library_id'))->orderBy('name')->get(['id', 'name', 'city']);

        return view('admin.sources.index', compact('sources', 'filters', 'sort', 'ranks', 'libraries'));
    }

    /** A new source (step 14.57, step 2 of docs/SOURCE_PROTOCOL.md): the source and its volumes; pages and texts stay commands. */
    public function create(Request $request)
    {
        $libraries = Library::orderBy('name')->get(['id', 'name', 'city']);
        $sources = Source::orderBy('title')->get(['id', 'code', 'title']);
        $similar = session('similar') ? Source::whereIn('id', session('similar'))->get(['id', 'code', 'title', 'volumes_count']) : collect();

        return view('admin.sources.create', compact('libraries', 'sources', 'similar'));
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:sources,code'],
            'kind' => ['required', Rule::in(array_keys(Source::KIND_LABELS))],
            'rank' => 'nullable|integer|min:0|max:999',
            'title' => 'required|string|max:500',
            'short_name' => 'nullable|string|max:255',
            'compiler' => 'nullable|string|max:255',
            'publisher' => 'nullable|string|max:255',
            'publication_year' => 'nullable|string|max:50',
            'edition_label' => 'nullable|string|max:100',
            'edition_of_id' => 'nullable|integer|exists:sources,id',
            'library_id' => 'nullable|integer|exists:libraries,id',
            'unit' => ['required', Rule::in(['صفحه', 'ستون'])],
            'volumes' => 'required|integer|min:1|max:200',
            'last_pages' => 'nullable|string|max:3000',
            'runs_on' => 'boolean',
            'despite_similar' => 'boolean',
        ], [
            'code.regex' => 'کد فقط حرف کوچک لاتین، رقم و خط تیره دارد، مانند «dena».',
            'code.unique' => 'این کد پیش‌تر به منبع دیگری داده شده است.',
        ]);
        $v['title'] = trim(preg_replace('/\s+/u', ' ', $v['title']));
        if (Source::mergedInto($v['code'])) {
            return back()->withInput()->withErrors(['code' => 'این کد، کد منبعی ادغام‌شده است و به منبع دیگری می‌رسد.']);
        }
        // the registry's look for the source first (step 2 of the protocol): titles one once spelling is evened out
        $similar = $this->similar($v['title']);
        if ($similar && ! $request->boolean('despite_similar')) {
            return back()->withInput()->with('similar', $similar)
                ->with('warning', 'منبعی با عنوان نزدیک هست. اگر همان منبع نیست، «با وجود منبع مشابه بساز» را بزنید.');
        }
        $digits = array_combine(['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'], range(0, 9));
        $last = array_values(array_filter(array_map(fn ($l) => (int) strtr(trim($l), $digits), preg_split('/\R|[،,]/u', (string) ($v['last_pages'] ?? '')))));
        if ($last && count($last) !== (int) $v['volumes']) {
            return back()->withInput()->withErrors(['last_pages' => 'برای هر جلد یک صفحه‌ی آخر لازم است، یا هیچ.']);
        }

        $source = DB::transaction(function () use ($v, $last, $request) {
            $source = Source::create([
                'code' => $v['code'], 'kind' => $v['kind'], 'rank' => $v['rank'] ?? Source::KIND_RANKS[$v['kind']], 'title' => $v['title'],
                'short_name' => $v['short_name'] ?? null, 'compiler' => $v['compiler'] ?? null, 'publisher' => $v['publisher'] ?? null,
                'publication_year' => $v['publication_year'] ?? null, 'edition_label' => $v['edition_label'] ?? null,
                'edition_of_id' => $v['edition_of_id'] ?? null, 'library_id' => $v['library_id'] ?? null, 'unit' => $v['unit'],
                'volumes_count' => (int) $v['volumes'], 'metadata' => ['made_by' => 'admin', 'images' => false],
            ]);
            $first = 1;
            for ($n = 1; $n <= (int) $v['volumes']; $n++) {
                $pages = isset($last[$n - 1]) ? ['page_first' => $first, 'page_last' => $last[$n - 1]] : [];
                $row = ['volume_number' => $n, 'title' => $v['title'] . ((int) $v['volumes'] > 1 ? ' - جلد ' . $n : ''), 'created_at' => now(), 'updated_at' => now()] + $pages;
                // a library's catalogue is a catalog volume too, under the same id, which copies are tied to
                $id = $v['kind'] === Source::KIND_LIBRARY_CATALOGUE
                    ? DB::table('catalog_volumes')->insertGetId($row + ['library_id' => $v['library_id'] ?? null, 'pages_count' => isset($pages['page_last']) ? $pages['page_last'] - $first + 1 : null])
                    : null;
                DB::table('source_volumes')->insert(($id ? ['id' => $id] : []) + $row + ['source_id' => $source->id]);
                $first = $request->boolean('runs_on') && isset($last[$n - 1]) ? $last[$n - 1] + 1 : 1;
            }
            AuditLog::create(['user_id' => auth()->id(), 'auditable_type' => Source::class, 'auditable_id' => $source->id, 'action' => 'create',
                'old_values' => null, 'new_values' => $source->only(['code', 'kind', 'rank', 'title', 'volumes_count']) + ['last_pages' => $last], 'ip_address' => $request->ip()]);

            return $source;
        });
        SourceMerger::forget([$source->id]);

        return redirect()->route('admin.sources.citations.create', $source)
            ->with('success', "منبع «{$source->title}» با {$source->volumes_count} جلد ساخته شد. اکنون بگویید در مآخذ چگونه ارجاع داده می‌شود.");
    }

    /** @return list<int> the sources whose title is the same once spelling is evened out, or holds it */
    private function similar(string $title): array
    {
        $plain = fn (string $t) => str_replace(' ', '', SourceMerger::normalize(\App\Sources\CitationForms::normalize(SourceMerger::baseTitle($t))));
        $mine = $plain($title);

        return Source::get(['id', 'title'])->filter(function ($s) use ($plain, $mine) {
            $theirs = $plain($s->title);

            return $theirs === $mine || (mb_strlen($mine) >= 6 && (str_contains($theirs, $mine) || str_contains($mine, $theirs) && mb_strlen($theirs) >= 6));
        })->pluck('id')->all();
    }

    public function show(Source $source)
    {
        $source->load(['library', 'editionOf:id,code,title', 'editions:id,code,title,edition_of_id,edition_label']);
        $volumes = SourceVolume::where('source_id', $source->id)->orderBy('volume_number')->orderBy('id')->get();
        $catalogVolumes = CatalogVolume::with(['library:id,name,city', 'catalogers:id,name'])->withCount('parts')
            ->whereIn('id', $volumes->pluck('id'))->get()->keyBy('id');
        $entries = DB::table('source_entries')->whereIn('source_volume_id', $volumes->pluck('id'))->groupBy('source_volume_id')
            ->selectRaw('source_volume_id, count(*) c')->pluck('c', 'source_volume_id');
        $pages = DB::table('source_pages')->whereIn('source_volume_id', $volumes->pluck('id'))->groupBy('source_volume_id')
            ->selectRaw('source_volume_id, count(*) c')->pluck('c', 'source_volume_id');
        $unplaced = DB::table('source_entries')->where('source_id', $source->id)->whereNull('source_volume_id')->count();
        $history = AuditLog::with('user:id,name')->where(fn ($q) => $q->where(fn ($q) => $q->where('auditable_type', Source::class)->where('auditable_id', $source->id))
            ->orWhere(fn ($q) => $q->where('auditable_type', \App\Models\SourceCitationForm::class)
                ->where(fn ($q) => $q->where('new_values->cited_source_id', $source->id)->orWhere('old_values->cited_source_id', $source->id))))
            ->latest('id')->limit(20)->get();
        $libraries = Library::orderBy('name')->get(['id', 'name', 'city']);
        // how it is cited in the others, and how it cites them (step 14.57)
        $cited = \App\Models\SourceCitationForm::with('citing:id,code,title,short_name')->where('cited_source_id', $source->id)->orderBy('citing_source_id')->orderBy('id')->get();
        $citing = \App\Models\SourceCitationForm::with('cited:id,code,title,short_name')->where('citing_source_id', $source->id)->orderBy('id')->get();
        $bibliography = DB::table('source_entries')->where('legacy_type', 'work_bibliography')->where('source_id', $source->id)->count();

        return view('admin.sources.show', compact('source', 'volumes', 'catalogVolumes', 'entries', 'pages', 'unplaced', 'history', 'libraries', 'cited', 'citing', 'bibliography'));
    }

    public function update(Request $request, Source $source)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:500',
            'short_name' => 'nullable|string|max:255',
            'kind' => ['required', Rule::in(array_keys(Source::KIND_LABELS))],
            'rank' => 'required|integer|min:0|max:999',
            'library_id' => 'nullable|integer|exists:libraries,id',
        ]);
        $validated['title'] = trim(preg_replace('/\s+/u', ' ', $validated['title']));
        $validated['short_name'] = filled($validated['short_name'] ?? null) ? trim($validated['short_name']) : null;
        $validated['library_id'] = $validated['library_id'] ?? null;

        $source->fill($validated);
        $changed = $source->getDirty();
        if (! $changed) {
            return redirect()->route('admin.sources.show', $source)->with('success', 'تغییری داده نشد.');
        }
        $old = array_intersect_key($source->getOriginal(), $changed);
        $source->save();
        SourceMerger::forget([$source->id]);

        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => Source::class,
            'auditable_id' => $source->id,
            'action' => 'update',
            'old_values' => $old,
            'new_values' => $changed,
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('admin.sources.show', $source)->with('success', 'مشخصات منبع ذخیره شد.');
    }

    public function volume(Source $source, int $volume)
    {
        [$sourceVolume, $catalogVolume] = $this->volumeOf($source, $volume);
        $catalogVolume->load(['library:id,name,city', 'catalogers:id,name', 'parts.library:id,name,city', 'parts.catalogers:id,name']);
        $outside = $this->outside($catalogVolume);
        $partless = DB::table('manuscripts')->where('catalog_volume_id', $catalogVolume->id)->whereNull('catalog_volume_part_id')->count();
        $libraries = Library::orderBy('name')->get(['id', 'name', 'city']);
        $catalogers = Cataloger::orderBy('name')->get(['id', 'name']);
        // the libraries of the volume's copies, those with no part first: the parts one may be missing
        $copyLibraries = DB::table('manuscripts')->where('catalog_volume_id', $catalogVolume->id)->whereNotNull('library_id')
            ->groupBy('library_id')->selectRaw('library_id, count(*) c, sum(catalog_volume_part_id is null) partless')
            ->orderByDesc('partless')->orderByDesc('c')->limit(30)->get();
        $copyLibraryNames = Library::whereIn('id', $copyLibraries->pluck('library_id'))->get(['id', 'name', 'city'])->keyBy('id');
        $history = AuditLog::with('user:id,name')->whereIn('auditable_type', [CatalogVolume::class, CatalogVolumePart::class])
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('auditable_type', CatalogVolume::class)->where('auditable_id', $catalogVolume->id))
                ->orWhere('new_values->catalog_volume_id', $catalogVolume->id)->orWhere('old_values->catalog_volume_id', $catalogVolume->id))
            ->latest('id')->limit(20)->get();

        return view('admin.sources.volume', compact('source', 'sourceVolume', 'catalogVolume', 'outside', 'partless', 'libraries',
            'catalogers', 'copyLibraries', 'copyLibraryNames', 'history'));
    }

    /** The volume's library and people. */
    public function updateVolume(Request $request, Source $source, int $volume, VolumeEditor $editor)
    {
        [, $catalogVolume] = $this->volumeOf($source, $volume);
        $validated = $request->validate([
            'library_id' => 'nullable|integer|exists:libraries,id',
            'people' => 'array|max:30',
            'people.*.cataloger_id' => 'nullable|integer|exists:catalogers,id',
            'people.*.role' => ['nullable', Rule::in(array_keys(self::ROLES))],
        ]);
        $people = collect($validated['people'] ?? [])->filter(fn ($p) => ! empty($p['cataloger_id']))
            ->map(fn ($p) => ['cataloger_id' => (int) $p['cataloger_id'], 'role' => $p['role'] ?? 'cataloger'])
            ->unique('cataloger_id')->values();
        $old = ['catalog_volume_id' => $catalogVolume->id, 'library_id' => $catalogVolume->library_id, 'people' => $this->peopleOf($catalogVolume)];
        $new = ['catalog_volume_id' => $catalogVolume->id, 'library_id' => $validated['library_id'] ?? null, 'people' => $people->all()];
        if ($old == $new) {
            return back()->with('success', 'تغییری داده نشد.');
        }

        $change = function () use ($catalogVolume, $new) {
            DB::table('catalog_volumes')->where('id', $catalogVolume->id)->update(['library_id' => $new['library_id'], 'updated_at' => now()]);
            DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $catalogVolume->id)->delete();
            foreach ($new['people'] as $person) {
                DB::table('catalog_volume_cataloger')->insert($person + ['catalog_volume_id' => $catalogVolume->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        };

        return $this->runChange($request, $source, $catalogVolume, $editor, $change, CatalogVolume::class, $catalogVolume->id, $old, $new);
    }

    public function storePart(Request $request, Source $source, int $volume, VolumeEditor $editor)
    {
        [, $catalogVolume] = $this->volumeOf($source, $volume);
        $values = $this->partValues($request, 'new');
        $new = ['catalog_volume_id' => $catalogVolume->id] + $values;
        $made = null;
        $change = function () use ($catalogVolume, $values, &$made) {
            $made = CatalogVolumePart::create(['catalog_volume_id' => $catalogVolume->id,
                'sort' => $values['sort'] ?? (int) DB::table('catalog_volume_parts')->where('catalog_volume_id', $catalogVolume->id)->max('sort') + 1,
                'metadata' => ['made_by' => 'admin']] + collect($values)->except(['catalogers', 'sort'])->all());
            $made->catalogers()->sync(collect($values['catalogers'])->mapWithKeys(fn ($id) => [$id => ['role' => 'cataloger']])->all());
        };

        return $this->runChange($request, $source, $catalogVolume, $editor, $change, CatalogVolumePart::class, function () use (&$made) {
            return $made?->id;
        }, null, $new, 'create', 'new');
    }

    public function updatePart(Request $request, Source $source, int $volume, CatalogVolumePart $part, VolumeEditor $editor)
    {
        [, $catalogVolume] = $this->volumeOf($source, $volume);
        abort_unless($part->catalog_volume_id === $catalogVolume->id, 404);
        $values = $this->partValues($request, 'part' . $part->id);
        $old = $this->partState($part);
        $new = ['catalog_volume_id' => $catalogVolume->id] + $values;
        $new['sort'] ??= $part->sort;
        if ($old == $new) {
            return back()->with('success', 'تغییری داده نشد.');
        }
        $change = function () use ($part, $values) {
            $part->update(collect($values)->except('catalogers')->filter(fn ($v, $k) => $k !== 'sort' || $v !== null)->all());
            $part->catalogers()->sync(collect($values['catalogers'])->mapWithKeys(fn ($id) => [$id => ['role' => 'cataloger']])->all());
        };

        return $this->runChange($request, $source, $catalogVolume, $editor, $change, CatalogVolumePart::class, $part->id, $old, $new, 'update', 'part' . $part->id);
    }

    public function destroyPart(Request $request, Source $source, int $volume, CatalogVolumePart $part, VolumeEditor $editor)
    {
        [, $catalogVolume] = $this->volumeOf($source, $volume);
        abort_unless($part->catalog_volume_id === $catalogVolume->id, 404);
        $old = $this->partState($part) + ['id' => $part->id, 'metadata' => $part->metadata];
        $change = function () use ($part) {
            DB::table('manuscripts')->where('catalog_volume_part_id', $part->id)->update(['catalog_volume_part_id' => null]);
            $part->delete();
        };

        return $this->runChange($request, $source, $catalogVolume, $editor, $change, CatalogVolumePart::class, $part->id, $old, null, 'remove', 'part' . $part->id);
    }

    /**
     * Runs a change of the volume through VolumeEditor: «preview» shows what it would do to the copies and keeps the
     * form; otherwise it is saved and logged.
     */
    private function runChange(Request $request, Source $source, CatalogVolume $volume, VolumeEditor $editor, \Closure $change,
        string $type, int|\Closure $id, ?array $old, ?array $new, string $action = 'update', string $form = 'volume')
    {
        $back = route('admin.sources.volume', [$source, $volume->id]) . ($form === 'volume' ? '' : '#' . $form);
        if ($request->boolean('preview')) {
            $effect = $editor->run($volume, $change, dry: true);

            return redirect($back)->withInput()->with('preview', ['form' => $form, 'effect' => $effect]);
        }
        $effect = $editor->run($volume, $change);
        $id = $id instanceof \Closure ? $id() : $id;
        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => $type,
            'auditable_id' => $id,
            'action' => $action,
            'old_values' => $old,
            'new_values' => ($new ?? ['catalog_volume_id' => $volume->id]) + ['effect' => collect($effect)->except('parts')->all()],
            'ip_address' => $request->ip(),
        ]);
        SourceMerger::forget([$source->id]);

        return redirect($back)->with('success', 'ذخیره شد.')->with('effect', $effect);
    }

    /** @return array{title: string, library_id: ?int, page_first: ?int, page_last: ?int, sort: ?int, catalogers: array<int, int>} */
    private function partValues(Request $request, string $bag): array
    {
        $v = $request->validateWithBag($bag, [
            'title' => 'required|string|max:500',
            'library_id' => 'nullable|integer|exists:libraries,id',
            'page_first' => 'nullable|integer|min:1|max:100000',
            'page_last' => 'nullable|integer|min:1|max:100000|gte:page_first',
            'sort' => 'nullable|integer|min:0|max:10000',
            'catalogers' => 'array|max:20',
            'catalogers.*' => 'nullable|integer|exists:catalogers,id',
        ], [
            'page_last.gte' => 'صفحه‌ی پایان نباید پیش از صفحه‌ی آغاز باشد.',
        ]);

        return [
            'title' => trim(preg_replace('/\s+/u', ' ', $v['title'])),
            'library_id' => isset($v['library_id']) ? (int) $v['library_id'] : null,
            'page_first' => isset($v['page_first']) ? (int) $v['page_first'] : null,
            'page_last' => isset($v['page_last']) ? (int) $v['page_last'] : null,
            'sort' => isset($v['sort']) ? (int) $v['sort'] : null,
            'catalogers' => collect($v['catalogers'] ?? [])->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
        ];
    }

    private function partState(CatalogVolumePart $part): array
    {
        return ['catalog_volume_id' => $part->catalog_volume_id, 'title' => $part->title, 'library_id' => $part->library_id,
            'page_first' => $part->page_first, 'page_last' => $part->page_last, 'sort' => $part->sort,
            'catalogers' => $part->catalogers()->pluck('catalogers.id')->map(fn ($id) => (int) $id)->all()];
    }

    private function peopleOf(CatalogVolume $volume): array
    {
        return DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $volume->id)->orderBy('id')->get(['cataloger_id', 'role'])
            ->map(fn ($r) => ['cataloger_id' => (int) $r->cataloger_id, 'role' => $r->role])->all();
    }

    /** Per part, the copies of its library whose citation's page falls outside its pages. */
    private function outside(CatalogVolume $volume): array
    {
        $pages = DB::table('manuscripts as m')->join('source_entries as e', fn ($j) => $j->on('e.legacy_id', '=', 'm.id')->where('e.legacy_type', 'fankha_citation'))
            ->where('m.catalog_volume_id', $volume->id)->whereNotNull('m.catalog_volume_part_id')->whereNotNull('e.page_start')
            ->get(['m.catalog_volume_part_id as part', 'e.page_start as page']);
        $out = [];
        foreach ($volume->parts as $part) {
            if ($part->page_first !== null) {
                $out[$part->id] = $pages->where('part', $part->id)->reject(fn ($r) => $part->holds((int) $r->page))->count();
            }
        }

        return $out;
    }

    /** @return array{0: SourceVolume, 1: CatalogVolume} */
    private function volumeOf(Source $source, int $id): array
    {
        $sourceVolume = SourceVolume::where('source_id', $source->id)->findOrFail($id);

        return [$sourceVolume, CatalogVolume::findOrFail($id)];
    }
}
