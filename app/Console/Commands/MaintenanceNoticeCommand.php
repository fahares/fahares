<?php

namespace App\Console\Commands;

use App\Models\Announcement;
use App\Support\AnnouncementTelegram;
use App\Support\MaintenanceNotice;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Backlog item 136, part 4 (step 14.414): the advance notice of a planned maintenance, a strip above every
 * page until it should be over and, with --telegram, a post in the site's channel. See MaintenanceNotice.
 * The maintenance itself (the outside page during the restart) is scripts/dev/maintenance on|off.
 */
class MaintenanceNoticeCommand extends Command
{
    protected $signature = 'fahares:maintenance-notice
        {start? : Tehran time, Solar Hijri «1405/09/06 02:00», or «02:00» for its next coming}
        {--minutes=30 : How long the site is expected to be away}
        {--note= : One more sentence for the notice}
        {--telegram : Also post the notice in the Telegram channel (or edit the post already made)}
        {--clear : Take the notice down}';

    protected $description = 'Announce a planned maintenance on every page and, optionally, in the Telegram channel';

    public function handle(): int
    {
        if ($this->option('clear')) {
            MaintenanceNotice::clear();
            $this->info('The maintenance notice is down.');

            return self::SUCCESS;
        }

        if (! $this->argument('start')) {
            $notice = MaintenanceNotice::current();
            $this->line($notice ? MaintenanceNotice::sentence($notice) . ($notice['note'] ? ' ' . $notice['note'] : '') : 'No maintenance notice.');

            return self::SUCCESS;
        }

        try {
            $at = MaintenanceNotice::parseStart($this->argument('start'));
            $notice = MaintenanceNotice::set($at, (int) $this->option('minutes'), (string) $this->option('note'), (bool) $this->option('telegram'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Every page now shows: ' . MaintenanceNotice::sentence($notice));
        if ($notice['announcement_id']) {
            $a = Announcement::find($notice['announcement_id']);
            $result = AnnouncementTelegram::sync($a);
            $a->refresh();
            $this->line("Telegram: {$result}" . ($a->telegram_error ? " ({$a->telegram_error})" : '')
                . (($url = AnnouncementTelegram::postUrl($a)) ? " {$url}" : ''));
            if ($result === 'failed') {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
