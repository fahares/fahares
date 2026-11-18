<?php

namespace App\Messaging;

use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Files attached to an email reply (steps 14.36 and 14.251). Resend lists a received email's attachments, each with a
 * download link that lasts about an hour; the pictures (PNG, JPEG, GIF or WebP) and the PDFs, both checked by their
 * bytes and not their name, are downloaded and kept as they are. The rest are skipped, and each skipped one is named
 * in a line under the letter (notice), so that neither side takes it for delivered. Gmail puts «[image: name]» in the
 * plain text where each inline picture stood; once the pictures are kept those markers are taken out of the text.
 */
class EmailAttachments
{
    private const TYPES = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];

    /** Why an attachment was not kept, as the line under the letter says it */
    private const REASONS = [
        'type' => 'این نوع فایل پذیرفته نمی‌شود',
        'size' => 'بزرگ‌تر از سقف حجم پیوست است',
        'count' => 'بیش از سقف شمار پیوست‌هاست',
        'fetch' => 'دریافت آن از سرویس ایمیل ناموفق بود',
    ];

    /**
     * The pictures and PDFs of a received email, downloaded: a list of [filename, content_type, extension, bytes]. Null
     * when Resend could not be reached, so the caller can ask for a retry. $skipped takes the attachments that were not
     * kept: [filename, content_type, size, reason], the reason a key of REASONS.
     *
     * @param  list<array{filename: string, content_type: string, size: int, reason: string}>  $skipped
     * @return list<array{filename: string, content_type: string, extension: string, bytes: string}>|null
     */
    public static function download(string $emailId, array &$skipped = []): ?array
    {
        $skipped = [];
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
            $type = strtolower((string) ($item['content_type'] ?? ''));
            $name = Str::limit(trim((string) ($item['filename'] ?? '')), 190, '');
            $skip = function (string $reason, ?int $size = null) use (&$skipped, $item, $name, $type) {
                Log::info('Inbound email attachment skipped.', ['attachment_id' => $item['id'] ?? null, 'reason' => $reason]);
                $skipped[] = ['filename' => $name, 'content_type' => $type, 'size' => $size ?? (int) ($item['size'] ?? 0), 'reason' => $reason];
            };
            $pdf = $type === 'application/pdf';
            if (! $pdf && ! str_starts_with($type, 'image/')) {
                $skip('type');
            } elseif ((int) ($item['size'] ?? 0) > $maxBytes) {
                $skip('size');
            } elseif (count($pictures) >= $max) {
                $skip('count');
            } elseif (! str_starts_with($url, 'https://')) {
                $skip('fetch');
            } else {
                $file = Http::timeout(60)->get($url);
                $bytes = $file->successful() ? $file->body() : '';
                if ($bytes === '') {
                    $skip('fetch');
                } elseif (strlen($bytes) > $maxBytes) {
                    $skip('size', strlen($bytes));
                } elseif ($pdf && str_contains(substr($bytes, 0, 1024), '%PDF-')) {
                    // a PDF by its header, as a reader looks for it; served as a download, never inline
                    $pictures[] = ['filename' => $name ?: 'file.pdf', 'content_type' => 'application/pdf', 'extension' => 'pdf', 'bytes' => $bytes];
                } elseif (! $pdf && ($info = @getimagesizefromstring($bytes)) && isset(self::TYPES[$info[2]])) {
                    $pictures[] = ['filename' => $name ?: 'image.' . self::TYPES[$info[2]], 'content_type' => image_type_to_mime_type($info[2]), 'extension' => self::TYPES[$info[2]], 'bytes' => $bytes];
                } else {
                    $skip('type', strlen($bytes));
                }
            }
        }

        return $pictures;
    }

    /**
     * The lines put under a letter for the attachments that were not kept, one each: «〔پیوست «نام» (PDF، ۶٫۰ مگابایت)
     * نگه داشته نشد: …〕». Empty when nothing was skipped.
     */
    public static function notice(array $skipped): string
    {
        return implode("\n", array_map(function (array $file) {
            // the kind as the file's name ends («DOCX»), else as the email names it
            $extension = pathinfo($file['filename'], PATHINFO_EXTENSION);
            $type = strtoupper(preg_match('/^[A-Za-z0-9]{1,5}$/', $extension) ? $extension : Str::afterLast($file['content_type'], '/')) ?: 'نامعلوم';
            $name = $file['filename'] !== '' ? "«{$file['filename']}» " : '';

            return "〔پیوست {$name}({$type}، " . self::size((int) $file['size']) . ') نگه داشته نشد: ' . (self::REASONS[$file['reason']] ?? self::REASONS['type']) . '〕';
        }, $skipped));
    }

    /** «۶٫۰ مگابایت», «۳۴۰ کیلوبایت» */
    public static function size(int $bytes): string
    {
        return \App\Support\Fa::digits($bytes >= 1048576 ? number_format($bytes / 1048576, 1, '٫', '') . ' مگابایت' : max(1, (int) round($bytes / 1024)) . ' کیلوبایت');
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
