<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
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

    public function merge(Request $request)
    {
        if ($request->isMethod('GET')) {
            return view('admin.people.merge');
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

        DB::transaction(function () use ($source, $target, $request) {
            // Capture snapshot of source person before changes
            $sourceSnapshot = $source->getAttributes();
            foreach ($sourceSnapshot as $k => $v) {
                if (is_array($v)) {
                    $sourceSnapshot[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
                }
            }

            // Identify exact works and manuscripts to transfer
            $transferredWorkIds = Work::where('author_id', $source->id)->pluck('id')->toArray();
            $transferredManuscriptIds = Manuscript::where('scribe_id', $source->id)->pluck('id')->toArray();

            // 1. Transfer Works (as author)
            Work::whereIn('id', $transferredWorkIds)->update([
                'author_id' => $target->id,
                'author_name' => $target->name,
            ]);

            // 2. Transfer Manuscripts (as scribe)
            Manuscript::whereIn('id', $transferredManuscriptIds)->update([
                'scribe_id' => $target->id,
                'scribe_name' => $target->name,
            ]);

            // 3. Recount works and manuscripts for target
            $newWorksCount = Work::where('author_id', $target->id)->count();
            $newManuscriptsCount = Manuscript::where('scribe_id', $target->id)->count();

            $target->update([
                'works_count' => $newWorksCount,
                'manuscripts_count' => $newManuscriptsCount,
                'is_author' => $target->is_author || ($newWorksCount > 0),
                'is_scribe' => $target->is_scribe || ($newManuscriptsCount > 0),
            ]);

            if (method_exists($target, 'searchable')) {
                $target->searchable();
            }

            // 4. Audit Log with full snapshot & exact transferred IDs
            AuditLog::create([
                'user_id' => auth()->id(),
                'auditable_type' => Person::class,
                'auditable_id' => $target->id,
                'action' => 'merge',
                'old_values' => [
                    'source_id' => $source->id,
                    'source_name' => $source->name,
                    'source_snapshot' => $sourceSnapshot,
                    'transferred_work_ids' => $transferredWorkIds,
                    'transferred_manuscript_ids' => $transferredManuscriptIds,
                    'works_transferred' => count($transferredWorkIds),
                    'manuscripts_transferred' => count($transferredManuscriptIds),
                ],
                'new_values' => [
                    'target_id' => $target->id,
                    'target_name' => $target->name,
                    'new_works_count' => $newWorksCount,
                    'new_manuscripts_count' => $newManuscriptsCount,
                ],
                'ip_address' => $request->ip(),
            ]);

            // 5. Delete source person
            $source->delete();
        });

        return redirect()->route('admin.people.index')
            ->with('success', "شخص تکراری [{$source->name} #{$source->id}] با موفقیت در [{$target->name} #{$target->id}] ادغام شد.");
    }
}
