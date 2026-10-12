<?php

namespace App\Models\Concerns;

use App\Models\SourceCitation;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Citations of a record beyond its primary catalog entry (addenda, errata, other catalogs).
 * Expects catalog_id, volume_number and page_start columns plus a `catalog` relation.
 */
trait HasSourceCitations
{
    public function sourceCitations(): MorphMany
    {
        return $this->morphMany(SourceCitation::class, 'citable')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Whether the record's primary entry sits in a catalog's addenda section (see config/catalogs.php).
     */
    public function getIsAddendumEntryAttribute(): bool
    {
        $code = $this->catalog?->code ?? 'fankha';

        foreach (config("catalogs.addenda.{$code}", []) as $range) {
            if ((int) $this->volume_number === $range['volume']
                && $this->page_start >= $range['from']
                && $this->page_start <= $range['to']) {
                return true;
            }
        }

        return false;
    }
}
