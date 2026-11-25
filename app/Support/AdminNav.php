<?php

namespace App\Support;

use App\Models\FieldSuggestion;
use App\Models\User;

/**
 * The admin panel's menu (backlog item 65): four groups, each item shown only to roles holding its permission
 * of the access matrix. Items with work waiting carry its count. Since step 14.397 a group is a subject of work
 * (the desk, the records, the site's content, the system); the review queues are one item (ReviewQueues), and
 * making or merging a record is a button of its list.
 */
class AdminNav
{
    /** @return array<int, array{title: string, items: array<int, array>}> */
    public static function groups(User $user): array
    {
        $groups = [
            // the daily work: the users' suggestions keep their own item; the queues the machine fills are one page (step 14.397)
            ['title' => 'کارتابل', 'items' => [
                ['label' => 'پیشخوان', 'route' => 'admin.dashboard', 'active' => 'admin.dashboard', 'can' => 'panel.access'],
                ['label' => 'اعلان‌های من', 'route' => 'notifications.index', 'active' => 'notifications.*', 'can' => 'panel.access',
                    'count' => fn () => $user->unreadNotifications()->count(), 'soft' => true],
                ['label' => 'پیشنهادهای اصلاح', 'route' => 'admin.suggestions.index', 'active' => 'admin.suggestions.*', 'can' => 'suggestions.review',
                    'count' => fn () => FieldSuggestion::inQueue()->count()],
                ['label' => 'صف‌های بررسی', 'route' => 'admin.review-queues', 'active' => fn () => ReviewQueues::isActive($user), 'can' => 'panel.access',
                    'count' => fn () => ReviewQueues::count($user), 'show' => fn () => ReviewQueues::forUser($user) !== []],
                ['label' => 'ابزارهای ساختاری', 'route' => 'admin.structure.index', 'active' => 'admin.structure.*', 'can' => 'structure.direct'],
            ]],
            // the records: making one and merging two are buttons of their list (step 14.397)
            ['title' => 'داده‌ها', 'items' => [
                ['label' => 'اشخاص', 'route' => 'admin.people.index', 'active' => ['admin.people.index', 'admin.people.edit', 'admin.people.create', 'admin.people.merge'], 'can' => 'people.edit'],
                ['label' => 'آثار', 'route' => 'admin.works.index', 'active' => 'admin.works.*', 'can' => 'panel.access'],
                ['label' => 'نسخه‌ها', 'route' => 'admin.manuscripts.index', 'active' => 'admin.manuscripts.*', 'can' => 'panel.access'],
                ['label' => 'کتابخانه‌ها', 'route' => 'admin.libraries.index', 'active' => 'admin.libraries.*', 'can' => 'libraries.edit'],
                ['label' => 'منابع', 'route' => 'admin.sources.index', 'active' => 'admin.sources.*', 'can' => 'sources.edit'],
                ['label' => 'فهرست‌نگاران', 'route' => 'admin.catalogers.index', 'active' => 'admin.catalogers.*', 'can' => 'catalogers.edit'],
                ['label' => 'موضوعات', 'route' => 'admin.subjects.index', 'active' => 'admin.subjects.*', 'can' => 'subjects.edit'],
            ]],
            ['title' => 'محتوای سایت', 'items' => [
                ['label' => 'صفحۀ خانه', 'route' => 'admin.home.index', 'active' => 'admin.home.*', 'can' => 'home.manage'],
                ['label' => 'صفحه‌ها', 'route' => ['admin.pages.edit', 'about'], 'active' => 'admin.pages.*', 'can' => 'pages.manage'],
                ['label' => 'نوشته‌ها', 'route' => 'admin.posts.index', 'active' => 'admin.posts.*', 'can' => 'posts.manage'],
                ['label' => 'اعلان‌های سایت', 'route' => 'admin.announcements.index', 'active' => 'admin.announcements.*', 'can' => 'announcements.manage'],
                ['label' => 'قالب ایمیل‌ها', 'route' => 'admin.mail.index', 'active' => 'admin.mail.*', 'can' => 'home.manage'],
            ]],
            ['title' => 'سامانه', 'items' => [
                ['label' => 'کاربران', 'route' => 'admin.users.index', 'active' => 'admin.users.*', 'can' => 'users.manage'],
                ['label' => 'پیام به کاربران', 'route' => 'admin.messages.index', 'active' => 'admin.messages.*', 'can' => 'users.message',
                    'count' => fn () => \App\Models\MessageThread::where('kind', 'direct')->unreadByStaff()->count()],
                ['label' => 'ماتریس دسترسی', 'route' => 'admin.roles.index', 'active' => 'admin.roles.*', 'can' => 'roles.manage'],
                ['label' => 'لاگ ممیزی', 'route' => 'admin.audit-logs.index', 'active' => 'admin.audit-logs.*', 'can' => 'audit.view'],
                ['label' => 'آمار جست‌وجو', 'route' => 'admin.search-stats.index', 'active' => 'admin.search-stats.*', 'can' => 'search.stats'],
                ['label' => 'آمار بازدید', 'route' => 'admin.visit-stats.index', 'active' => 'admin.visit-stats.*', 'can' => 'visits.stats'],
                ['label' => 'کش و نمایه‌سازی', 'route' => 'admin.system.index', 'active' => 'admin.system.*', 'can' => 'system.manage'],
            ]],
        ];

        $visible = [];
        foreach ($groups as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                if (! $user->can($item['can']) || (isset($item['show']) && ! ($item['show'])())) {
                    continue;
                }
                [$name, $params] = is_array($item['route']) ? $item['route'] : [$item['route'], []];
                $items[] = [
                    'label' => $item['label'],
                    'url' => route($name, $params),
                    // a route pattern, or a check for items that share a route and differ by query
                    'active' => $item['active'] instanceof \Closure ? ($item['active'])() : request()->routeIs(...(array) $item['active']),
                    'count' => isset($item['count']) ? ($item['count'])() : 0,
                    'soft' => $item['soft'] ?? false,
                ];
            }
            if ($items) {
                $visible[] = ['title' => $group['title'], 'items' => $items];
            }
        }

        return $visible;
    }

    /** The group of the page being shown, for the small heading above its title */
    public static function currentGroup(array $groups): ?string
    {
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                if ($item['active']) {
                    return $group['title'];
                }
            }
        }

        return null;
    }
}
