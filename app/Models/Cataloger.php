<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Cataloger extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'birth_year_solar' => 'integer',
            'death_year_solar' => 'integer',
            'birth_year_hijri' => 'integer',
            'death_year_hijri' => 'integer',
            'manuscripts_count' => 'integer',
            'volumes_count' => 'integer',
            'supervised_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function getPersianSlugAttribute(): string
    {
        $slug = Str::slug($this->name, '-', null);
        if (mb_strlen($slug) > 75) {
            $slug = mb_substr($slug, 0, 75);
            $lastHyphen = mb_strrpos($slug, '-');
            if ($lastHyphen > 30) {
                $slug = mb_substr($slug, 0, $lastHyphen);
            }
        }
        return $slug ?: 'cataloger';
    }

    public function getRouteKey(): string
    {
        $slug = $this->persian_slug;
        return $slug ? "{$this->id}-{$slug}" : (string) $this->id;
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->name;
    }

    public function getLifeYearsTextAttribute(): ?string
    {
        if ($this->birth_year_solar && $this->death_year_solar) {
            return "{$this->birth_year_solar} – {$this->death_year_solar} هـ.ش";
        }

        if ($this->death_year_solar) {
            return "وفات {$this->death_year_solar} هـ.ش";
        }

        if ($this->birth_year_solar) {
            return "متولد {$this->birth_year_solar} هـ.ش";
        }

        if ($this->death_year_hijri) {
            return "وفات {$this->death_year_hijri} هـ.ق";
        }

        return null;
    }

    public function getAvatarUrlAttribute(): ?string
    {
        if ($this->avatar_path) {
            if (str_starts_with($this->avatar_path, 'http://') || str_starts_with($this->avatar_path, 'https://')) {
                return $this->avatar_path;
            }
            $clean = ltrim($this->avatar_path, '/');
            $publicFile = public_path('images/' . $clean);
            if (file_exists($publicFile)) {
                return asset('images/' . $clean) . '?v=' . filemtime($publicFile);
            }
            $storageFile = public_path('storage/' . $clean);
            if (file_exists($storageFile)) {
                return asset('storage/' . $clean) . '?v=' . filemtime($storageFile);
            }
            // A path whose file is gone (an upload lost in a redeploy) falls through to the
            // placeholder instead of a broken image.
        }

        if ($this->mtif_entry_id) {
            $publicFile = public_path("images/catalogers/avatars/{$this->mtif_entry_id}.jpg");
            if (file_exists($publicFile)) {
                return asset("images/catalogers/avatars/{$this->mtif_entry_id}.jpg") . '?v=' . filemtime($publicFile);
            }
        }

        return null;
    }

    public function catalogVolumes(): BelongsToMany
    {
        return $this->belongsToMany(CatalogVolume::class, 'catalog_volume_cataloger')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** The parts of volumes this cataloger wrote (step 13.150). */
    public function catalogVolumeParts(): BelongsToMany
    {
        return $this->belongsToMany(CatalogVolumePart::class, 'catalog_volume_part_cataloger')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function manuscripts(): HasMany
    {
        return $this->hasMany(Manuscript::class);
    }

    /**
     * The copies this cataloger catalogued: those that name them, those of their volumes without parts, and those of
     * the parts they wrote. A volume with parts reaches its copies through them: its own people are its editors
     * (supervisedCopies).
     */
    public function catalogedCopies(): Builder
    {
        [$volumes, $parted] = $this->volumeIds();
        $parts = CatalogVolumePart::ready() ? $this->catalogVolumeParts()->pluck('catalog_volume_parts.id') : collect();

        return Manuscript::where(fn ($q) => $q->where('cataloger_id', $this->id)
            ->orWhereIn('catalog_volume_id', $volumes->diff($parted)->values())
            ->orWhereIn('catalog_volume_part_id', $parts));
    }

    /** The copies of the volumes with parts this cataloger is on as a whole: edited or supervised by them. */
    public function supervisedCopies(): Builder
    {
        [$volumes, $parted] = $this->volumeIds();

        return Manuscript::whereIn('catalog_volume_id', $volumes->intersect($parted)->values());
    }

    /** @return array{0: Collection<int, int>, 1: Collection<int, int>} this cataloger's volumes, and those of them with parts */
    private function volumeIds(): array
    {
        $volumes = $this->catalogVolumes()->pluck('catalog_volumes.id')->map(fn ($id) => (int) $id);
        $parted = $volumes->isEmpty() || ! CatalogVolumePart::ready() ? collect() : DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $volumes)->distinct()->pluck('catalog_volume_id')->map(fn ($id) => (int) $id);

        return [$volumes, $parted];
    }

    /**
     * Takes again the volumes, copies and edited copies of the catalogers given (all when null): the one count every
     * command uses (LinkCatalogVolumesCommand::recountVolumesAndCatalogers and the data commands of step 13).
     *
     * @param  iterable<int>|null  $ids
     */
    public static function recount(?iterable $ids = null): void
    {
        $query = self::query()->when($ids !== null, fn ($q) => $q->whereIn('id', collect($ids)->unique()->values()));
        $query->each(function (self $cataloger) {
            $volumes = $cataloger->catalogVolumes()->pluck('catalog_volumes.id')
                ->merge($cataloger->catalogVolumeParts()->pluck('catalog_volume_parts.catalog_volume_id'))->unique();
            DB::table('catalogers')->where('id', $cataloger->id)->update([
                'volumes_count' => $volumes->count(),
                'manuscripts_count' => $cataloger->catalogedCopies()->count(),
                'supervised_count' => $cataloger->supervisedCopies()->count(),
            ]);
        });
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
