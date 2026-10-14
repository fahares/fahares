<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person connected with a manuscript other than its scribes (owner, endower, corrector…),
 * linked to a person record or kept as a name only.
 */
class ManuscriptPerson extends Model
{
    protected $table = 'manuscript_people';

    protected $guarded = ['id'];

    /** Roles in display order. */
    public const ROLES = [
        'owner' => 'مالک',
        'endower' => 'واقف',
        'corrector' => 'مصحح',
        'collator' => 'مقابله‌کننده',
        'glossator' => 'محشی',
        'patron' => 'سفارش‌دهنده یا اهداشونده',
        'illuminator' => 'مذهّب',
        'painter' => 'نگارگر',
        'binder' => 'صحاف',
        'reader' => 'خواننده (یادداشت مطالعه)',
    ];

    public function manuscript(): BelongsTo
    {
        return $this->belongsTo(Manuscript::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(FieldSuggestion::class, 'suggestion_id');
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->person?->name ?? (string) $this->name;
    }

    public function getRoleLabelAttribute(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }
}
