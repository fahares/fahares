<?php

namespace App\Support;

/**
 * A contact number typed by a user, stored in E.164 (backlog item 74). Persian and Arabic digits, spaces,
 * dashes, dots and brackets are accepted. A number without a country code is taken as Iranian
 * («09121234567» → «+989121234567»); other countries need «+» or «00» before the code.
 */
class Phone
{
    private const DIGITS = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /** The number in E.164, or null when it cannot be read as a phone number. */
    public static function normalize(?string $input): ?string
    {
        $s = preg_replace('/[\s\-\.\(\)\x{200C}\x{200F}\x{200E}]+/u', '', strtr(trim((string) $input), self::DIGITS));
        if ($s === '' || ! preg_match('/^\+?\d+$/', $s)) {
            return null;
        }

        if (str_starts_with($s, '00')) {
            $s = '+' . substr($s, 2);
        } elseif (str_starts_with($s, '0')) {
            // Iranian trunk prefix: 0912…, 021…
            $s = '+98' . substr($s, 1);
        } elseif (! str_starts_with($s, '+')) {
            // Without a code or a leading zero, only an Iranian mobile written as 912… or 98912… can be read
            $s = match (true) {
                (bool) preg_match('/^9\d{9}$/', $s) => '+98' . $s,
                (bool) preg_match('/^989\d{9}$/', $s) => '+' . $s,
                default => '',
            };
        }
        // «+98 0912…» keeps a trunk zero after the code
        $s = preg_replace('/^\+980/', '+98', $s);

        if (! preg_match('/^\+[1-9]\d{6,14}$/', $s)) {
            return null;
        }
        // Iranian numbers are ten digits after the code
        if (str_starts_with($s, '+98') && strlen($s) !== 13) {
            return null;
        }

        return $s;
    }

    /** A validation rule for an optional phone field. */
    public static function rule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (trim((string) $value) !== '' && self::normalize((string) $value) === null) {
                $fail('شماره‌ی تماس خوانا نیست. شماره‌ی همراه را مانند ۰۹۱۲۱۲۳۴۵۶۷ بنویسید، و شماره‌ی خارج از ایران را با + و کد کشور.');
            }
        };
    }
}
