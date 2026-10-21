<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogVolume extends Model
{
    protected $guarded = ['id'];

    /** Where a scan came from is kept for the record only and never reaches the site or an export. */
    protected $hidden = ['source_url'];

    protected function casts(): array
    {
        return [
            'volume_number' => 'integer',
            'pages_count' => 'integer',
            'manuscripts_count' => 'integer',
            'page_first' => 'integer',
            'page_last' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function getCoverImageUrlAttribute(): ?string
    {
        if ($this->cover_image_path) {
            if (str_starts_with($this->cover_image_path, 'http://') || str_starts_with($this->cover_image_path, 'https://')) {
                return $this->cover_image_path;
            }
            return asset('storage/' . ltrim($this->cover_image_path, '/'));
        }

        return null;
    }

    public function catalogers(): BelongsToMany
    {
        return $this->belongsToMany(Cataloger::class, 'catalog_volume_cataloger')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function primaryCataloger()
    {
        return $this->catalogers()->wherePivot('role', 'cataloger')->first() ?? $this->catalogers()->first();
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    public function manuscripts(): HasMany
    {
        return $this->hasMany(Manuscript::class);
    }

    /** The volume Fankha cites, of which this row is another printing (backlog item 51). */
    public function editionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'edition_of_id');
    }

    public function editions(): HasMany
    {
        return $this->hasMany(self::class, 'edition_of_id');
    }

    public function catalogReferences(): HasMany
    {
        return $this->hasMany(ManuscriptCatalogReference::class);
    }

    /** The title without the trailing « - جلد دوم», as the viewer header and the link show it. */
    public function getShortTitleAttribute(): string
    {
        return trim(preg_replace('/\s*[-–]\s*جلد\s+\S+\s*$/u', '', (string) $this->title));
    }
}
