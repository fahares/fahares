<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];

    /** Changes the audit log can undo; each has a rollback_* counterpart. */
    public const UNDOABLE = ['merge', 'verify', 'update', 'remove', 'split'];

    /** Persian names of the actions, for the audit log page and the dashboard; unknown ones show their key */
    public const ACTION_LABELS = [
        'create' => 'ایجاد',
        'update' => 'ویرایش',
        'verify' => 'تأیید پیشنهاد',
        'bulk_verify' => 'تأیید گروهی',
        'review' => 'داوری',
        'reopen' => 'بازگشایی',
        'merge' => 'ادغام',
        'split' => 'تفکیک',
        'remove' => 'حذف',
        'delete' => 'حذف',
        'permissions' => 'تغییر دسترسی',
        'profile_correction' => 'اصلاح شناسنامه',
        'home_texts' => 'متن‌های صفحه‌ی خانه',
        'home_item_delete' => 'حذف از صفحه‌ی خانه',
        'home_cataloger_add' => 'افزودن فهرست‌نگار به خانه',
        'home_cataloger_remove' => 'برداشتن فهرست‌نگار از خانه',
        'post_delete' => 'حذف نوشته',
    ];

    /** Persian names of the record types */
    public const TYPE_LABELS = [
        Manuscript::class => 'نسخه',
        Work::class => 'اثر',
        Person::class => 'شخص',
        Library::class => 'کتابخانه',
        Cataloger::class => 'فهرست‌نگار',
        Subject::class => 'موضوع',
        SourceEntry::class => 'مدخل مأخذ',
        ManuscriptCatalogReference::class => 'نشانی فهرست',
        FieldSuggestion::class => 'پیشنهاد',
        Page::class => 'صفحه',
        Post::class => 'نوشته',
        HomeItem::class => 'صفحه‌ی خانه',
        SiteSetting::class => 'تنظیمات سایت',
        User::class => 'کاربر',
        Role::class => 'نقش',
        // Operations of the system page, logged without a model
        'System' => 'سامانه',
        'Meilisearch' => 'نمایه‌ی جستجو',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actionLabel(): string
    {
        if (str_starts_with($this->action, 'rollback_')) {
            return 'بازگردانی ' . (self::ACTION_LABELS[substr($this->action, 9)] ?? substr($this->action, 9));
        }

        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->auditable_type] ?? class_basename($this->auditable_type);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether a later rollback undid this change. Rollbacks name the log they undid; older field
     * rollbacks only swapped the values, so those are matched by their content.
     */
    public function isRolledBack(): bool
    {
        $rollbacks = self::where('auditable_type', $this->auditable_type)
            ->where('id', '>', $this->id)
            ->where('action', 'like', 'rollback%')
            ->get();

        return $rollbacks->contains(fn (self $r) => ($r->old_values['rolled_back_log_id'] ?? null) === $this->id
            || (in_array($r->action, ['rollback_verify', 'rollback_update'], true) && $r->auditable_id === $this->auditable_id
                && array_diff_key($r->old_values ?? [], ['rolled_back_log_id' => 0]) == $this->new_values));
    }

    /** A later field change to the same record that a rollback of this one would overwrite. */
    public function laterOverlappingChange(): ?self
    {
        if (! in_array($this->action, ['verify', 'update'], true) || empty($this->old_values)) {
            return null;
        }

        return self::where('auditable_type', $this->auditable_type)
            ->where('auditable_id', $this->auditable_id)
            ->where('id', '>', $this->id)
            ->whereIn('action', ['verify', 'update'])
            ->orderBy('id')
            ->get()
            ->first(fn (self $l) => array_intersect_key($l->old_values ?? [], $this->old_values) && ! $l->isRolledBack());
    }

    /** The change an approved suggestion made, if it is still in force. */
    public static function activeForSuggestion(int $suggestionId): ?self
    {
        return self::whereIn('action', self::UNDOABLE)
            ->where('new_values->suggestion_id', $suggestionId)
            ->latest('id')
            ->get()
            ->first(fn (self $l) => ! $l->isRolledBack());
    }
}
