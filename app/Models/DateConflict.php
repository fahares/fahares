<?php

namespace App\Models;

use App\Support\DateEstimates\Loader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Two documented dates that cannot both be right (step 14.166), as fahares:estimate-dates found them: «a» is no
 * earlier than a_from, which is later than b's latest year b_to allows, by «gap» years. An indirect conflict
 * (direct = false) is between two dates that leave a third, «via», no year; a_from and b_to are then the bounds they
 * give it. A reviewer may dismiss a row («not an error»), and the nightly run keeps that.
 */
class DateConflict extends Model
{
    public const FIELD_LABELS = ['birth' => 'تولد', 'death' => 'وفات', 'composition' => 'تألیف', 'copy' => 'کتابت'];

    /** What is wrong, by the rule and the two dates it ties: rule => [a_field > b_field => label]. */
    public const LABELS = [
        Loader::RULE_LIFE => ['death>birth' => 'عمر بیش از ۱۰۰ سال', 'birth>death' => 'وفات پیش از تولد'],
        Loader::RULE_AUTHOR => ['composition>death' => 'تألیف پس از وفات مؤلف', 'birth>composition' => 'مؤلف هنگام تألیف کمتر از ۱۵ ساله'],
        Loader::RULE_TRANSLATOR => ['composition>death' => 'ترجمه پس از وفات مترجم', 'birth>composition' => 'مترجم هنگام ترجمه کمتر از ۱۵ ساله'],
        Loader::RULE_DEDICATEE => ['composition>death' => 'تألیف پس از وفات مُهدی‌الیه', 'birth>composition' => 'تألیف پیش از تولد مُهدی‌الیه'],
        Loader::RULE_COPY => ['composition>copy' => 'کتابت نسخه پیش از تألیف اثر'],
        Loader::RULE_AUTOGRAPH => ['copy>death' => 'نسخه‌ی خط مؤلف پس از وفات مؤلف'],
        Loader::RULE_SCRIBE => ['copy>death' => 'کتابت پس از وفات کاتب', 'birth>copy' => 'کاتب هنگام کتابت کمتر از ۱۰ ساله'],
        Loader::RULE_PATRON => ['copy>death' => 'کتابت پس از وفات بانی', 'birth>copy' => 'کتابت پیش از تولد بانی'],
        Loader::RULE_DERIVED => ['composition>composition' => 'اثر وابسته پیش از اثر اصلی'],
    ];

    /** The rules' short names, for the filter. */
    public const RULE_LABELS = [
        Loader::RULE_COPY => 'نسخه و تألیف',
        Loader::RULE_SCRIBE => 'کاتب',
        Loader::RULE_AUTOGRAPH => 'خط مؤلف',
        Loader::RULE_AUTHOR => 'مؤلف',
        Loader::RULE_TRANSLATOR => 'مترجم',
        Loader::RULE_DEDICATEE => 'مُهدی‌الیه',
        Loader::RULE_PATRON => 'بانی',
        Loader::RULE_LIFE => 'عمر',
        Loader::RULE_DERIVED => 'اثر وابسته',
    ];

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['direct' => 'boolean', 'dismissed_at' => 'datetime', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function dismisser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at');
    }

    public function getLabelAttribute(): string
    {
        if (! $this->direct) {
            return 'برای «' . (self::FIELD_LABELS[$this->via_field] ?? $this->via_field) . '» سالی نمی‌ماند';
        }

        return self::LABELS[$this->rule]["{$this->a_field}>{$this->b_field}"] ?? (self::RULE_LABELS[$this->rule] ?? $this->rule);
    }
}
