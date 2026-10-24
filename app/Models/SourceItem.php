<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One printed entry of a source (plan docs/plans/dharia-text.md): its place, number, title, author as printed and
 * text. Records are tied to it through source_entries.source_item_id.
 */
class SourceItem extends Model
{
    public const KIND_ENTRY = 'entry';

    public const KIND_SUB_ENTRY = 'sub_entry';

    public const KIND_ADDENDUM = 'addendum';

    public const TEXT_AUTO = 'auto';

    public const TEXT_EDITED = 'edited';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'volume_number' => 'integer',
            'position' => 'integer',
            'entry_number' => 'integer',
            'page_start' => 'integer',
            'page_end' => 'integer',
            'author_death_year' => 'integer',
            'flags' => 'array',
            'metadata' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function sourceVolume(): BelongsTo
    {
        return $this->belongsTo(SourceVolume::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function refs(): HasMany
    {
        return $this->hasMany(SourceItemRef::class)->orderBy('position');
    }

    public function links(): HasMany
    {
        return $this->hasMany(SourceEntry::class);
    }
}
