<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A ready-made direct message the admin picks and edits before sending (backlog item 91).
 * Its subject and body take the placeholders of VARIABLES, filled for each recipient.
 */
class MessageTemplate extends Model
{
    public const VARIABLES = [
        '{نام}' => 'نام کاربر',
        '{شمار_اصلاحات}' => 'شمار پیشنهادهای تأییدشده‌ی او',
        '{پیوند_حساب}' => 'نشانی صفحه‌ی «حساب من»',
    ];

    protected $guarded = ['id'];

    public static function render(string $text, User $user): string
    {
        return strtr($text, [
            '{نام}' => $user->name,
            '{شمار_اصلاحات}' => \App\Support\Fa::num(FieldSuggestion::where('user_id', $user->id)->where('status', 'approved')->count()),
            '{پیوند_حساب}' => route('profile'),
        ]);
    }
}
