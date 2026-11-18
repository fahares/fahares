<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * What a first-hand source says about one field of a record, where it differs from the record's value or fills a field
 * the record lacks (backlog items 99 and 147, step 14.249). The record's column is the accepted value; a claim stays a
 * candidate until it is accepted (the column then takes its value) or rejected. Agreement is no claim: the entry lists
 * the field in source_entries.confirmed_fields.
 */
class Claim extends Model
{
    public const STATUS_CANDIDATE = 'candidate';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    /** Read by a rule (App\Sources\FirstHand), by a language model, or entered by a person. */
    public const METHOD_RULE = 'rule';

    public const METHOD_LLM = 'llm';

    public const METHOD_MANUAL = 'manual';

    protected $guarded = ['id'];

    protected $attributes = ['status' => self::STATUS_CANDIDATE, 'method' => self::METHOD_RULE];

    protected function casts(): array
    {
        return [
            'value_json' => 'array',
            'certainty' => 'integer',
            'is_negative' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function sourceEntry(): BelongsTo
    {
        return $this->belongsTo(SourceEntry::class);
    }

    public function scopeCandidate($query)
    {
        return $query->where('status', self::STATUS_CANDIDATE);
    }
}
