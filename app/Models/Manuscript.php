<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Manuscript extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'volume_number' => 'integer',
            'page_start' => 'integer',
            'page_end' => 'integer',
            'is_bika' => 'boolean',
            'is_bita' => 'boolean',
            'is_autograph' => 'boolean',
            'copy_date_hijri_year' => 'integer',
            'folios' => 'integer',
            'lines' => 'integer',
            'is_corrected' => 'boolean',
            'has_marginal_notes' => 'boolean',
            'is_ruled' => 'boolean',
            'has_catchwords' => 'boolean',
            'is_facsimile' => 'boolean',
            'is_collated' => 'boolean',
            'is_illuminated' => 'boolean',
            'is_illustrated' => 'boolean',
            'has_author_marginalia' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function scribe(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'scribe_id');
    }

    public function scripts(): BelongsToMany
    {
        return $this->belongsToMany(Script::class)->withPivot('is_primary');
    }

    public function fieldSuggestions(): MorphMany
    {
        return $this->morphMany(FieldSuggestion::class, 'suggestable');
    }

    public function scholarlyAnnotations(): MorphMany
    {
        return $this->morphMany(ScholarlyAnnotation::class, 'annotatable');
    }
}
