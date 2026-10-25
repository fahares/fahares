<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item of the admin queue «بازبینی اشخاص»: a group of records that may be one person
 * (type duplicate) or a record that may hold several people (type mixed). See PersonReviewQueue.
 */
class PersonReview extends Model
{
    public const STATUSES = [
        'open' => 'در انتظار',
        'deferred' => 'بعداً',
        'merged' => 'ادغام شد',
        'distinct' => 'چند نفرند',
        'split' => 'تفکیک شد',
        'dismissed' => 'یک نفر است',
        'gone' => 'دیگر وجود ندارد',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'person_ids' => 'array',
            'suggestion' => 'array',
            'decision' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'deferred'], true);
    }
}
