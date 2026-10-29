<?php

namespace App\Notifications;

use App\Models\FieldSuggestion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends the suggestion notifications to the right people. A failure here is logged and never
 * stops the submission or the review that triggered it.
 */
class SuggestionNotifier
{
    public static function submitted(FieldSuggestion $suggestion): void
    {
        self::safely(function () use ($suggestion) {
            $reviewers = NotificationPreferences::recipients(SuggestionSubmitted::TYPE)->reject(fn ($u) => $u->id === $suggestion->user_id);
            Notification::send($reviewers, new SuggestionSubmitted($suggestion));

            if ($chatId = config('notifications.telegram.reviewers_chat_id')) {
                Notification::route('telegram', $chatId)->notify(new SuggestionSubmitted($suggestion));
            }
        });
    }

    /** @param  string  $event  "reviewed", or "demoted" when another reading replaced this adopted one */
    public static function reviewed(FieldSuggestion $suggestion, string $event = 'reviewed'): void
    {
        // The admin's own direct action (backlog item 45) has nobody to tell
        if (! empty($suggestion->payload['direct'])) {
            return;
        }
        self::safely(function () use ($suggestion, $event) {
            $notification = new SuggestionReviewed($suggestion, $event);
            if ($suggestion->user) {
                $suggestion->user->notify($notification);
            } elseif ($suggestion->notify_submitter && $suggestion->guest_email) {
                Notification::route('mail', $suggestion->guest_email)->notify($notification);
            }
        });
    }

    private static function safely(callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::warning('Suggestion notification failed: ' . $e->getMessage());
        }
    }
}
