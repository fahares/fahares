<?php

namespace App\Models;

use App\Sources\CitationForms;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How a source is cited in another (step 14.57): the names the citing source writes for it, or a regex, and the
 * rules its numbers are read by. citing_source_id null is the form any source uses. CitationForms reads them.
 */
class SourceCitationForm extends Model
{
    public const CONTEXTS = ['bibliography' => 'مآخذ آثار', 'page_text' => 'متن صفحه‌ها'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saved(fn () => CitationForms::forget());
        static::deleted(fn () => CitationForms::forget());
    }

    protected function casts(): array
    {
        return ['names' => 'array', 'options' => 'array', 'examples' => 'array'];
    }

    public function cited(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'cited_source_id');
    }

    public function citing(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'citing_source_id');
    }

    /** The pattern its names make, as BibliographyLinks matches them: after the same evening out of the spelling. */
    public function pattern(): string
    {
        return CitationForms::pattern($this->regex, (array) $this->names);
    }
}
