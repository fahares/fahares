<?php

namespace App\Notifications;

/** A new suggestion waits for review: sent to reviewers and to the reviewers' Telegram group. */
class SuggestionSubmitted extends SuggestionNotification
{
    public const TYPE = 'suggestion.submitted';

    protected function title(): string
    {
        return 'پیشنهاد اصلاحی تازه: ' . $this->suggestion->field_label;
    }

    protected function lines(object $notifiable): array
    {
        $s = $this->suggestion;

        return array_values(array_filter([
            'رکورد: ' . $this->recordLabel(),
            $s->kind === 'field' ? 'مقدار فعلی: ' . self::short($s->current_value, 200) : null,
            'پیشنهاد: ' . self::short($s->suggested_value),
            $s->rationale_citation ? 'مستند: ' . self::short($s->rationale_citation) : null,
            'پیشنهاددهنده: ' . ($s->guest_name ?: 'مهمان'),
        ]));
    }

    protected function url(object $notifiable): string
    {
        return route('admin.suggestions.show', $this->suggestion);
    }

    protected function actionLabel(): string
    {
        return 'بررسی در میز داوری';
    }
}
