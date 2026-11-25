<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\Source;
use App\Models\Work;
use App\Suggestions\RecordCreator;
use App\Suggestions\SuggestionRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * «اثر تازه» (backlog item 161, step 14.381): a work's record without a copy, made from the panel; its author is a
 * person of the site, one made from the same form, or a name kept as text. Works of the same title by the same
 * author, and people of the new author's name, are shown before it is made.
 */
class WorkController extends Controller
{
    /**
     * The works, for the panel (step 14.397): by title, other title, author or id, the records made in the panel (no
     * volume of Fankha) and the works with no copy. «اثر تازه» is a button of this page.
     */
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $filter = $request->query('filter');
        $query = Work::query()->select(['id', 'primary_title', 'author_id', 'author_name', 'composition_date_raw', 'manuscripts_count', 'catalog_id', 'slug']);

        if ($search !== '') {
            $id = \App\Http\Controllers\Admin\SuggestionController::recordId('work', $search);
            $id ? $query->whereKey($id) : $query->where(fn ($q) => $q->where('primary_title', 'like', "%{$search}%")
                ->orWhere('clean_title', 'like', "%{$search}%")->orWhere('alternative_titles', 'like', "%{$search}%")->orWhere('author_name', 'like', "%{$search}%"));
        }
        match ($filter) {
            'panel' => $query->whereNull('catalog_id'),
            'no_copies' => $query->where('manuscripts_count', 0),
            default => null,
        };
        $works = $query->orderByDesc('id')->paginate(25)->withQueryString();

        return view('admin.works.index', compact('works', 'search', 'filter'));
    }

    public function create(Request $request)
    {
        $picked = [];
        foreach (['author_id' => Person::class, 'source_id' => Source::class] as $field => $class) {
            $id = (int) $request->old($field);
            $picked[$field] = $id && ($record = $class::find($id)) ? SuggestionRegistry::describe($record) : null;
        }

        return view('admin.works.create', [
            'picked' => $picked,
            'duplicates' => session('duplicates') ? Work::whereIn('id', session('duplicates'))->get() : collect(),
            'namesakes' => session('namesakes') ? Person::whereIn('id', session('namesakes'))->orderByDesc('works_count')->get() : collect(),
            'subjects' => DB::table('subjects')->orderByDesc('works_count')->pluck('name'),
            'languages' => DB::table('languages')->orderByDesc('works_count')->pluck('name'),
        ]);
    }

    public function store(Request $request, RecordCreator $creator)
    {
        $data = $request->validate(self::rules());
        if (! empty($data['author_new']) && filled($data['author_id'] ?? null)) {
            return back()->withInput()->with('error', 'پدیدآور یا شخص سایت است یا شخص تازه؛ هر دو را با هم نمی‌شود.');
        }

        // works of the same title by the same author, and people of the new author's name: the editor sees them and says again
        $duplicates = $creator->workDuplicates($data['title'], preg_split('/\R/u', (string) ($data['alternative_titles'] ?? '')), isset($data['author_id']) ? (int) $data['author_id'] : null);
        $namesakes = ! empty($data['author_new']) ? $creator->personDuplicates($data['author_name'] ?? null) : collect();
        if (($duplicates->isNotEmpty() || $namesakes->isNotEmpty()) && ! $request->boolean('confirm_duplicate')) {
            return back()->withInput()->with(['duplicates' => $duplicates->pluck('id')->all(), 'namesakes' => $namesakes->pluck('id')->all()]);
        }

        try {
            $work = $creator->createWork($data, $request->user()->id, $request->ip());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $unlinked = array_merge(RecordCreator::terms($work->subject_summary), RecordCreator::terms($work->language_summary));
        $unlinked = array_values(array_diff($unlinked, $work->subjects()->pluck('name')->all(), $work->languages()->pluck('name')->all()));

        return redirect()->route('works.show', $work)
            ->with('success', 'رکورد اثر ساخته شد.'
                . ($unlinked ? ' این موضوع‌ها یا زبان‌ها در سایت نیستند و فقط به‌صورت متن ثبت شدند: ' . implode('، ', $unlinked) . '.' : '')
                . ' از لاگ ممیزی بازگرداندنی است.');
    }

    public static function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'alternative_titles' => 'nullable|string|max:5000',
            'transliteration' => 'nullable|string|max:255',
            'author_id' => 'nullable|integer|exists:people,id',
            'author_name' => 'nullable|required_if:author_new,1|string|max:500',
            'author_new' => 'nullable|boolean',
            'author_death_year' => 'nullable|integer|between:1,1500',
            'composition_date_raw' => 'nullable|string|max:300',
            'composition_place' => 'nullable|string|max:300',
            'subject_summary' => 'nullable|string|max:500',
            'language_summary' => 'nullable|string|max:500',
            'work_form' => 'nullable|string|max:100',
            'incipit_text' => 'nullable|string|max:5000',
            'explicit_text' => 'nullable|string|max:5000',
            'description' => 'nullable|string|max:20000',
            'source_id' => 'nullable|integer|exists:sources,id',
            'source_place' => 'nullable|required_with:source_id|string|max:60',
            'source_text' => 'nullable|string|max:300',
        ];
    }
}
