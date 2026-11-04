<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/** Creates the initial system roles. Existing roles (and any edits made to them) are left alone. */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('rbac.roles') as $slug => $role) {
            Role::firstOrCreate(['slug' => $slug], [
                'name' => $role['name'],
                'description' => $role['description'],
                'is_system' => true,
                'is_active' => true,
            ]);
        }
    }
}
