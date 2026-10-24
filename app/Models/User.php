<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'google_id', 'password', 'role', 'affiliation', 'is_verified_scholar'])]
#[Hidden(['password', 'remember_token', 'google_id'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_verified_scholar' => 'boolean',
        ];
    }

    /** In Persian whatever APP_LOCALE says, like the other notifications. */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify((new \Illuminate\Auth\Notifications\VerifyEmail)->locale('fa'));
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify((new \Illuminate\Auth\Notifications\ResetPassword($token))->locale('fa'));
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isEditor(): bool
    {
        return in_array($this->role, ['admin', 'editor']);
    }

    /** Whether the user may enter the admin panel; each section has its own permission (App\Auth\Permissions) */
    public function canAccessAdmin(): bool
    {
        return $this->hasPermission('panel.access');
    }

    /** A permission of the access matrix; also available as a Gate ability, e.g. `can:people.edit` */
    public function hasPermission(string $key): bool
    {
        return \App\Auth\Permissions::allows($this->role, $key);
    }

    /** The user's picture, uploaded or copied from Google (backlog item 66); null means the initial of the name. */
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? asset('storage/' . $this->avatar_path) : null;
    }

    public function suggestions()
    {
        return $this->hasMany(FieldSuggestion::class);
    }

    public function annotations()
    {
        return $this->hasMany(ScholarlyAnnotation::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }
}
