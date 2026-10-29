<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A conversation between the site's staff and one user (backlog item 91): about one suggestion (kind
 * "suggestion"), or a direct message from an admin (kind "direct"). The user sees the staff side as
 * «داور فهارس» or «مدیریت فهارس», never a staff member's name or address.
 */
class MessageThread extends Model
{
    public const KIND_SUGGESTION = 'suggestion';

    public const KIND_DIRECT = 'direct';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'closed' => 'boolean',
            'last_message_at' => 'datetime',
            'user_read_at' => 'datetime',
            'staff_read_at' => 'datetime',
            'reported_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(FieldSuggestion::class, 'field_suggestion_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('id');
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function scopeUnreadByUser(Builder $query): Builder
    {
        return $query->whereExists(fn ($q) => $q->from('messages')->whereColumn('messages.message_thread_id', 'message_threads.id')
            ->where('messages.is_staff', true)
            ->where(fn ($w) => $w->whereNull('message_threads.user_read_at')->orWhereColumn('messages.created_at', '>', 'message_threads.user_read_at')));
    }

    /** Threads with an answer of the user that the staff have not opened since */
    public function scopeUnreadByStaff(Builder $query): Builder
    {
        return $query->whereExists(fn ($q) => $q->from('messages')->whereColumn('messages.message_thread_id', 'message_threads.id')
            ->where('messages.is_staff', false)
            ->where(fn ($w) => $w->whereNull('message_threads.staff_read_at')->orWhereColumn('messages.created_at', '>', 'message_threads.staff_read_at')));
    }

    public function unreadByUser(): bool
    {
        return $this->messages->where('is_staff', true)->contains(fn (Message $m) => ! $this->user_read_at || $m->created_at->gt($this->user_read_at));
    }

    public function unreadByStaff(): bool
    {
        return $this->messages->where('is_staff', false)->contains(fn (Message $m) => ! $this->staff_read_at || $m->created_at->gt($this->staff_read_at));
    }

    /** The name the user sees for the staff side */
    public function staffLabel(): string
    {
        return $this->kind === self::KIND_SUGGESTION ? 'داور فهارس' : 'مدیریت فهارس';
    }

    /** Whether the user may still write in it: open, and for a suggestion only while it is pending */
    public function acceptsReplies(): bool
    {
        if ($this->closed) {
            return false;
        }

        return $this->kind !== self::KIND_SUGGESTION || $this->suggestion?->status === 'pending';
    }

    /** The permission a staff member needs to read and write in it */
    public function staffPermission(): string
    {
        return $this->kind === self::KIND_SUGGESTION ? 'suggestions.review' : 'users.message';
    }
}
