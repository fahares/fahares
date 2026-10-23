<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One ground of a field's value or of its correction (step 13.1). A change may rest on several: an entry of a
 * source on the site, a place in a registered source, a work the site does not hold, or the researcher's own
 * reasoning from several sources.
 */
class FieldEvidence extends Model
{
    public const KIND_SOURCE_ENTRY = 'source_entry';

    public const KIND_SOURCE = 'source';

    public const KIND_EXTERNAL = 'external';

    public const KIND_RESEARCH = 'research';

    public const KIND_LABELS = [
        self::KIND_SOURCE_ENTRY => 'مدخل منبع',
        self::KIND_SOURCE => 'منبع',
        self::KIND_EXTERNAL => 'منبع بیرونی',
        self::KIND_RESEARCH => 'تحقیق پژوهشگر',
    ];

    protected $table = 'field_evidence';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'volume_number' => 'integer',
            'page' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(FieldSuggestion::class, 'field_suggestion_id');
    }

    public function auditLog(): BelongsTo
    {
        return $this->belongsTo(AuditLog::class);
    }

    public function sourceEntry(): BelongsTo
    {
        return $this->belongsTo(SourceEntry::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
