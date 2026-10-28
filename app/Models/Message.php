<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One letter of a MessageThread (backlog item 91); "via" is site or email (a reply to the notification email). */
class Message extends Model
{
    public const MAX_LENGTH = 5000;

    protected $guarded = ['id'];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class, 'message_thread_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
