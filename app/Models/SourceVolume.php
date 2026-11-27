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

    /**
     * The place of this row among the rows of one printed volume, read from its title: 1 for «جلد ۱/۴۰» (the part
     * before the slash, the volume after it, or the other way round) and for «جلد نهم - بخش اول», 0 when the title
     * names no part. The rows are made as their scans or citations turn up, so their ids are in no order (step 14.440:
     * the Majlis catalogue listed 40/2, 40/3, 40/1).
     */
    public function partOrder(): int
    {
        $title = strtr((string) $this->title, array_combine(['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'], range(0, 9)));
        if (preg_match('~جلد\s*(\d+)\s*/\s*(\d+)~u', $title, $m)) {
            return (int) $m[1] === (int) $this->volume_number ? (int) $m[2] : (int) $m[1];
        }
        if (preg_match('~بخش\s*(\d+|اول|نخست|دوم|سوم|چهارم|پنجم|ششم)~u', $title, $m)) {
            return ['اول' => 1, 'نخست' => 1, 'دوم' => 2, 'سوم' => 3, 'چهارم' => 4, 'پنجم' => 5, 'ششم' => 6][$m[1]] ?? (int) $m[1];
        }

        return 0;
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

    /** The catalogues of the libraries the volume holds, under its catalog volume's id (step 13.150). */
    public function parts(): HasMany
    {
        return $this->hasMany(CatalogVolumePart::class, 'catalog_volume_id')->orderBy('sort')->orderBy('id');
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
