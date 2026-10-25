<?php

namespace App\Listeners;

use App\Notifications\SystemNotices;
use Illuminate\Queue\Events\JobFailed;

/** Without this, a notification email that keeps failing is lost silently (backlog item 25). */
class NotifyQueueFailure
{
    public function handle(JobFailed $event): void
    {
        SystemNotices::queueFailed($event->job->resolveName(), $event->exception);
    }
}
