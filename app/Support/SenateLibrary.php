<?php

namespace App\Support;

use App\Models\Library;
use App\Models\Manuscript;

/**
 * The library of the former Senate («مجلس سنا»), now kept by the Majlis library but numbered on its own (step 13.148).
 * Fankha writes its copies under «تهران؛ مجلس» and tells them only by the catalogue they cite («[سنا: ف: 2-61]»,
 * Manuscript::citesOnlySenate), so «791/1» of the Senate and «791/1» of the Majlis are two volumes. The corpus
 * commands find a copy's library from that text, and pass it through libraryFor() so a Senate copy is not put back
 * under the Majlis.
 */
class SenateLibrary
{
    public const CITY = 'تهران';

    public const NAME = 'مجلس سنا';

    public const FULL_NAME = 'کتابخانه مجلس سنا';

    public const ALIASES = ['سنا', 'کتابخانه سنا'];

    public const MAJLIS = 'مجلس';

    /** @var array<string, ?int> */
    private static array $ids = [];

    public static function majlis(): ?Library
    {
        return Library::where('city', self::CITY)->where('name', self::MAJLIS)->first();
    }

    public static function find(): ?Library
    {
        return Library::where('city', self::CITY)->where('name', self::NAME)->first();
    }

    /** The library a copy belongs to: the Senate's for a Majlis copy described only from the Senate's catalogue, once that library exists. */
    public static function libraryFor(?int $libraryId, ?string $rawText): ?int
    {
        if ($libraryId === null || ! Manuscript::citesOnlySenate($rawText)) {
            return $libraryId;
        }
        self::$ids['majlis'] ??= self::majlis()?->id;
        self::$ids['senate'] ??= self::find()?->id;

        return $libraryId === self::$ids['majlis'] && self::$ids['senate'] ? self::$ids['senate'] : $libraryId;
    }

    /** Forgets the ids libraryFor() looked up (after the library is made or removed). */
    public static function forget(): void
    {
        self::$ids = [];
    }
}
