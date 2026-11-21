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
