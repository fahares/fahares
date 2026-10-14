<?php

namespace App\Auth;

use App\Models\FieldSuggestion;
use App\Models\User;

/**
 * Attaches suggestions sent as a guest with this email to the account, once the account
 * has proven it owns the email (verification link or a Google sign-in). Never runs for an
 * unverified email: anyone can register with someone else's address.
 */
class GuestSuggestionLinker
{
    public static function link(User $user): int
    {
        if (! $user->hasVerifiedEmail()) {
            return 0;
        }

        return FieldSuggestion::whereNull('user_id')
            ->whereNotNull('guest_email')
            ->whereRaw('LOWER(guest_email) = ?', [mb_strtolower(trim($user->email))])
            ->update(['user_id' => $user->id]);
    }

    public static function message(int $linked): ?string
    {
        return $linked ? "{$linked} پیشنهادی که پیش‌تر با همین ایمیل فرستاده بودید به حساب شما وصل شد و در «پیشنهادهای من» دیده می‌شود." : null;
    }
}
