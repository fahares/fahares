<?php

namespace App\Support;

use Illuminate\Database\LostConnectionDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Backlog item 136, part 3 (step 14.412): while the database restarts or refuses connections, every page
 * failed with Laravel's plain English 500 page, which Traefik's fahares-down middleware (502-504) does not
 * cover. Such errors now answer 503 with the fahares-down page itself (docker/down/index.html), so the
 * visitor sees the same notice as when the app is down. /up does not touch the database and keeps
 * answering 200, so the page polls its own address instead.
 */
class DatabaseUnavailable
{
    /** MySQL/MariaDB codes for a server that is down, starting, stopping or full */
    private const CODES = [1040, 1053, 2002, 2003, 2005, 2006, 2013];

    public const RETRY_AFTER = '30';

    public static function causedBy(Throwable $e): bool
    {
        $detector = new LostConnectionDetector;
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            if ($detector->causedByLostConnection($t)) {
                return true;
            }
            $info = $t instanceof \PDOException ? ($t->errorInfo ?? null) : null;
            if (is_array($info) && in_array((int) ($info[1] ?? 0), self::CODES, true)) {
                return true;
            }
            if (preg_match('/SQLSTATE\[\w+\] \[(\d+)\]/', $t->getMessage(), $m) && in_array((int) $m[1], self::CODES, true)) {
                return true;
            }
        }

        return false;
    }

    public static function render(Throwable $e, Request $request): Response|JsonResponse|null
    {
        if (! self::causedBy($e)) {
            return null;
        }
        $headers = [
            'Retry-After' => self::RETRY_AFTER,
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ];
        if ($request->expectsJson() || $request->is('api/*')) {
            return new JsonResponse(['message' => 'سایت چند لحظه در دسترس نیست.'], 503, $headers);
        }

        return new Response(self::page(), 503, $headers + ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function page(): string
    {
        $file = base_path('docker/down/index.html');
        $html = is_readable($file) ? (string) file_get_contents($file) : '';
        if ($html === '') {
            return '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>فهارس</title>'
                .'<p>سایت چند لحظه در دسترس نیست. لطفاً اندکی بعد دوباره بیایید.</p></html>';
        }

        // /up answers 200 even now, so the page waits for its own address to answer
        return str_replace("var probe = '/up';", 'var probe = location.href;', $html);
    }
}
