<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One volume of a source (step 13.1); volumes copied from catalog_volumes keep its ids. facsimile_code names the
 * folder of its page images under storage/app/public/catalogs, and source_url is never shown on the site.
 */
class SourceVolume extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'volume_number' => 'integer',
            'pages_count' => 'integer',
            'page_first' => 'integer',
            'page_last' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** Who catalogued the volume, and under whose supervision: a source volume shares its id with its catalog volume. */
    public function catalogers(): BelongsToMany
    {
        return $this->belongsToMany(Cataloger::class, 'catalog_volume_cataloger', 'catalog_volume_id', 'cataloger_id')->withPivot('role');
    }

    public function editionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'edition_of_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(SourceEntry::class);
    }
}
