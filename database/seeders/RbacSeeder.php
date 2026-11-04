<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Permissions, initial roles and their default permissions (idempotent). */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        (new PermissionSeeder)->run();
        (new RoleSeeder)->run();
        (new RolePermissionSeeder)->run();
    }
}
