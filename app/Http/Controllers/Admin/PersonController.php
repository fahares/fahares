<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Person;
use App\Services\PersonMerger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PersonController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->query('q');
        $role = $request->query('role');
        $century = $request->query('century');

        $query = Person::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('normalized_name', 'like', "%{$search}%");
            });
        }

        if ($role === 'author') {
            $query->where('is_author', true);
        } elseif ($role === 'scribe') {
            $query->where('is_scribe', true);
        }

        if ($century) {
            $query->where('century_hijri', $century);
        }

        $people = $query->orderByDesc('works_count')
            ->orderByDesc('manuscripts_count')
            ->paginate(25)
            ->withQueryString();

        return view('admin.people.index', compact('people', 'search', 'role', 'century'));
    }

    public function edit(Person $person)
    {
        return view('admin.people.edit', compact('person'));
    }

    public function update(Request $request, Person $person)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:600',
            'birth_year_hijri' => 'nullable|integer',
            'death_year_hijri' => 'nullable|integer',
            'century_hijri' => 'nullable|integer|between:1,15',
            'death_year_gregorian' => 'nullable|integer',
            'transliteration' => 'nullable|string',
            'bio_notes' => 'nullable|string',
            'is_author' => 'boolean',
            'is_scribe' => 'boolean',
        ]);

        $oldValues = $person->toArray();

        $person->update([
            'name' => $validated['name'],
            'normalized_name' => preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $validated['name']),
            'birth_year_hijri' => $validated['birth_year_hijri'] ?? null,
            'death_year_hijri' => $validated['death_year_hijri'] ?? null,
            'century_hijri' => $validated['century_hijri'] ?? null,
            'death_year_gregorian' => $validated['death_year_gregorian'] ?? null,
            'transliteration' => $validated['transliteration'] ?? null,
            'bio_notes' => $validated['bio_notes'] ?? null,
            'is_author' => $request->boolean('is_author'),
            'is_scribe' => $request->boolean('is_scribe'),
        ]);

        if (method_exists($person, 'searchable')) {
            $person->searchable();
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => Person::class,
            'auditable_id' => $person->id,
            'action' => 'update',
            'old_values' => $oldValues,
            'new_values' => $person->toArray(),
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('admin.people.index')
            ->with('success', "مشخصات پدیدآور [{$person->name}] با موفقیت به‌روزرسانی شد.");
    }

    public function search(Request $request)
    {
        $q = trim($request->input('q', ''));
        if (empty($q)) {
            return response()->json([]);
        }

        // Exact match by numeric ID
        if (is_numeric($q)) {
            $person = Person::find((int) $q);
            if ($person) {
                return response()->json([
                    $this->formatPersonResult($person),
                ]);
            }
        }

        // Full-text search via Meilisearch with database fallback and merging
        $scoutHits = collect();
        try {
            $scoutHits = Person::search($q)->take(12)->get();
        } catch (\Throwable $e) {
            // Meilisearch offline or unreachable
        }

        $dbMatches = Person::where(function ($query) use ($q) {
            $query->where('name', 'like', "%{$q}%")
                  ->orWhere('normalized_name', 'like', "%{$q}%");
        })
        ->orderByDesc('works_count')
        ->take(12)
        ->get();

        $people = $scoutHits->concat($dbMatches)->unique('id')->take(12);

        return response()->json($people->map(fn($p) => $this->formatPersonResult($p))->values());
    }

    public function merge(Request $request)
    {
        if ($request->isMethod('GET')) {
            $preloadedSource = null;
            $preloadedTarget = null;

            if ($request->filled('source_id')) {
                $source = Person::find($request->query('source_id'));
                if ($source) {
                    $preloadedSource = $this->formatPersonResult($source);
                }
            }

            if ($request->filled('target_id')) {
                $target = Person::find($request->query('target_id'));
                if ($target) {
                    $preloadedTarget = $this->formatPersonResult($target);
                }
            }

            $suggestionId = $request->integer('suggestion_id') ?: null;

            return view('admin.people.merge', compact('preloadedSource', 'preloadedTarget', 'suggestionId'));
        }

        $validated = $request->validate([
            'source_id' => 'required|integer|exists:people,id|different:target_id',
            'target_id' => 'required|integer|exists:people,id',
        ], [
            'source_id.required' => 'شناسه شخص مبدأ الزامی است.',
            'target_id.required' => 'شناسه شخص مقصد الزامی است.',
            'source_id.different' => 'شخص مبدأ و مقصد نمی‌توانند یکسان باشند.',
            'source_id.exists' => 'شخص مبدأ یافت نشد.',
            'target_id.exists' => 'شخص مقصد یافت نشد.',
        ]);

        $source = Person::findOrFail($validated['source_id']);
        $target = Person::findOrFail($validated['target_id']);

        // Opened from a "same person" suggestion: the merge closes it, whichever record is kept
        $suggestion = FieldSuggestion::where('id', (int) $request->input('suggestion_id'))
            ->where('status', 'pending')->where('kind', 'relation')->where('suggestable_type', Person::class)->first();
        if ($suggestion && ! in_array($suggestion->suggestable_id, [$source->id, $target->id], true)) {
            $suggestion = null;
        }

        DB::transaction(function () use ($source, $target, $request, $suggestion) {
            app(PersonMerger::class)->merge($source, $target, auth()->id(), $request->ip(),
                $suggestion ? ['suggestion_id' => $suggestion->id] : []);

            $suggestion?->update([
                'status' => 'approved',
                'resolution' => ['applied' => true, 'kept_id' => $target->id, 'removed_id' => $source->id],
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);
        });

        return redirect()->route('admin.people.index')
            ->with('success', "شخص تکراری [{$source->name} #{$source->id}] با موفقیت در [{$target->name} #{$target->id}] ادغام شد.");
    }

    protected function formatPersonResult(Person $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'century_hijri' => $p->century_hijri,
            'death_year_hijri' => $p->death_year_hijri,
            'works_count' => (int) ($p->works_count ?? 0),
            'manuscripts_count' => (int) ($p->manuscripts_count ?? 0),
            'is_author' => (bool) $p->is_author,
            'is_scribe' => (bool) $p->is_scribe,
            'url' => route('people.show', $p),
        ];
    }
}
