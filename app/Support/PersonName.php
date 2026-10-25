<?php

namespace App\Support;

/** Checks on the name of an account, which is published beside its corrections (backlog item 75). */
class PersonName
{
    /** Latin letters and no Persian or Arabic ones, e.g. a Google account name like «Ali Rezaei». */
    public static function isLatinOnly(?string $name): bool
    {
        $name = (string) $name;

        return (bool) preg_match('/\p{Latin}/u', $name) && ! preg_match('/\p{Arabic}/u', $name);
    }
}
