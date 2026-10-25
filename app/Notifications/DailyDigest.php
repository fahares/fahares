<?php

namespace App\Notifications;

use App\Models\User;

/** The daily email of `notifications:digest` (backlog item 25): one section per type the user gathers. */
class DailyDigest extends SiteNotification
{
    public const TYPE = 'digest';

    /** @param  array<string, string[]>  $sections  heading => lines */
    public function __construct(public array $sections)
    {
        parent::__construct();
    }

    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && $notifiable->email ? ['mail'] : [];
    }

    protected function title(): string
    {
        return 'خلاصه‌ی روزانه‌ی فهارس';
    }

    protected function lines(object $notifiable): array
    {
        $lines = [];
        foreach ($this->sections as $heading => $items) {
            $lines[] = "**{$heading}**";
            array_push($lines, ...$items);
        }
        $lines[] = 'آنچه در این خلاصه می‌آید، در ستون «خلاصه‌ی روزانه» تنظیمات اعلان انتخاب می‌شود: ' . route('notifications.settings');

        return $lines;
    }

    protected function url(object $notifiable): string
    {
        return $notifiable instanceof User && $notifiable->hasPermission('suggestions.review') ? route('admin.suggestions.index') : route('notifications.index');
    }

    protected function actionLabel(): string
    {
        return 'میز داوری';
    }
}
