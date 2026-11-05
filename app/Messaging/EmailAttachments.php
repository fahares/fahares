<?php

namespace App\Messaging;

use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pictures attached to an email reply (step 14.36). Resend lists a received email's attachments, each with a
 * download link that lasts about an hour; the pictures (PNG, JPEG, GIF or WebP, checked by their bytes, not their
 * name) are downloaded and kept as they are, the rest skipped. Gmail puts «[image: name]» in the plain text where
 * each inline picture stood; once the pictures are kept those markers are taken out of the text.
 */
class EmailAttachments
{
    private const TYPES = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];

    /**
     * The pictures of a received email, downloaded: a list of [filename, content_type, extension, bytes]. Null when Resend
     * could not be reached, so the caller can ask for a retry.
     *
     * @return list<array{filename: string, content_type: string, extension: string, bytes: string}>|null
     */
    public static function download(string $emailId): ?array
    {
        $list = Http::withToken((string) config('services.resend.key'))->acceptJson()
            ->get('https://api.resend.com/emails/receiving/' . rawurlencode($emailId) . '/attachments');
        if (! $list->successful()) {
            Log::warning('Inbound email attachments could not be listed: ' . $list->status());

            return null;
        }

        $max = (int) config('notifications.inbound.max_attachments', 12);
        $maxBytes = (int) config('notifications.inbound.max_attachment_bytes', 10 * 1024 * 1024);
        $pictures = [];
        foreach ((array) $list->json('data', []) as $item) {
            $url = (string) ($item['download_url'] ?? '');
            if (count($pictures) >= $max || ! str_starts_with((string) ($item['content_type'] ?? ''), 'image/')
                || (int) ($item['size'] ?? 0) > $maxBytes || ! str_starts_with($url, 'https://')) {
                continue;
            }
            $file = Http::timeout(30)->get($url);
            $bytes = $file->successful() ? $file->body() : '';
            $info = $bytes !== '' && strlen($bytes) <= $maxBytes ? @getimagesizefromstring($bytes) : false;
            if (! $info || ! isset(self::TYPES[$info[2]])) {
                Log::info('Inbound email attachment skipped.', ['attachment_id' => $item['id'] ?? null]);

                continue;
            }
            $pictures[] = [
                'filename' => Str::limit(trim((string) ($item['filename'] ?? '')) ?: 'image.' . self::TYPES[$info[2]], 190, ''),
                'content_type' => image_type_to_mime_type($info[2]),
                'extension' => self::TYPES[$info[2]],
                'bytes' => $bytes,
            ];
        }

        return $pictures;
    }

    /** Keeps downloaded pictures with a message, each under a random name in its thread's directory */
    public static function keep(Message $message, array $pictures): void
    {
        foreach ($pictures as $picture) {
            $path = MessageAttachment::DIRECTORY . '/' . $message->message_thread_id . '/' . $message->id . '-' . Str::lower(Str::random(24)) . '.' . $picture['extension'];
            Storage::disk('public')->put($path, $picture['bytes']);
            $message->attachments()->create([
                'path' => $path,
                'filename' => $picture['filename'],
                'content_type' => $picture['content_type'],
                'size' => strlen($picture['bytes']),
            ]);
        }
    }

    /** The text without Gmail's «[image: name]» markers (a long name may wrap onto the next line) */
    public static function withoutMarkers(string $text): string
    {
        $text = preg_replace('~\[image:[^\]\n]*(?:\n[^\]\n]*)?\]~u', '', $text);

        return trim(preg_replace(["~[ \t]+\n~", "~\n{3,}~"], ["\n", "\n\n"], $text));
    }
}
