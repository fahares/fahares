<?php

namespace App\Notifications;

use App\Models\FieldSuggestion;
use App\Models\User;

/**
 * Tells a submitter how their suggestion was reviewed, or (event "demoted") that another reading
 * was adopted for the field and theirs became an alternative or was superseded.
 */
class SuggestionReviewed extends SuggestionNotification
{
    public const TYPE = 'suggestion.reviewed';

    public function __construct(FieldSuggestion $suggestion, public string $event = 'reviewed')
    {
        parent::__construct($suggestion);
    }

    protected function title(): string
    {
        $s = $this->suggestion;

        return match (true) {
            $this->event === 'demoted' && $s->standing === 'superseded' => 'قول شما درباره‌ی «' . $s->field_label . '» منسوخ شد',
            $this->event === 'demoted' => 'قول دیگری درباره‌ی «' . $s->field_label . '» مختار شد',
            $s->status === 'rejected' => 'پیشنهاد شما درباره‌ی «' . $s->field_label . '» پذیرفته نشد',
            $s->standing === 'alternative' => 'پیشنهاد شما درباره‌ی «' . $s->field_label . '» به‌عنوان «قول دیگر» ثبت شد',
            default => 'پیشنهاد شما درباره‌ی «' . $s->field_label . '» تأیید شد',
        };
    }

    protected function lines(object $notifiable): array
    {
        $s = $this->suggestion;
        $lines = ['رکورد: ' . $this->recordLabel(), 'پیشنهاد شما: ' . self::short($s->applied_value ?? $s->suggested_value, 200)];

        if ($this->event === 'demoted') {
            $replacement = $s->supersededBy;
            $lines[] = $s->standing === 'superseded'
                ? 'قول شما در «سابقه‌ی اصلاحات» صفحه می‌ماند و سهم شما در آن دیده می‌شود.'
                : 'قول شما با مستندش به‌عنوان «قول دیگر» در صفحه می‌ماند.';
            if ($replacement) {
                $lines[] = 'قول مختار تازه: ' . self::short($replacement->display_value, 200);
                if ($replacement->public_note) {
                    $lines[] = 'یادداشت داور: ' . self::short($replacement->public_note, 500);
                }
            }

            return $lines;
        }

        $lines[] = match (true) {
            $s->status === 'rejected' => 'داور پیشنهاد را نپذیرفت.',
            $s->kind === 'note' => 'یادداشت شما در صفحه‌ی رکورد، بخش «یادداشت‌ها و منابع پژوهشی»، منتشر شد.',
            $s->standing === 'adopted' => 'پیشنهاد در شناسنامه‌ی رکورد اعمال شد و به‌عنوان «قول مختار» با مستندش در صفحه نمایش داده می‌شود.',
            $s->standing === 'alternative' => 'مقدار رکورد تغییر نکرد، ولی پیشنهاد شما با مستندش به‌عنوان «قول دیگر» در صفحه نمایش داده می‌شود.',
            $s->standing === 'edit' => 'اصلاح در شناسنامه‌ی رکورد اعمال شد.',
            default => 'داور به پیشنهاد شما رسیدگی کرد.',
        };
        if ($s->public_note) {
            $lines[] = 'یادداشت داور: ' . self::short($s->public_note, 500);
        }
        if ($s->status === 'approved' && (($s->standing && $s->standing !== 'edit') || $s->kind === 'note') && ! $s->credit_name && $notifiable instanceof User) {
            $lines[] = 'اگر مایلید نامتان کنار این قول نمایش داده شود، آن را در «پیشنهادهای من» ثبت کنید.';
        }

        return $lines;
    }

    protected function url(object $notifiable): string
    {
        return $notifiable instanceof User ? route('my.suggestions') : $this->recordUrl();
    }

    protected function actionLabel(): string
    {
        return 'مشاهده';
    }
}
