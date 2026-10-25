<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\NotificationPreferences;
use App\Notifications\UserRegistered;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/** Every sign-up path fires Registered (the form, and the first Google sign-in), so a new path cannot skip the notice. */
class NotifyNewUserRegistered
{
    public function handle(Registered $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }
        try {
            Notification::send(
                NotificationPreferences::recipients(UserRegistered::TYPE)->reject(fn (User $u) => $u->id === $event->user->id),
                new UserRegistered($event->user),
            );
        } catch (\Throwable $e) {
            Log::warning('New user notification failed: ' . $e->getMessage());
        }
    }
}
