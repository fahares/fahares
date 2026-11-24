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
    public function create(Request $request)
    {
        $from = $request->filled('from') ? Manuscript::with('libraryRecord')->find((int) $request->query('from')) : null;
        $defaults = [];
        if ($from) {
            $key = $from->collection_key ?? Manuscript::collectionKeyOf($from->shelfmark, $from->raw_text) ?? Manuscript::plainVolumeKeyOf($from->shelfmark, $from->raw_text);
            $defaults = ['from_id' => $from->id, 'library_id' => $from->library_id, 'collection_key' => $key,
                // the volume's plain number is a shelfmark of its own; a numbered part is the reviewer's to write
                'shelfmark' => $key !== null && Manuscript::plainVolumeKeyOf($key) === $key ? $key : null];
        }

        return view('admin.manuscripts.create', [
            'from' => $from,
            'defaults' => $defaults,
            'picked' => $this->picked($request, $defaults),
            'duplicates' => session('duplicates') ? Manuscript::with('work:id,primary_title,clean_title')->whereIn('id', session('duplicates'))->get() : collect(),
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

        try {
            $manuscript = $creator->create($data, $request->user()->id, $request->ip());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('manuscripts.show', $manuscript)
            ->with('success', 'رکورد نسخه ساخته شد. از لاگ ممیزی بازگرداندنی است.');
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
            'author_name' => 'nullable|string|max:500',
            'range' => 'nullable|string|max:30',
            'folios' => 'nullable|integer|between:1,5000',
            'pages' => 'nullable|integer|between:1,10000',
            'scribe_id' => 'nullable|integer|exists:people,id',
            'scribe_name' => 'nullable|string|max:600',
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
