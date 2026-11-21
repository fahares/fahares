<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An entry of a work's related works that is text, as a reviewer's queue sees it: a row of work_text_relations with a
 * candidate work (backlog item 6, steps 14.274 and 14.285; the table work_entry_links it began in is gone). «text»
 * is the entry as the catalogue writes it, «linked_work_id» the candidate, «link_status» proposed, linked or rejected,
 * and «link_source» review for the rows of the panel's «پیوند آثار مرتبط». See WorkCatalogueEntries.
 */
class WorkEntryLink extends Model
{
    public const LINKED = 'linked';

    public const PROPOSED = 'proposed';

    public const REJECTED = 'rejected';

    public const SOURCE_REVIEW = 'review';

    public const STATUSES = [self::PROPOSED => 'در انتظار', self::LINKED => 'پیوند خورد', self::REJECTED => 'اثر دیگری است'];

    protected $table = 'work_text_relations';

    protected $guarded = [];

    protected $casts = ['decided_at' => 'datetime', 'differs' => 'boolean'];

    /** The rows of the panel's queue: a reviewer's to decide, or decided there */
    public function scopeReview(Builder $query): Builder
    {
        return $query->where('link_source', self::SOURCE_REVIEW);
    }

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    public function linkedWork(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'linked_work_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
