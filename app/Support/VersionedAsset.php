<?php

namespace App\Support;

/**
 * URL of a public file with a short content hash, «/images/brand/shamseh-64.png?v=1a2b3c4d».
 * Cloudflare keeps images for hours; when the file changes the URL changes with it,
 * so visitors get the new image at once.
 */
class VersionedAsset
{
    private static array $hashes = [];

    public static function url(string $path): string
    {
        $file = public_path($path);
        $hash = self::$hashes[$path] ??= (is_file($file) ? substr(md5_file($file), 0, 8) : null);

        return asset($path) . ($hash ? '?v=' . $hash : '');
    }
}
