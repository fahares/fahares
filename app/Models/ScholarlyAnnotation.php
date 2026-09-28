<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ScholarlyAnnotation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sync_to_corpus' => 'boolean',
            'is_synced' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    public function annotatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(FieldSuggestion::class, 'suggestion_id');
    }
}
