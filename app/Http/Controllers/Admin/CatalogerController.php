<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Cataloger;
use App\Models\Person;
use Illuminate\Http\Request;

/**
 * The catalogers of the panel: list, create (backlog item 40) and edit. One form serves both, with the profile fields
 * of the public page: title, nickname, years, bio, avatar, the link to a person, and the profile kept in metadata.
 */
class CatalogerController extends Controller
{
    /** The metadata keys the form edits; any other key (external_links, made_by …) is kept as it is */
    public const TEXT_META = ['specialty' => 'تخصص', 'birth_place' => 'زادگاه', 'death_place' => 'محل درگذشت', 'resting_place' => 'آرامگاه'];

    public const LIST_META = ['institutions' => 'نهادهای محل خدمت', 'major_catalogs' => 'فهرست‌های شاخص'];

    public function index()
    {
        $catalogers = Cataloger::orderByDesc('manuscripts_count')->get();
        return view('admin.catalogers.index', compact('catalogers'));
    }

    public function create()
    {
        return view('admin.catalogers.edit', ['cataloger' => new Cataloger()]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        $cataloger = new Cataloger(['slug' => Cataloger::uniqueSlug($validated['name'])]);
        $this->fill($cataloger, $validated, $request);
        $cataloger->save();
        $this->storeAvatar($request, $cataloger);

        $this->audit($request, $cataloger, 'create', null);

        return redirect()->route('admin.catalogers.edit', $cataloger)
            ->with('success', "فهرست‌نگار [{$cataloger->name}] ساخته شد.");
    }

    public function edit(Cataloger $cataloger)
    {
        return view('admin.catalogers.edit', compact('cataloger'));
    }

    public function update(Request $request, Cataloger $cataloger)
    {
        $validated = $this->validated($request);
        $oldValues = $cataloger->toArray();

        $this->fill($cataloger, $validated, $request);
        $cataloger->save();
        $this->storeAvatar($request, $cataloger);

        $this->audit($request, $cataloger, 'update', $oldValues);

        return redirect()->route('admin.catalogers.index')
            ->with('success', "مشخصات فهرست‌نگار [{$cataloger->name}] با موفقیت به‌روزرسانی شد.");
    }

    private function validated(Request $request): array
    {
        $year = 'nullable|integer|min:1|max:2100';

        return $request->validate([
            'name' => 'required|string|max:255',
            'title_prefix' => 'nullable|string|max:100',
            'nickname' => 'nullable|string|max:150',
            'birth_year_solar' => $year,
            'death_year_solar' => $year,
            'birth_year_hijri' => $year,
            'death_year_hijri' => $year,
            'bio' => 'nullable|string',
            'avatar' => 'nullable|image|max:3072',
            'person_id' => 'nullable|integer|exists:people,id',
            'meta' => 'nullable|array',
            'meta.*' => 'nullable|string|max:5000',
        ], [
            'person_id.exists' => 'شخصی با این شناسه نیست.',
        ]);
    }

    private function fill(Cataloger $cataloger, array $validated, Request $request): void
    {
        foreach (['name', 'title_prefix', 'nickname', 'birth_year_solar', 'death_year_solar', 'birth_year_hijri', 'death_year_hijri', 'bio', 'person_id'] as $column) {
            $value = $validated[$column] ?? null;
            $cataloger->{$column} = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        }

        $meta = $cataloger->metadata ?? [];
        $input = $validated['meta'] ?? [];
        foreach (array_keys(self::TEXT_META) as $key) {
            $meta[$key] = trim((string) ($input[$key] ?? '')) ?: null;
        }
        foreach (array_keys(self::LIST_META) as $key) {
            $meta[$key] = self::lines($input[$key] ?? '') ?: null;
        }
        $meta['sources'] = array_map(self::parseSource(...), self::lines($input['sources'] ?? '')) ?: null;
        $meta = array_filter($meta, fn ($v) => $v !== null);
        $cataloger->metadata = $meta ?: null;
    }

    // Avatars go to the public disk: in production that is the persistent volume, while
    // public/images is rebuilt from git on every deploy and would lose the upload.
    private function storeAvatar(Request $request, Cataloger $cataloger): void
    {
        if (! $request->hasFile('avatar')) {
            return;
        }
        $file = $request->file('avatar');
        $filename = "cataloger_{$cataloger->id}_" . time() . '.' . $file->extension();
        $cataloger->avatar_path = $file->storeAs('catalogers/avatars', $filename, 'public');
        $cataloger->save();
    }

    private function audit(Request $request, Cataloger $cataloger, string $action, ?array $oldValues): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => Cataloger::class,
            'auditable_id' => $cataloger->id,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $cataloger->toArray(),
            'ip_address' => $request->ip(),
        ]);
    }

    /** @return list<string> the non-empty lines of a textarea */
    public static function lines(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $text)), fn ($l) => $l !== ''));
    }

    /** «عنوان | https://…» is a linked source; a line without a link stays text, as the public page shows it */
    public static function parseSource(string $line): string|array
    {
        if (preg_match('~^(.*?)\s*\|\s*(https?://\S+)$~u', $line, $m)) {
            return ['title' => trim($m[1]) ?: $m[2], 'url' => $m[2]];
        }
        if (preg_match('~^https?://\S+$~u', $line)) {
            return ['title' => $line, 'url' => $line];
        }

        return $line;
    }

    /** The form's text for the sources: one per line, a link after «|» */
    public static function sourcesText(array $sources): string
    {
        return implode("\n", array_map(fn ($s) => is_array($s) ? trim(($s['title'] ?? '') . ' | ' . ($s['url'] ?? ''), ' |') : (string) $s, $sources));
    }

    /** The linked person, for the form */
    public static function personLabel(?int $id): ?string
    {
        $person = $id ? Person::find($id) : null;

        return $person ? $person->name . ($person->life_span ? ' (' . $person->life_span . ')' : '') : null;
    }
}
