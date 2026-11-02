<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item of the admin queue «بازبینی اشخاص»: a group of records that may be one person
 * (type duplicate) or a record that may hold several people (type mixed). See PersonReviewQueue. Type author_link
 * (step 14.17) is a work whose author is a name only and the one person it may be: person_ids holds that person and
 * suggestion the work and the evidence (AuthorLinkQueue).
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
        'linked' => 'پیوند خورد',
        'not_linked' => 'شخص دیگری است',
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
