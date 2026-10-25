<?php

namespace App\Notifications;

use App\Auth\Permissions;
use App\Models\User;

/** Tells the admins that someone changed the access matrix (backlog item 65), with what was added and removed per role. */
class AccessMatrixChanged extends SiteNotification
{
    public const TYPE = 'permissions.changed';

    /** @param  array<string, array{added: string[], removed: string[]}>  $changes  role label => keys */
    public function __construct(public string $by, public array $changes)
    {
        parent::__construct();
    }

    protected function title(): string
    {
        return 'تغییر دسترسی نقش‌ها';
    }

    /** @return string[] */
    protected function lines(object $notifiable): array
    {
        $lines = ["{$this->by} دسترسی نقش‌ها را در پنل مدیریت تغییر داد."];
        foreach ($this->changes as $role => $change) {
            $parts = [];
            if ($change['added']) {
                $parts[] = 'افزوده: ' . implode('، ', array_map([Permissions::class, 'label'], $change['added']));
            }
            if ($change['removed']) {
                $parts[] = 'برداشته: ' . implode('، ', array_map([Permissions::class, 'label'], $change['removed']));
            }
            $lines[] = "{$role}: " . implode(' — ', $parts);
        }

        return $lines;
    }

    protected function url(object $notifiable): string
    {
        return route('admin.roles.index');
    }

    protected function actionLabel(): string
    {
        return 'ماتریس دسترسی';
    }
}
