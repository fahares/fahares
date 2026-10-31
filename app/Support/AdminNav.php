<?php

namespace App\Support;

use App\Models\FieldSuggestion;
use App\Models\SourceEntry;
use App\Models\User;

/**
 * The admin panel's menu (backlog item 65): four groups, each item shown only to roles holding its permission
 * of the access matrix. Items with work waiting carry its count.
 */
class AdminNav
{
    /** @return array<int, array{title: string, items: array<int, array>}> */
    public static function groups(User $user): array
    {
        $groups = [
            ['title' => 'داوری', 'items' => [
                ['label' => 'پیشخوان', 'route' => 'admin.dashboard', 'active' => 'admin.dashboard', 'can' => 'panel.access'],
                ['label' => 'پیشنهادهای اصلاح', 'route' => 'admin.suggestions.index', 'active' => 'admin.suggestions.*', 'can' => 'suggestions.review',
                    'count' => fn () => FieldSuggestion::inQueue()->count()],
                ['label' => 'ابزارهای ساختاری', 'route' => 'admin.structure.index', 'active' => 'admin.structure.*', 'can' => 'structure.direct'],
                ['label' => 'نشانی‌های خودکار فهرست', 'route' => 'admin.catalog-references.index', 'active' => 'admin.catalog-references.*', 'can' => 'catalog_refs.review',
                    'count' => fn () => SourceEntry::awaitingReview()->where('citable_type', \App\Models\Manuscript::class)->count()],
                ['label' => 'پیوندهای خودکار آثار', 'route' => 'admin.source-links.index', 'active' => 'admin.source-links.*', 'can' => 'catalog_refs.review',
                    'count' => fn () => SourceEntry::awaitingReview()->where('citable_type', \App\Models\Work::class)->whereNotNull('source_item_id')->count()],
                ['label' => 'اعلان‌های من', 'route' => 'notifications.index', 'active' => 'notifications.*', 'can' => 'panel.access',
                    'count' => fn () => $user->unreadNotifications()->count(), 'soft' => true],
            ]],
            ['title' => 'داده‌ها و مراجع', 'items' => [
                ['label' => 'اشخاص', 'route' => 'admin.people.index', 'active' => ['admin.people.index', 'admin.people.edit'], 'can' => 'people.edit'],
                ['label' => 'ادغام اشخاص', 'route' => 'admin.people.merge', 'active' => 'admin.people.merge', 'can' => 'people.merge'],
                ['label' => 'اشخاص هم‌نام', 'route' => 'admin.person-reviews.index', 'active' => fn () => request()->routeIs('admin.person-reviews.*') && request()->query('type') !== 'mixed', 'can' => 'people.merge',
                    'count' => fn () => \App\Models\PersonReview::where('type', 'duplicate')->where('status', 'open')->count()],
                ['label' => 'رکوردهای قاطی‌شده', 'route' => ['admin.person-reviews.index', ['type' => 'mixed']], 'active' => fn () => request()->routeIs('admin.person-reviews.*') && request()->query('type') === 'mixed', 'can' => 'structure.split',
                    'count' => fn () => \App\Models\PersonReview::where('type', 'mixed')->where('status', 'open')->count()],
                ['label' => 'فهرست‌نگاران', 'route' => 'admin.catalogers.index', 'active' => 'admin.catalogers.*', 'can' => 'catalogers.edit'],
                ['label' => 'کتابخانه‌ها', 'route' => 'admin.libraries.index', 'active' => 'admin.libraries.*', 'can' => 'libraries.edit'],
                ['label' => 'موضوعات', 'route' => 'admin.subjects.index', 'active' => ['admin.subjects.index', 'admin.subjects.create', 'admin.subjects.edit', 'admin.subjects.tree'], 'can' => 'subjects.edit'],
                ['label' => 'ادغام موضوعات', 'route' => 'admin.subjects.merge', 'active' => 'admin.subjects.merge', 'can' => 'subjects.merge'],
            ]],
            ['title' => 'محتوای سایت', 'items' => [
                ['label' => 'صفحه‌ی خانه', 'route' => 'admin.home.index', 'active' => 'admin.home.*', 'can' => 'home.manage'],
                ['label' => 'صفحه‌ها', 'route' => ['admin.pages.edit', 'about'], 'active' => 'admin.pages.*', 'can' => 'pages.manage'],
                ['label' => 'نوشته‌ها', 'route' => 'admin.posts.index', 'active' => 'admin.posts.*', 'can' => 'posts.manage'],
            ]],
            ['title' => 'سامانه', 'items' => [
                ['label' => 'کاربران', 'route' => 'admin.users.index', 'active' => 'admin.users.*', 'can' => 'users.manage'],
                ['label' => 'پیام به کاربران', 'route' => 'admin.messages.index', 'active' => 'admin.messages.*', 'can' => 'users.message',
                    'count' => fn () => \App\Models\MessageThread::where('kind', 'direct')->unreadByStaff()->count()],
                ['label' => 'ماتریس دسترسی', 'route' => 'admin.roles.index', 'active' => 'admin.roles.*', 'can' => 'roles.manage'],
                ['label' => 'لاگ ممیزی', 'route' => 'admin.audit-logs.index', 'active' => 'admin.audit-logs.*', 'can' => 'audit.view'],
                ['label' => 'آمار جست‌وجو', 'route' => 'admin.search-stats.index', 'active' => 'admin.search-stats.*', 'can' => 'search.stats'],
                ['label' => 'کش و نمایه‌سازی', 'route' => 'admin.system.index', 'active' => 'admin.system.*', 'can' => 'system.manage'],
            ]],
        ];

        $visible = [];
        foreach ($groups as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                if (! $user->can($item['can'])) {
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
