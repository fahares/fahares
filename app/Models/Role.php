<?php

namespace App\Models;

use App\Auth\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A user role, referenced by users.role through its key. What each role may do in the admin panel
 * is the access matrix (App\Auth\Permissions); new roles can be added as rows here.
 */
class Role extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /** The roles whose grants the matrix page edits: all but the admin, who holds every permission */
    public function scopeEditable(Builder $query): Builder
    {
        return $query->where('key', '!=', Permissions::SUPER_ROLE)->orderBy('sort_order');
    }

    /** key => label, in display order */
    public static function options(): array
    {
        return self::orderBy('sort_order')->pluck('label', 'key')->all();
    }
}
