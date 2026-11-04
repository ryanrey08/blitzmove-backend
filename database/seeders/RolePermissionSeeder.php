<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Gives each initial role its default permissions. Super Admin always gets
 * every permission. Other roles are only filled while they have none, so
 * permissions edited in Role Management are never overwritten on a re-run.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $all = Permission::pluck('id', 'slug');

        foreach (config('rbac.roles') as $slug => $definition) {
            $role = Role::where('slug', $slug)->first();
            if (! $role) {
                continue;
            }

            if ($role->isSuperAdmin()) {
                $role->permissions()->sync($all->values());

                continue;
            }

            if ($role->permissions()->exists()) {
                continue;
            }

            $ids = $all->filter(fn ($id, $permission) => collect($definition['permissions'])
                ->contains(fn ($pattern) => Str::is($pattern, $permission)))->values();
            $role->permissions()->sync($ids);
        }
    }
}
