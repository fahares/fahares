<?php

namespace App\Messaging;

/**
 * The new text of an email reply (backlog item 91): the plain text (or the HTML made plain), cut at the first line
 * that opens the quoted history (invisible direction marks aside) — «On … wrote:», its Persian and Arabic forms, a forwarded-header block, or the
 * «>» quote lines — and at a signature delimiter.
 */
class ReplyText
{
    private const QUOTE_HEADERS = [
        '~^On .{4,300}wrote:\s*$~iu',
        '~^در تاریخ .{2,300}نوشت\s*:?\s*$~u',
        '~^.{2,300}نوشت\s*:\s*$~u',
        '~^في .{2,300}كتب\s*:?\s*$~u',
        '~^-{2,}\s*(Original Message|Forwarded message|پیام اصلی)\s*-{2,}~iu',
        '~^(From|از|Sent|فرستنده)\s*:\s~iu',
        '~^_{5,}\s*$~u',
    ];

    public static function extract(?string $text, ?string $html = null): string
    {
        if (trim((string) $text) === '' && $html) {
            $text = html_entity_decode(strip_tags(preg_replace(['~<br\s*/?>~i', '~</(p|div|li|tr|h\d)>~i', '~<blockquote.*?</blockquote>~is'], ["\n", "\n", ''], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $lines = preg_split("~\r\n|\r|\n~", (string) $text);
        $kept = [];
        foreach ($lines as $line) {
            // Gmail wraps the «On … wrote:» line in invisible direction marks (U+202A, U+202B, U+200E …)
            $trimmed = trim(preg_replace('~\p{Cf}+~u', '', $line));
            if ($trimmed === '--' || $trimmed === '-- ') {
                break;
            }
            foreach (self::QUOTE_HEADERS as $pattern) {
                if (preg_match($pattern, $trimmed)) {
                    break 2;
                }
            }
            if (str_starts_with($trimmed, '>')) {
                continue;
            }
            $kept[] = rtrim($line);
        }

        return trim(preg_replace("~\n{3,}~", "\n\n", implode("\n", $kept)));
    }
}
