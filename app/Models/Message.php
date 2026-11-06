<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One letter of a MessageThread (backlog item 91); "via" is site or email (a reply to the notification email).
 * client_token is the one send it came from (item 115); an admin soft-deletes a letter that came twice.
 */
class Message extends Model
{
    use SoftDeletes;

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

    /** Pictures attached to an email reply (step 14.36) */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class)->orderBy('id');
    }
}
