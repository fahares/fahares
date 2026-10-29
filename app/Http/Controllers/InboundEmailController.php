<?php

namespace App\Http\Controllers;

use App\Messaging\Conversations;
use App\Messaging\ReplyText;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A user's answer by email (backlog item 91). Resend receives the reply domain and posts email.received here,
 * signed the Svix way; the webhook carries no body, so the text is fetched from Resend's receiving API.
 * Filed only when the reply-to token names a thread and the sender is that thread's user; the quoted history
 * is cut off. Anything else is answered 200 and dropped, so Resend does not retry it.
 */
class InboundEmailController extends Controller
{
    public function store(Request $request)
    {
        $secret = (string) config('notifications.inbound.webhook_secret');
        abort_unless($secret && self::verified($request, $secret), 403);
        if ($request->input('type') !== 'email.received') {
            return response()->json(['ok' => true]);
        }

        $data = (array) $request->input('data', []);
        $thread = collect((array) ($data['to'] ?? []))->map(fn ($to) => Conversations::threadForAddress(is_array($to) ? ($to['email'] ?? '') : (string) $to))->filter()->first();
        $from = self::address($data['from'] ?? '');
        if (! $thread || ! $thread->user || strcasecmp($from, (string) $thread->user->email) !== 0) {
            Log::info('Inbound email dropped: no thread or sender is not its user.', ['email_id' => $data['email_id'] ?? null]);

            return response()->json(['ok' => true]);
        }
        if (! $thread->acceptsReplies()) {
            return response()->json(['ok' => true]);
        }

        $email = Http::withToken((string) config('services.resend.key'))->acceptJson()
            ->get('https://api.resend.com/emails/receiving/' . rawurlencode((string) ($data['email_id'] ?? '')));
        if (! $email->successful()) {
            Log::warning('Inbound email could not be fetched: ' . $email->status());

            // Resend retries a failed delivery
            return response()->json(['ok' => false], 502);
        }
        $text = ReplyText::extract($email->json('text'), $email->json('html'));
        if ($text === '') {
            return response()->json(['ok' => true]);
        }

        Conversations::userReply($thread, Str::limit($text, Message::MAX_LENGTH, ''), 'email');

        return response()->json(['ok' => true]);
    }

    /** "Name <a@b>" or "a@b" → a@b */
    private static function address(string $from): string
    {
        return preg_match('~<([^>]+)>~', $from, $m) ? trim($m[1]) : trim($from);
    }

    /** Svix signature: HMAC-SHA256 of "id.timestamp.body" with the base64 secret after "whsec_", within five minutes */
    public static function verified(Request $request, string $secret): bool
    {
        $id = (string) $request->header('svix-id');
        $timestamp = (string) $request->header('svix-timestamp');
        if ($id === '' || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return false;
        }
        $key = base64_decode(Str::after($secret, 'whsec_'), true);
        if ($key === false) {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}." . $request->getContent(), $key, true));
        foreach (explode(' ', (string) $request->header('svix-signature')) as $signature) {
            if (hash_equals($expected, Str::after($signature, ','))) {
                return true;
            }
        }

        return false;
    }
}
