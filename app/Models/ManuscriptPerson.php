<?php

namespace App\Models;

use App\Models\Concerns\RecountsPerson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person connected with a manuscript (scribe, owner, endower, corrector…), linked to a person record or kept
 * as a name only. The scribes (role «scribe», step 13.151) are the copy's scribe list; ROLES are the others.
 */
class ManuscriptPerson extends Model
{
    use RecountsPerson;

    protected $table = 'manuscript_people';

    protected $guarded = ['id'];

    /** The role of the copy's scribes; the primary one (manuscripts.scribe_id) is at position 0. */
    public const SCRIBE = 'scribe';

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
