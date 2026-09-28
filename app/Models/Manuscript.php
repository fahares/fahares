<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Scout\Searchable;

class Manuscript extends Model
{
    use Searchable;

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

    public function getAccessionNumberAttribute(): ?string
    {
        return $this->shelfmark;
    }

    public function searchableAs(): string
    {
        return 'manuscripts_index';
    }

    public function makeAllSearchableUsing($query)
    {
        return $query->with(['work:id,primary_title,clean_title,author_name', 'scripts:id,name']);
    }

    public function toSearchableArray(): array
    {
        $workTitle = null;
        $authorName = null;
        if ($this->relationLoaded('work') && $this->work) {
            $workTitle = $this->work->clean_title ?: $this->work->primary_title;
            $authorName = $this->work->author_name;
        }

        return [
            'id' => (int) $this->id,
            'work_id' => (int) $this->work_id,
            'work_title' => $workTitle,
            'author_name' => $authorName,
            'library_id' => $this->library_id ? (int) $this->library_id : null,
            'library' => $this->library,
            'city' => $this->city,
            'shelfmark' => $this->shelfmark,
            'shelfmark_key' => $this->shelfmark_key,
            'scribe_id' => $this->scribe_id ? (int) $this->scribe_id : null,
            'scribe_name' => $this->scribe_name,
            'is_bika' => (bool) $this->is_bika,
            'is_bita' => (bool) $this->is_bita,
            'is_autograph' => (bool) $this->is_autograph,
            'copy_date_raw' => $this->copy_date_raw,
            'copy_date_hijri_year' => $this->copy_date_hijri_year ? (int) $this->copy_date_hijri_year : null,
            'copy_place' => $this->copy_place,
            'script_names' => $this->script_names,
            'script_style' => $this->script_style,
            'folios' => $this->folios ? (int) $this->folios : null,
            'lines' => $this->lines ? (int) $this->lines : null,
            'dimensions' => $this->dimensions,
            'paper' => $this->paper,
            'binding' => $this->binding,
            'incipit_text' => $this->incipit_text,
            'explicit_text' => $this->explicit_text,
            'volume_number' => (int) $this->volume_number,
            'sequence_number' => (int) $this->sequence_number,
            'is_corrected' => (bool) $this->is_corrected,
            'has_marginal_notes' => (bool) $this->has_marginal_notes,
            'is_ruled' => (bool) $this->is_ruled,
            'has_catchwords' => (bool) $this->has_catchwords,
            'is_facsimile' => (bool) $this->is_facsimile,
            'is_collated' => (bool) $this->is_collated,
            'is_illuminated' => (bool) $this->is_illuminated,
            'is_illustrated' => (bool) $this->is_illustrated,
            'has_author_marginalia' => (bool) $this->has_author_marginalia,
            'scripts' => $this->relationLoaded('scripts') ? $this->scripts->pluck('name')->all() : [],
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

    public function libraryRecord(): BelongsTo
    {
        return $this->belongsTo(Library::class, 'library_id');
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
