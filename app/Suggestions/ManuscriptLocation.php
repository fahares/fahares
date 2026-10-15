<?php

namespace App\Suggestions;

use App\Models\Library;
use App\Models\Manuscript;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Applies the relational suggestion «relocated»: the copy moved to another library or was given a
 * new shelfmark. The former location stays on the record (metadata.former_locations), since older
 * catalogs and studies cite it. Returns what the audit log needs to undo it.
 */
class ManuscriptLocation
{
    /**
     * @param  Library|null  $library  the new library; null keeps the current one
     * @param  string|null  $shelfmark  the new shelfmark; null keeps the current one
     * @return array{old: array, new: array, resolution: array}
     */
    public function relocate(Manuscript $manuscript, ?Library $library, ?string $shelfmark, ?int $suggestionId = null): array
    {
        $shelfmark = trim((string) $shelfmark) ?: null;
        $libraryChanged = $library && (int) $manuscript->library_id !== $library->id;
        $shelfmarkChanged = $shelfmark !== null && $shelfmark !== $manuscript->shelfmark;
        if (! $libraryChanged && ! $shelfmarkChanged) {
            throw new InvalidArgumentException('کتابخانه و شماره‌ی تازه همان نشانی کنونی نسخه است.');
        }

        $meta = $manuscript->metadata ?? [];
        $meta['former_locations'] = array_values(array_merge((array) ($meta['former_locations'] ?? []), [array_filter([
            'library_id' => $manuscript->library_id,
            'library' => $manuscript->libraryRecord?->name ?? $manuscript->library,
            'city' => $manuscript->city,
            'shelfmark' => $manuscript->shelfmark,
            'suggestion_id' => $suggestionId,
            'until' => now()->toDateString(),
        ], fn ($v) => $v !== null && $v !== '')]));
        $manuscript->metadata = $meta;

        $previousLibrary = $manuscript->library_id;
        if ($libraryChanged) {
            $manuscript->forceFill(['library_id' => $library->id, 'library' => $library->name, 'city' => $library->city]);
        }
        if ($shelfmarkChanged) {
            $manuscript->shelfmark = $shelfmark;
        }

        $old = array_intersect_key($manuscript->getOriginal(), $manuscript->getDirty());
        $manuscript->save();
        $new = $manuscript->getChanges();
        unset($new['updated_at']);

        self::recountLibraries([$previousLibrary, $manuscript->library_id]);

        return [
            'old' => $old,
            'new' => $new,
            'resolution' => array_filter(['library_id' => $libraryChanged ? $library->id : null, 'shelfmark' => $shelfmarkChanged ? $shelfmark : null]),
        ];
    }

    /** A library the reviewer adds because the copy moved to one fahares does not list yet. */
    public function createLibrary(string $name, string $city, ?string $country = null): Library
    {
        $name = trim($name);
        $city = trim($city);
        if ($name === '' || $city === '') {
            throw new InvalidArgumentException('برای ساخت کتابخانه‌ی تازه، نام و شهر لازم است.');
        }
        if ($existing = Library::where('city', $city)->where('name', $name)->first()) {
            throw new InvalidArgumentException("این کتابخانه پیش‌تر ثبت شده است (#{$existing->id}). آن را با شناسه انتخاب کنید.");
        }

        $library = Library::create(['name' => $name, 'city' => $city, 'country' => trim((string) $country) ?: 'ایران', 'slug' => 'library-' . Str::random(12)]);
        $library->update(['slug' => mb_substr(Str::slug($name . ' ' . $city, '-', null) ?: 'library', 0, 180) . '-' . $library->id]);

        return $library;
    }

    public static function recountLibraries(array $ids): void
    {
        foreach (array_unique(array_filter($ids)) as $id) {
            DB::table('libraries')->where('id', $id)->update(['manuscripts_count' => Manuscript::where('library_id', $id)->count()]);
        }
    }
}
