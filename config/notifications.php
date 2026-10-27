<?php

/*
| The notification types the site sends and the channels each can use. Adding a type here
| extends the settings page without a database change; a user's choices are stored per
| type and channel in notification_preferences, and a missing row means the default below.
|
| roles: who may receive it ('*' = any signed-in user); or permission: the roles that hold this permission
|        of the access matrix (App\Auth\Permissions), as edited at admin/roles
| channels: channel => default for users who have not chosen
| locked: security notices; the defaults always hold and the settings page shows them fixed
| group: site-level channels sent once per event, not per user (the reviewers' Telegram group, 'telegram';
|        the admins' alerts chat, 'alerts')
|
| Channels: webpush needs a subscribed browser (any switchable type may use it, off by default), telegram a linked chat (backlog item 25); digest is not
| sent per event but gathered into the daily email of `notifications:digest`.
*/

return [
    'channels' => [
        'database' => 'داخل سایت',
        'mail' => 'ایمیل',
        'webpush' => 'مرورگر',
        'telegram' => 'تلگرام',
        'digest' => 'خلاصه‌ی روزانه',
    ],

    'types' => [
        'suggestion.submitted' => [
            'label' => 'پیشنهاد اصلاحی تازه برای داوری',
            'permission' => 'suggestions.review',
            'channels' => ['database' => true, 'mail' => true, 'webpush' => false, 'telegram' => false, 'digest' => false],
            'group' => ['telegram'],
        ],
        'collective.opinions' => [
            'label' => 'نظرهای تازه‌ی «تصحیح جمعی»',
            'permission' => 'suggestions.review',
            'channels' => ['digest' => true],
        ],
        'review.stale' => [
            'label' => 'یادآور هفتگی پیشنهادهایی که بیش از یک هفته بی‌داوری مانده‌اند',
            'permission' => 'suggestions.review',
            'channels' => ['database' => true, 'mail' => true, 'webpush' => false, 'telegram' => false],
        ],
        'review.conflict' => [
            'label' => 'ویرایش یا ادغام رکوردی که پیشنهاد در انتظار داوری دارد، به دست دیگری',
            'permission' => 'suggestions.review',
            'channels' => ['database' => true, 'mail' => false, 'webpush' => false, 'telegram' => false],
        ],
        'suggestion.reviewed' => [
            'label' => 'نتیجه‌ی داوری پیشنهادهای من، و تغییر وضعیت قول‌هایم',
            'roles' => ['*'],
            'channels' => ['database' => true, 'mail' => true, 'webpush' => false, 'telegram' => false],
        ],
        'search.export_ready' => [
            'label' => 'آماده شدن خروجی بزرگ جستجو',
            'roles' => ['*'],
            'channels' => ['database' => true, 'mail' => true, 'webpush' => false, 'telegram' => false],
        ],
        'account.role' => [
            'label' => 'تغییر نقش حساب من، یا تأیید آن به‌عنوان پژوهشگر',
            'roles' => ['*'],
            'channels' => ['database' => true, 'mail' => true, 'webpush' => false],
        ],
        'account.security' => [
            'label' => 'تغییر ایمیل یا گذرواژه‌ی حساب من',
            'roles' => ['*'],
            'channels' => ['database' => true, 'mail' => true],
            'locked' => true,
        ],
        'account.new_device' => [
            'label' => 'ورود به حساب من از دستگاه یا مرورگر تازه',
            'permission' => 'panel.access',
            'channels' => ['database' => true, 'mail' => true],
            'locked' => true,
        ],
        'user.registered' => [
            'label' => 'ثبت‌نام کاربر تازه',
            'permission' => 'users.manage',
            'channels' => ['database' => true, 'mail' => true, 'webpush' => false, 'telegram' => false, 'digest' => false],
        ],
        'user.role_changed' => [
            'label' => 'تغییر نقش کاربران',
            'roles' => ['admin'],
            'channels' => ['database' => true, 'mail' => true],
            'locked' => true,
        ],
        'permissions.changed' => [
            'label' => 'تغییر دسترسی نقش‌ها در پنل مدیریت',
            'roles' => ['admin'],
            'channels' => ['database' => true, 'mail' => true, 'webpush' => false],
        ],
        'system.queue_failed' => [
            'label' => 'شکست کارهای صف (مانند ایمیل‌های اعلان)',
            'roles' => ['admin'],
            'channels' => ['database' => true, 'mail' => true, 'webpush' => false, 'telegram' => false],
            'group' => ['alerts'],
        ],
        'system.search_failed' => [
            'label' => 'خطای نمایه‌ی جستجو (Meilisearch)',
            'roles' => ['admin'],
            'channels' => ['database' => true, 'mail' => true, 'webpush' => false, 'telegram' => false],
            'group' => ['alerts'],
        ],
        'system.command_finished' => [
            'label' => 'پایان دستورهای سنگین روی سرور (نمایه‌سازی، فهرست‌ها)',
            'roles' => ['admin'],
            'channels' => ['database' => true, 'mail' => false, 'webpush' => false, 'telegram' => false],
            'group' => ['alerts'],
        ],
    ],

    // Artisan commands whose end is announced on production (system.command_finished); * matches any tail
    'heavy_commands' => ['scout:import', 'scout:sync-index-settings', 'catalogs:*', 'sources:*', 'fahares:seed'],

    // A browser just subscribed switches on its column for these types only; the others stay as the user set them
    'push_on_subscribe' => ['suggestion.submitted', 'suggestion.reviewed'],

    // Pending suggestions older than this many days are listed in the weekly reminder
    'stale_after_days' => 7,

    // Both bots' values are set in the server environment, never in code
    'telegram' => [
        'bot_token' => env('TELEGRAM_NOTIFY_BOT_TOKEN'),
        // The bot's @username, for the t.me/<bot>?start=<token> link a user follows to link a personal chat
        'bot_username' => env('TELEGRAM_NOTIFY_BOT_USERNAME'),
        // Telegram sends it back in a header on every webhook call; set with notifications:telegram-webhook
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'reviewers_chat_id' => env('TELEGRAM_REVIEWERS_CHAT_ID'),
        // The admins' alerts chat (e.g. the «Fahares Backup» channel)
        'alerts_chat_id' => env('TELEGRAM_ALERTS_CHAT_ID'),
    ],
];
