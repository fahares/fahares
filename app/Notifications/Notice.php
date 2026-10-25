<?php

namespace App\Notifications;

/**
 * A notification whose text is the same for every recipient (backlog item 25): system alerts, security
 * notices, review reminders. The type decides who may receive it and over which channels.
 */
class Notice extends SiteNotification
{
    /** @param  string[]  $lines */
    public function __construct(public string $noticeType, public string $heading, public array $lines, public string $link, public string $action = 'مشاهده')
    {
        parent::__construct();
    }

    public function type(): string
    {
        return $this->noticeType;
    }

    protected function title(): string
    {
        return $this->heading;
    }

    protected function lines(object $notifiable): array
    {
        return $this->lines;
    }

    protected function url(object $notifiable): string
    {
        return $this->link;
    }

    protected function actionLabel(): string
    {
        return $this->action;
    }
}
