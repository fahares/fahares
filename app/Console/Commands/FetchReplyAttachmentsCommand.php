<?php

namespace App\Console\Commands;

use App\Messaging\EmailAttachments;
use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Fetches the pictures of an email reply filed before they were kept (step 14.36), while Resend still holds the
 * email. The email is the one Resend received from the thread's user within five minutes of the letter, unless
 * --email names it; a letter that already has pictures is left alone. Gmail's «[image: …]» markers leave its text.
 */
class FetchReplyAttachmentsCommand extends Command
{
    protected $signature = 'fahares:fetch-reply-attachments
        {message : The id of the letter (messages.id) filed from the email}
        {--email= : The Resend id of the received email, when it cannot be found by its sender and time}
        {--dry-run : Show what would be fetched, without keeping anything}';

    protected $description = 'Fetch from Resend the pictures attached to an email reply filed without them';

    public function handle(): int
    {
        $message = Message::with(['thread.user', 'attachments'])->find($this->argument('message'));
        if (! $message || $message->via !== 'email' || $message->is_staff) {
            $this->error('No letter of a user filed from an email has that id.');

            return self::FAILURE;
        }
        if ($message->attachments->isNotEmpty()) {
            $this->info('The letter already has ' . $message->attachments->count() . ' picture(s); nothing to do.');

            return self::SUCCESS;
        }

        $emailId = $this->option('email') ?: $this->findEmail($message);
        if (! $emailId) {
            $this->error('No email from the thread\'s user within five minutes of the letter was found in Resend; pass --email.');

            return self::FAILURE;
        }

        $pictures = EmailAttachments::download($emailId);
        if ($pictures === null) {
            $this->error('Resend could not be reached.');

            return self::FAILURE;
        }
        $this->line("Email {$emailId}: " . count($pictures) . ' picture(s), ' . number_format(array_sum(array_map(fn ($p) => strlen($p['bytes']), $pictures))) . ' bytes.');
        if (! $pictures || $this->option('dry-run')) {
            return self::SUCCESS;
        }

        EmailAttachments::keep($message, $pictures);
        $message->update(['body' => EmailAttachments::withoutMarkers((string) $message->body)]);
        $this->info('Kept ' . count($pictures) . " picture(s) with letter {$message->id}.");

        return self::SUCCESS;
    }

    /** The received email from the thread's user nearest the letter's time, within five minutes */
    private function findEmail(Message $message): ?string
    {
        $list = Http::withToken((string) config('services.resend.key'))->acceptJson()
            ->get('https://api.resend.com/emails/receiving', ['limit' => 100]);
        if (! $list->successful()) {
            return null;
        }
        $address = strtolower((string) $message->thread?->user?->email);

        return collect((array) $list->json('data', []))
            ->filter(function ($email) use ($address) {
                $from = (string) ($email['from'] ?? '');
                $from = preg_match('~<([^>]+)>~', $from, $m) ? $m[1] : $from;

                return $address !== '' && strtolower(trim($from)) === $address && ! empty($email['created_at']);
            })
            ->map(fn ($email) => ['id' => $email['id'] ?? null, 'gap' => abs(Carbon::parse($email['created_at'])->diffInSeconds($message->created_at))])
            ->filter(fn ($email) => $email['id'] && $email['gap'] <= 300)
            ->sortBy('gap')
            ->first()['id'] ?? null;
    }
}
