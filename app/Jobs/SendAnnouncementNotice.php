<?php

namespace App\Jobs;

use App\Models\Announcement;
use App\Notifications\AnnouncementNotice;
use App\Notifications\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Puts a notice that has just begun its first run into every member's notifications, over the channels
 * each one chose. It runs in the queue, so saving the notice does not wait for the members' list.
 */
class SendAnnouncementNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public int $announcementId) {}

    public function handle(): void
    {
        $announcement = Announcement::find($this->announcementId);
        // Switched off or deleted before the queue reached it: nothing goes out
        if (! $announcement || ! $announcement->isRunning() || $announcement->audience === 'guests') {
            return;
        }
        NotificationPreferences::broadcast(AnnouncementNotice::TYPE, new AnnouncementNotice($announcement));
    }

    /** Called after each save: the first time a notice for members runs, and never again for it. */
    public static function forFirstRun(Announcement $announcement): bool
    {
        if ($announcement->notified_at || ! $announcement->isRunning() || $announcement->audience === 'guests') {
            return false;
        }
        $announcement->forceFill(['notified_at' => now()])->saveQuietly();
        self::dispatch($announcement->id);

        return true;
    }
}
