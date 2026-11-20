<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An entry of a work's «وابسته به» or «شروح و حواشی» text tied to the work it names (backlog item 6, steps 14.270
 * and 14.274). Rows of source «model» are written by works:link-decisions; rows of source «review» are proposals a
 * reviewer confirms or rejects in the panel's «پیوند آثار مرتبط».
 */
class WorkEntryLink extends Model
{
    public const LINKED = 'linked';

    public const PROPOSED = 'proposed';

    public const REJECTED = 'rejected';

    public const SOURCE_REVIEW = 'review';

    public const STATUSES = [self::PROPOSED => 'در انتظار', self::LINKED => 'پیوند خورد', self::REJECTED => 'اثر دیگری است'];

    protected $guarded = [];

    protected $casts = ['decided_at' => 'datetime'];

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
