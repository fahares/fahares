<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Library extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // A renamed, merged or removed library is found by its new names in searches at once (DirectAnswer)
        static::saved(fn () => \App\Support\DirectAnswer::forgetLibraries());
        static::deleted(fn () => \App\Support\DirectAnswer::forgetLibraries());
    }

    protected function casts(): array
    {
        return [
            'manuscripts_count' => 'integer',
            'aliases' => 'array',
            'logo_on_dark' => 'boolean',
            'logo_width' => 'integer',
            'logo_height' => 'integer',
        ];
    }

    /** Official logo on the public disk (the persistent volume in production); null when none or its file is gone. */
    public function getLogoUrlAttribute(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }
        $clean = ltrim($this->logo_path, '/');
        $disk = \Illuminate\Support\Facades\Storage::disk('public');

        return $disk->exists($clean) ? asset('storage/' . $clean) . '?v=' . $disk->lastModified($clean) : null;
    }

    /** Saves $file as this library's logo on the public disk and drops the previous file. */
    public function storeLogo(\Illuminate\Http\File|\Illuminate\Http\UploadedFile $file): void
    {
        $old = $this->logo_path;
        $ext = strtolower($file->extension() ?: 'png');
        [$this->logo_width, $this->logo_height] = @getimagesize($file->getRealPath()) ?: [null, null];
        $this->logo_path = \Illuminate\Support\Facades\Storage::disk('public')
            ->putFileAs('libraries/logos', $file, "library_{$this->id}_" . now()->timestamp . ".{$ext}");
        $this->save();
        if ($old && $old !== $this->logo_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($old);
        }
    }

    /**
     * Largest CSS height (px) the logo can take and stay sharp on a 2× screen, capped at $want;
     * $want when the file's size is unknown.
     */
    public function logoMaxHeight(int $want): int
    {
        return $this->logo_height ? min($want, intdiv($this->logo_height, 2)) : $want;
    }

    public function removeLogo(): void
    {
        if ($this->logo_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($this->logo_path);
        }
        $this->update(['logo_path' => null, 'logo_width' => null, 'logo_height' => null]);
    }

    // Other names are matched with LIKE, so Persian text is stored as is, not as \u escapes
    protected function asJson($value, $flags = 0)
    {
        return parent::asJson($value, $flags | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Manuscripts carry a copy of the library's name and city (shown in lists and searched in the
     * manuscripts index); after a rename they are brought in step and re-indexed.
     */
    public function syncManuscriptCopies(): void
    {
        $this->manuscripts()->where(fn ($q) => $q->where('library', '!=', $this->name)->orWhereNull('library')->orWhere('city', '!=', $this->city)->orWhereNull('city'))
            ->update(['library' => $this->name, 'city' => $this->city]);
        $this->manuscripts()->searchable();
    }

    /** True when $name is already this library's name, official name or one of its other names. */
    public function knowsName(string $name): bool
    {
        $key = \App\Helpers\TextNormalizer::normalize($name);
        foreach (array_merge([$this->name, $this->full_name], (array) ($this->aliases ?? [])) as $known) {
            if ($known && \App\Helpers\TextNormalizer::normalize($known) === $key) {
                return true;
            }
        }

        return false;
    }

    /** Official name, for introductions, titles, metadata and citations; the short name when none is recorded. */
    public function getDisplayNameAttribute(): string
    {
        return $this->full_name ?: $this->name;
    }

    /** Short name, for compact lists (manuscript tables, search results, facets); pair it with display_name as a tooltip. */
    public function getShortNameAttribute(): string
    {
        return $this->name;
    }

    public function getPersianSlugAttribute(): string
    {
        $name = $this->full_name ?: ($this->name . ($this->city ? ' ' . $this->city : ''));
        $slug = \Illuminate\Support\Str::slug($name, '-', null);
        if (mb_strlen($slug) > 75) {
            $slug = mb_substr($slug, 0, 75);
            $lastHyphen = mb_strrpos($slug, '-');
            if ($lastHyphen > 30) {
                $slug = mb_substr($slug, 0, $lastHyphen);
            }
        }
        return $slug ?: 'library';
    }

    public function getRouteKey(): string
    {
        $slug = $this->persian_slug;
        return $slug ? "{$this->id}-{$slug}" : (string) $this->id;
    }

    public function manuscripts(): HasMany
    {
        return $this->hasMany(Manuscript::class);
    }

    public function catalogVolumes(): HasMany
    {
        return $this->hasMany(CatalogVolume::class);
    }
}
