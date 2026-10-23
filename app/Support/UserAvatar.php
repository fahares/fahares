<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A user's picture (backlog item 66), in this order: one the user uploaded, then a local copy of their
 * Google picture taken at sign-in, then the initial of the name (x-ui.avatar). Pictures are cropped to a
 * centred square, shrunk to SIZE and kept as WebP on the public disk; no page ever loads them from Google.
 */
class UserAvatar
{
    public const SIZE = 256;

    public const DIRECTORY = 'users/avatars';

    public static function upload(User $user, UploadedFile $file): void
    {
        self::save($user, (string) file_get_contents($file->getRealPath()), 'upload');
    }

    /**
     * Copies the Google picture at sign-in, unless the user uploaded their own or removed theirs.
     * A failure leaves the picture as it was: signing in never waits on it.
     */
    public static function syncGoogle(User $user, ?string $url): void
    {
        if (! $url || in_array($user->avatar_source, ['upload', 'none'], true)) {
            return;
        }
        // Google serves any size: «…=s96-c» becomes «…=s256-c»
        $url = preg_match('/=s\d+(-c)?$/', $url) ? preg_replace('/=s\d+(-c)?$/', '=s' . self::SIZE . '-c', $url) : $url;

        try {
            $response = Http::timeout(5)->get($url);
            if ($response->successful()) {
                self::save($user, $response->body(), 'google');
            }
        } catch (\Throwable $e) {
            Log::warning('Google avatar not copied', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    /** Back to the initial; a later Google sign-in does not bring the Google picture back. */
    public static function remove(User $user): void
    {
        self::deleteFile($user->avatar_path);
        $user->forceFill(['avatar_path' => null, 'avatar_source' => 'none'])->save();
    }

    private static function save(User $user, string $bytes, string $source): void
    {
        $image = $bytes !== '' ? @imagecreatefromstring($bytes) : false;
        if (! $image) {
            throw new InvalidArgumentException('این پرونده تصویر خوانایی نیست.');
        }

        $side = min(imagesx($image), imagesy($image));
        $square = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        imagecopyresampled($square, $image, 0, 0, intdiv(imagesx($image) - $side, 2), intdiv(imagesy($image) - $side, 2), self::SIZE, self::SIZE, $side, $side);

        ob_start();
        imagewebp($square, null, 85);
        $webp = (string) ob_get_clean();
        imagedestroy($image);
        imagedestroy($square);

        // A new name each time, so browsers never show the old picture from cache
        $path = self::DIRECTORY . '/' . $user->id . '-' . Str::lower(Str::random(10)) . '.webp';
        Storage::disk('public')->put($path, $webp);
        self::deleteFile($user->avatar_path);
        $user->forceFill(['avatar_path' => $path, 'avatar_source' => $source])->save();
    }

    private static function deleteFile(?string $path): void
    {
        if ($path && str_starts_with($path, self::DIRECTORY . '/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
