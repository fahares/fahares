<?php

namespace App\Support;

/**
 * Raising PHP's memory_limit for a heavy request or job without ever lowering it: a limit already higher (or
 * unlimited, -1) is kept, and a limit below the memory already in use would fail with a warning (an
 * ErrorException under Laravel), so the new limit is never below the current usage either.
 */
final class MemoryLimit
{
    /** Make the limit at least $limit (an ini quantity such as "192M"); true if it was changed */
    public static function raiseTo(string $limit): bool
    {
        $current = ini_parse_quantity((string) ini_get('memory_limit'));
        if ($current < 0) {
            return false;
        }
        // Headroom over the current usage, in case a long-running process has already grown past $limit
        $wanted = max(ini_parse_quantity($limit), memory_get_usage(true) + 32 * 1024 * 1024);
        if ($wanted <= $current) {
            return false;
        }

        return ini_set('memory_limit', (string) $wanted) !== false;
    }
}
