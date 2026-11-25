<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Source;
use App\Models\Work;
use App\Suggestions\ManuscriptCreator;
use App\Suggestions\SuggestionRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * «نسخۀ تازه» (backlog item 149, step 14.306): the record of a copy the catalogues do not list, made from the panel.
 * Opened from a copy's page («جزء دیگری از همین مجموعه») the form starts with that copy's library and volume.
 */
class ManuscriptController extends Controller
{
    /**
     * The copies, for the panel (step 14.397): by library, by shelfmark or title, or by id or link, and the records made
     * in the panel. «نسخۀ تازه» is a button of this page.
     */
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $libraryId = $request->integer('library_id') ?: null;
        $filter = $request->query('filter');
        $query = Manuscript::query()->select(['id', 'work_id', 'library_id', 'library', 'city', 'shelfmark', 'range_from', 'range_to', 'scribe_name', 'copy_date_raw', 'catalog_id'])
            ->with('work:id,primary_title,author_name,slug');

        if ($libraryId) {
            $query->where('library_id', $libraryId);
        }
        if ($search !== '') {
            $digits = strtr($search, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
            // a bare number is a shelfmark here: the id goes with «#» or the copy's link
            $id = preg_match('~/(?:m|manuscripts)/(\d+)~u', $digits, $m) || preg_match('/^#(\d+)$/u', $digits, $m) ? (int) $m[1] : null;
            // a title is searched with a library chosen: the whole of the copies is too many for a title alone. The
            // works are found first; as a subquery MariaDB scans the copies (4 s on the production copy)
            $works = $libraryId ? Work::where('primary_title', 'like', "%{$search}%")->limit(5000)->pluck('id')->all() : [];
            $id ? $query->whereKey($id) : $query->where(fn ($q) => $q->where('shelfmark_key', $digits)->orWhere('shelfmark_key', 'like', $digits . '/%')
                ->when($works, fn ($q) => $q->orWhereIn('work_id', $works)));
        }
        if ($filter === 'panel') {
            $query->whereNull('catalog_id');
        }
        $manuscripts = $query->orderByDesc('id')->paginate(25)->withQueryString();
        $library = $libraryId ? Library::find($libraryId) : null;

        return view('admin.manuscripts.index', [
            'manuscripts' => $manuscripts, 'search' => $search, 'filter' => $filter,
            'picked' => ['library_id' => $library ? SuggestionRegistry::describe($library) : null],
        ]);
    }

    public function create(Request $request)
    {
        $from = $request->filled('from') ? Manuscript::with(['libraryRecord', 'fankhaEntry'])->find((int) $request->query('from')) : null;
        $defaults = [];
        if ($from) {
            // the «مجموعه» record it is of comes first (backlog item 163): an editor may have placed it in a collection its
            // shelfmark does not name (collection_pinned, step 14.330)
            $key = $from->collection?->key ?? $from->collection_key ?? Manuscript::collectionKeyOf($from->shelfmark, $from->fankhaText()) ?? Manuscript::plainVolumeKeyOf($from->shelfmark, $from->fankhaText());
            $defaults = ['from_id' => $from->id, 'library_id' => $from->library_id, 'collection_key' => $key,
                // the volume's plain number is a shelfmark of its own; a numbered part is the reviewer's to write, and
                // so is the number of a treatise placed by hand
                'shelfmark' => $key !== null && ! $from->collection_pinned && Manuscript::plainVolumeKeyOf($key) === $key ? $key : null];
        }

        return view('admin.manuscripts.create', [
            'from' => $from,
            'defaults' => $defaults,
            'picked' => $this->picked($request, $defaults),
            'duplicates' => session('duplicates') ? Manuscript::with('work:id,primary_title,clean_title')->whereIn('id', session('duplicates'))->get() : collect(),
            // the people of the name of a new author or scribe (backlog item 161), by role
            'namesakes' => collect((array) session('namesakes'))->map(fn ($ids) => Person::whereIn('id', $ids)->orderByDesc('works_count')->get())->filter->isNotEmpty(),
        ]);
    }

