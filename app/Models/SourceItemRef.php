<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reference printed in a source entry to another place of the same source («12 : 204»). */
class SourceItemRef extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'target_volume' => 'integer',
            'target_page' => 'integer',
            'target_page_end' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SourceItem::class, 'source_item_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(SourceItem::class, 'target_item_id');
    }
}
