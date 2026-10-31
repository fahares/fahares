<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/**
 * One search made on the site (backlog item 83, phase 0): anonymous for guests and for users who turned their
 * search history off, otherwise kept with the account.
 */
class SearchLog extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    public const KIND_SEARCH = 'search';

    /** A download of the results as a file (backlog item 85) */
    public const KIND_EXPORT = 'export';

    /** How long a search is kept */
    public const KEEP_DAYS = 365;

    protected $guarded = [];

    protected $casts = [
        'filters' => 'array',
        'is_member' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /** Whether a request comes from a person, worth counting */
    public static function isCountable(Request $request): bool
    {
        return \App\Support\Visitors::isPerson($request);
    }
}
