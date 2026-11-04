<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Rules that stop privilege escalation and lock-outs in User and Role Management.
 * Only a Super Admin can touch Super Admin accounts or the Super Admin role, and
 * nobody can hand out a permission they do not hold themselves.
 */
class AccessControl
{
    public function ensureCanManageUser(User $actor, User $target): void
    {
        if ($target->role?->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Only a Super Admin can manage Super Admin accounts.');
        }
    }

    public function ensureCanAssignRole(User $actor, Role $role): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }
        if ($role->isSuperAdmin()) {
            throw new AuthorizationException('Only a Super Admin can assign the Super Admin role.');
        }
        $missing = array_diff($role->permissionSlugs(), $actor->permissionSlugs());
        if ($missing) {
            throw new AuthorizationException("You cannot assign the {$role->name} role because it has permissions you do not have.");
        }
    }

    /** @param  list<string>  $slugs */
    public function ensureCanGrantPermissions(User $actor, array $slugs): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }
        $missing = array_values(array_diff($slugs, $actor->permissionSlugs()));
        if ($missing) {
            throw new AuthorizationException('You cannot grant permissions you do not have yourself: '.implode(', ', $missing).'.');
        }
    }

    public function ensureCanEditRole(User $actor, Role $role): void
    {
        if ($role->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Only a Super Admin can change the Super Admin role.');
        }
    }

    /** Keeps at least one active Super Admin so the system cannot be locked out. */
    public function ensureNotLastSuperAdmin(User $target, string $field = 'status'): void
    {
        if (! $target->role?->isSuperAdmin()) {
            return;
        }
        $others = User::where('id', '!=', $target->id)
            ->where('status', UserStatus::Active)
            ->whereHas('role', fn ($q) => $q->where('slug', config('rbac.super_admin'))->where('is_active', true))
            ->exists();
        if (! $others) {
            throw ValidationException::withMessages([$field => 'This is the only active Super Admin. Add another Super Admin first.']);
        }
    }

    /** @param  list<string>  $slugs @return list<int> */
    public function permissionIds(array $slugs): array
    {
        return Permission::whereIn('slug', $slugs)->pluck('id')->all();
    }
}
