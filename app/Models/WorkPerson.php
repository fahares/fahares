<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person connected with a work other than its authors (translator, dedicatee, commissioner…),
 * linked to a person record or kept as a name only, with how sure the catalogue is.
 */
class WorkPerson extends Model
{
    protected $table = 'work_people';

    protected $guarded = ['id'];

    /** Roles in display order. */
    public const ROLES = [
        'translator' => 'مترجم',
        'original_author' => 'مؤلف متن اصلی',
        'dedicatee' => 'تقدیم‌شده به',
        'commissioner' => 'به سفارش یا درخواست',
        'subject' => 'درباره‌ی (موضوع اثر)',
        'editor' => 'مصحح یا ویراستار',
        'other' => 'نقش دیگر',
    ];

    /** The person page's tab for each role: the works this person is connected with in it. */
    public const PERSON_TABS = [
        'translator' => 'ترجمه‌ها',
        'original_author' => 'ترجمه‌های آثار او',
        'dedicatee' => 'آثار تقدیم‌شده به او',
        'commissioner' => 'آثار به سفارش او',
        'subject' => 'آثار درباره‌ی او',
        'editor' => 'آثار به تصحیح او',
        'other' => 'نقش‌های دیگر در آثار',
    ];

    /** How sure the catalogue is; null means it says nothing either way. */
    public const CERTAINTIES = [
        'certain' => 'قطعی',
        'probable' => 'محتمل',
        'doubtful' => 'مشکوک',
    ];

    public function work(): BelongsTo
    {
        return $this->belongsTo(Work::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function sourceEntry(): BelongsTo
    {
        return $this->belongsTo(SourceEntry::class);
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

    /** The cataloguer's own hedge word, else the certainty when it is not plain: «ظاهراً», «؟», «محتمل». */
    public function getHedgeLabelAttribute(): ?string
    {
        if ($this->hedge_raw) {
            return $this->hedge_raw;
        }

        return in_array($this->certainty, ['probable', 'doubtful'], true) ? self::CERTAINTIES[$this->certainty] : null;
    }
}
