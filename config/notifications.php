<?php

/*
| The notification types the site sends and the channels each can use. Adding a type here
| extends the settings page without a database change; a user's choices are stored per
| type and channel in notification_preferences, and a missing row means the default below.
|
| roles: who may receive it ('*' = any signed-in user)
| channels: channel => default for users who have not chosen
| group: site-level channels sent once per event, not per user (e.g. the reviewers' Telegram group)
*/

return [
    'channels' => [
        'database' => 'داخل سایت',
        'mail' => 'ایمیل',
    ],

    'types' => [
        'suggestion.submitted' => [
            'label' => 'پیشنهاد اصلاحی تازه برای داوری',
            'roles' => ['admin', 'editor'],
            'channels' => ['database' => true, 'mail' => true],
            'group' => ['telegram'],
        ],
        'suggestion.reviewed' => [
            'label' => 'نتیجه‌ی داوری پیشنهادهای من، و تغییر وضعیت قول‌هایم',
            'roles' => ['*'],
            'channels' => ['database' => true, 'mail' => true],
        ],
    ],

    // The reviewers' group; both values are set in the server environment, never in code
    'telegram' => [
        'bot_token' => env('TELEGRAM_NOTIFY_BOT_TOKEN'),
        'reviewers_chat_id' => env('TELEGRAM_REVIEWERS_CHAT_ID'),
    ],
];
