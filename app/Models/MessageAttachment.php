<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A picture attached to an email reply (step 14.36), kept on the public disk and served by MessageAttachmentController */
class MessageAttachment extends Model
{
    public const DIRECTORY = 'message-attachments';

    protected $guarded = ['id'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
