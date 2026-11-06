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
        $history = AuditLog::with('user:id,name')->where('auditable_type', Source::class)->where('auditable_id', $source->id)
            ->latest('id')->limit(20)->get();
        $libraries = Library::orderBy('name')->get(['id', 'name', 'city']);

        return view('admin.sources.show', compact('source', 'volumes', 'catalogVolumes', 'entries', 'pages', 'unplaced', 'history', 'libraries'));
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
