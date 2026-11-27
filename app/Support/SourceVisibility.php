<?php

namespace App\Support;

use App\Auth\Permissions;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who sees a source's places and texts on the record pages (step 14.426). Each source has a visibility:
 *  - always: every reader;
 *  - fallback: only on a page where the reader sees the record in no first-hand source (the rule of step 14.376 for
 *    Fankha: a page image or text of a source whose visibility is always);
 *  - hidden: no reader.
 * Its audience (roles, verified scholars, single users) and the admin see it always. What a reader may not see is left
 * out of the page on the server, not hidden in the browser, so neither the page's source nor a crawler has it.
 */
final class SourceVisibility
{
    public const ALWAYS = 'always';

    public const FALLBACK = 'fallback';

    public const HIDDEN = 'hidden';

    public const MODES = [
        self::ALWAYS => 'همیشه',
        self::FALLBACK => 'فقط وقتی خواننده مأخذ دیگری نبیند',
        self::HIDDEN => 'هیچ‌گاه',
    ];

    public function __construct(private readonly ?User $user)
    {
    }

    /** The visibility for the signed-in reader, or a guest. */
    public static function forViewer(): self
    {
        $user = auth()->user();

        return new self($user instanceof User ? $user : null);
    }

    public static function mode(?Source $source): string
    {
        return $source && isset(self::MODES[$source->visibility]) ? $source->visibility : self::ALWAYS;
    }

    /** Whether every reader sees the source: only such a source makes the page first-hand for a fallback one. */
    public static function isOpen(?Source $source): bool
    {
        return self::mode($source) === self::ALWAYS;
    }

    /** Whether the reader is in the source's audience, who see it always. */
    public function inAudience(?Source $source): bool
    {
        $user = $this->user;
        if (! $user || ! $source) {
            return false;
        }
        if ($user->role === Permissions::SUPER_ROLE) {
            return true;
        }
        $audience = (array) ($source->audience ?? []);

        return in_array($user->role, (array) ($audience['roles'] ?? []), true)
            || (! empty($audience['scholars']) && $user->is_verified_scholar)
            || in_array((int) $user->id, array_map('intval', (array) ($audience['users'] ?? [])), true);
    }

    /** Whether the reader sees the source on a page that does or does not show the record first-hand. */
    public function shows(?Source $source, bool $seesFirstHand): bool
    {
        return match (self::mode($source)) {
            self::ALWAYS => true,
            self::FALLBACK => ! $seesFirstHand || $this->inAudience($source),
            default => $this->inAudience($source),
        };
    }

    /**
     * The entries the reader sees, in their order.
     *
     * @param  Collection<int, SourceEntry>  $entries
     * @return Collection<int, SourceEntry>
     */
    public function filter(Collection $entries, bool $seesFirstHand): Collection
    {
        return $entries->filter(fn (SourceEntry $e) => $this->shows($e->source, $seesFirstHand))->values();
    }
}
