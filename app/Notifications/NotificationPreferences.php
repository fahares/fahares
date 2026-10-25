<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Collection;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Who receives which notification over which channel, from the registry in config/notifications.php
 * and each user's stored choices.
 */
class NotificationPreferences
{
    public static function types(): array
    {
        return config('notifications.types');
    }

    public static function channels(): array
    {
        return config('notifications.channels');
    }

    /** The types a user's role may receive. */
    public static function typesFor(User $user): array
    {
        return array_filter(self::types(), fn ($type) => self::allowed($user, $type));
    }

    public static function enabled(User $user, string $type, string $channel): bool
    {
        $definition = self::types()[$type] ?? null;
        if (! $definition || ! self::allowed($user, $definition) || ! array_key_exists($channel, $definition['channels'])) {
            return false;
        }
        $stored = NotificationPreference::where('user_id', $user->id)
            ->where('notification_type', $type)->where('channel', $channel)->value('enabled');

        return $stored ?? $definition['channels'][$channel];
    }

    /** Laravel channel names to use for this user and type. */
    public static function channelsFor(User $user, string $type): array
    {
        $channels = [];
        foreach (array_keys(self::types()[$type]['channels'] ?? []) as $channel) {
            if ($channel === 'mail' && ! $user->email) {
                continue;
            }
            if ($channel === 'webpush' && ! $user->pushSubscriptions()->exists()) {
                continue;
            }
            if (self::enabled($user, $type, $channel)) {
                $channels[] = $channel === 'webpush' ? WebPushChannel::class : $channel;
            }
        }

        return $channels;
    }

    public static function set(User $user, string $type, string $channel, bool $enabled): void
    {
        NotificationPreference::updateOrCreate(
            ['user_id' => $user->id, 'notification_type' => $type, 'channel' => $channel],
            ['enabled' => $enabled],
        );
    }

    /** A browser was just subscribed: turns on the browser channel of each type the user has not chosen for yet. */
    public static function enableWebPushDefaults(User $user): void
    {
        foreach (self::typesFor($user) as $type => $definition) {
            $chosen = NotificationPreference::where('user_id', $user->id)->where('notification_type', $type)->where('channel', 'webpush')->exists();
            if (array_key_exists('webpush', $definition['channels']) && ! $chosen) {
                self::set($user, $type, 'webpush', true);
            }
        }
    }

    /** Users whose role may receive the type. */
    public static function recipients(string $type): Collection
    {
        $definition = self::types()[$type] ?? [];
        if (isset($definition['permission'])) {
            return User::all()->filter(fn (User $user) => $user->hasPermission($definition['permission']))->values();
        }
        $roles = $definition['roles'] ?? [];

        return in_array('*', $roles, true) ? User::all() : User::whereIn('role', $roles)->get();
    }

    private static function allowed(User $user, array $definition): bool
    {
        if (isset($definition['permission'])) {
            return $user->hasPermission($definition['permission']);
        }

        return in_array('*', $definition['roles'], true) || in_array($user->role, $definition['roles'], true);
    }
}