    /**
     * Places a treatise in another «مجموعه» of its library by hand, or gives it back to the collection its shelfmark
     * names (backlog item 163, step 14.330): for the volumes two of which stand under one number, and the treatise
     * whose shelfmark was misread. The collection is found by its number or made; the copy is pinned to it, so the
     * rule of the shelfmark leaves it there. Undone from the audit log.
     */
    public function moveCollection(Request $request, Manuscript $manuscript)
    {
        $data = $request->validate(['collection_key' => 'nullable|string|max:100']);
        $key = trim(strtr((string) ($data['collection_key'] ?? ''), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']));
        if (! $manuscript->library_id) {
            return back()->with('error', 'این نسخه کتابخانۀ شناخته‌ای ندارد.');
        }
        $old = $manuscript->only(['collection_id', 'collection_pinned']);
        \Illuminate\Support\Facades\DB::transaction(function () use ($manuscript, $key, $old, $request) {
            if ($key === '') {
                // back to the rule: the hook links it to the collection of its shelfmark, or to none
                $manuscript->forceFill(['collection_pinned' => false])->save();
            } else {
                $collection = \App\Models\ManuscriptCollection::firstOrCreate(['library_id' => $manuscript->library_id, 'key' => $key]);
                $manuscript->forceFill(['collection_id' => $collection->id, 'collection_pinned' => true])->save();
            }
            $new = \Illuminate\Support\Facades\DB::table('manuscripts')->where('id', $manuscript->id)->first(['collection_id', 'collection_pinned']);
            $new = ['collection_id' => $new->collection_id, 'collection_pinned' => (bool) $new->collection_pinned];
            if ($new != $old) {
                \App\Models\AuditLog::create(['user_id' => $request->user()->id, 'auditable_type' => Manuscript::class, 'auditable_id' => $manuscript->id, 'action' => 'verify',
                    'old_values' => $old, 'new_values' => $new + ['direct_edit' => true], 'ip_address' => $request->ip()]);
            }
        });
        $manuscript->refresh()->searchable();

        return redirect()->route('manuscripts.show', $manuscript)->with('success', $key === '' ? 'نسخه به مجموعۀ شمارۀ خودش بازگشت.' : 'نسخه در مجموعۀ ' . \App\Support\Fa::digits($key) . ' جای گرفت. از لاگ ممیزی بازگرداندنی است.');
    }

    public function store(Request $request, ManuscriptCreator $creator)
    {
        $data = $request->validate($this->rules());

        // the same library and shelfmark: the reviewer sees the copies and says again that this is another
        $duplicates = $creator->duplicates((int) $data['library_id'], $data['shelfmark']);
        if ($duplicates->isNotEmpty() && ! $request->boolean('confirm_duplicate')) {
            return back()->withInput()->with('duplicates', $duplicates->pluck('id')->all());
        }
        // a new person for the author or scribe (backlog item 161): the people of that name first
        $namesakes = [];
        foreach (['author' => ($data['work_mode'] ?? null) === 'new', 'scribe' => true] as $role => $applies) {
            if ($applies && ! empty($data["{$role}_new"]) && blank($data["{$role}_id"] ?? null)) {
                $ids = app(\App\Suggestions\RecordCreator::class)->personDuplicates($data["{$role}_name"] ?? null)->pluck('id')->all();
                $ids && $namesakes[$role] = $ids;
            }
        }
        if ($namesakes && ! $request->boolean('confirm_people')) {
            return back()->withInput()->with('namesakes', $namesakes);
        }

        try {
            $manuscript = $creator->create($data, $request->user()->id, $request->ip());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('manuscripts.show', $manuscript)
            ->with('success', 'رکورد نسخه ساخته شد.'
                // its shelfmark names another volume or none: say where it went (step 14.353)
                . ($manuscript->collection_pinned ? ' شمارۀ نسخه این مجموعه را نشان نمی‌دهد؛ نسخه دستی در مجموعۀ ' . \App\Support\Fa::digits((string) $manuscript->collection?->key) . ' جای گرفت.' : '')
                . ' از لاگ ممیزی بازگرداندنی است.');
    }

    private function rules(): array
    {
        return [
            'from_id' => 'nullable|integer',
            'library_id' => 'required|integer|exists:libraries,id',
            'shelfmark' => 'required|string|max:100',
            'collection_key' => 'nullable|string|max:100',
            'work_mode' => ['required', Rule::in(array_keys(ManuscriptCreator::WORK_MODES))],
            'work_id' => 'nullable|required_if:work_mode,existing|integer|exists:works,id',
            'work_title' => 'nullable|required_if:work_mode,new|string|max:255',
            'author_id' => 'nullable|integer|exists:people,id',
            'author_name' => 'nullable|required_if:author_new,1|string|max:500',
            'author_new' => 'nullable|boolean',
            'range' => 'nullable|string|max:30',
            'folios' => 'nullable|integer|between:1,5000',
            'pages' => 'nullable|integer|between:1,10000',
            'scribe_id' => 'nullable|integer|exists:people,id',
            'scribe_name' => 'nullable|required_if:scribe_new,1|string|max:600',
            'scribe_new' => 'nullable|boolean',
            'copy_date_raw' => 'nullable|string|max:300',
            'incipit_text' => 'nullable|string|max:5000',
            'explicit_text' => 'nullable|string|max:5000',
            'copy_place' => 'nullable|string|max:300',
            'commissioned_by' => 'nullable|string|max:500',
            'script_names' => 'nullable|string|max:300',
            'script_style' => 'nullable|string|max:300',
            'lines' => 'nullable|integer|between:1,99',
            'dimensions' => 'nullable|string|max:255',
            'paper' => 'nullable|string|max:500',
            'binding' => 'nullable|string|max:500',
            'flags' => 'nullable|array',
            'flags.*' => 'boolean',
            'notes' => 'nullable|array|max:40',
            'notes.*.type' => ['nullable', Rule::in(array_keys(\App\Models\ManuscriptNote::TYPES))],
            'notes.*.text' => 'nullable|string|max:5000',
            'source_kind' => ['required', Rule::in(array_keys(ManuscriptCreator::SOURCE_KINDS))],
            'source_text' => 'nullable|string|max:300',
            'suggestion_id' => 'nullable|integer|exists:field_suggestions,id',
            'source_id' => 'nullable|integer|exists:sources,id',
            'source_place' => 'nullable|required_with:source_id|string|max:60',
        ];
    }

    /** The records the form's pickers hold, described for their labels: the old input, else the defaults. */
    private function picked(Request $request, array $defaults): array
    {
        $picked = [];
        foreach (['library_id' => Library::class, 'work_id' => Work::class, 'author_id' => Person::class, 'scribe_id' => Person::class, 'source_id' => Source::class] as $field => $class) {
            $id = (int) $request->old($field, $defaults[$field] ?? 0);
            $record = $id ? $class::find($id) : null;
            $picked[$field] = $record ? SuggestionRegistry::describe($record) : null;
        }

        return $picked;
    }
}
