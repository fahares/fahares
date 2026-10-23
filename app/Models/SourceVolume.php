<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function editionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'edition_of_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(SourceEntry::class);
    }
}
