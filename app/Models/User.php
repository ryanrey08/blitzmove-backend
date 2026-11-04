<?php

namespace App\Models;

use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'username', 'first_name', 'middle_name', 'last_name', 'email', 'password', 'role_id', 'job_title', 'phone', 'status', 'is_sample'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements CanResetPassword
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /** @var list<string>|null */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'is_sample' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // The display name everywhere else in the app is composed from the name parts.
        static::saving(function (User $user) {
            if (($user->first_name || $user->last_name) && $user->isDirty(['first_name', 'middle_name', 'last_name'])) {
                $user->name = trim(implode(' ', array_filter([$user->first_name, $user->middle_name, $user->last_name])));
            }
            $user->permissionCache = null;
        });
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function driver(): HasOne
    {
        return $this->hasOne(Driver::class);
    }

    public function canAuthenticate(): bool
    {
        return ($this->status ?? UserStatus::Active)->canAuthenticate() && ! $this->trashed();
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->role?->is_active && $this->role->isSuperAdmin();
    }

    public function hasRole(string $slug): bool
    {
        return $this->role?->slug === $slug;
    }

    /**
     * Permission slugs granted through the user's role. An inactive role grants
     * nothing; Super Admin always has every permission.
     *
     * @return list<string>
     */
    public function permissionSlugs(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }

        $role = $this->role;
        if (! $role || ! $role->is_active) {
            return $this->permissionCache = [];
        }
        $role->loadMissing('permissions');

        return $this->permissionCache = $role->permissionSlugs();
    }

    public function hasPermission(string $slug): bool
    {
        return $this->isSuperAdmin() || in_array($slug, $this->permissionSlugs(), true);
    }

    /** @param  list<string>  $slugs */
    public function hasAnyPermission(array $slugs): bool
    {
        foreach ($slugs as $slug) {
            if ($this->hasPermission($slug)) {
                return true;
            }
        }

        return false;
    }

    /** Driver profile ids whose trips a user limited to trips.view_assigned may see. */
    public function seesOnlyAssignedTrips(): bool
    {
        return ! $this->hasAnyPermission(['trips.view', 'dispatch.view']) && $this->hasPermission('trips.view_assigned');
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCache = null;
        $this->unsetRelation('role');
    }
}
