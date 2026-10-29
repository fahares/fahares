<?php

namespace App\Notifications;

use App\Messaging\Conversations;
use App\Models\MessageThread;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A letter of a staff–user thread (backlog item 91), to the user or to the staff. The email to the user carries
 * the thread's own reply-to address, so answering the email files the answer in the same thread.
 */
class ThreadNotice extends Notice
{
    public function __construct(public MessageThread $thread, string $noticeType, string $heading, array $lines, string $link, string $action = 'مشاهده و پاسخ')
    {
        parent::__construct($noticeType, $heading, $lines, $link, $action);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = parent::toMail($notifiable);
        if ($notifiable instanceof User && $notifiable->id === $this->thread->user_id) {
            // Above the unsubscribe line, which stays last
            $unsubscribe = array_pop($mail->outroLines);
            $mail->line('این پیام از سوی فهارس فرستاده شده است.');
            if ($address = Conversations::replyAddress($this->thread)) {
                $mail->replyTo($address, 'فهارس');
                $mail->line('برای پاسخ می‌توانید به همین ایمیل پاسخ دهید، یا پاسخ را در سایت بنویسید.');
            }
            if ($unsubscribe !== null) {
                $mail->line($unsubscribe);
            }
        }

        return $mail;
    }
}
