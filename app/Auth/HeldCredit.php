<?php

namespace App\Auth;

use App\Models\FieldSuggestion;
use App\Models\User;

/**
 * Suggestions are accepted before the email is verified, but the submitter's name is published only after it
 * (backlog item 76, user decision): until then the requested name waits in held_credit_name.
 */
class HeldCredit
{
    /** The values to save for a name the user asked to show: published, or held until verification. */
    public static function columns(?User $user, ?string $name): array
    {
        $name = trim((string) $name) ?: null;

        return $name && $user && ! $user->hasVerifiedEmail()
            ? ['credit_name' => null, 'held_credit_name' => $name]
            : ['credit_name' => $name, 'held_credit_name' => null];
    }

    /** Publishes the held names once the account has verified its email. */
    public static function release(User $user): int
    {
        if (! $user->hasVerifiedEmail()) {
            return 0;
        }

        return FieldSuggestion::where('user_id', $user->id)->whereNotNull('held_credit_name')
            ->get()
            ->each(fn (FieldSuggestion $s) => $s->update(['credit_name' => $s->credit_name ?: $s->held_credit_name, 'held_credit_name' => null]))
            ->count();
    }
}
