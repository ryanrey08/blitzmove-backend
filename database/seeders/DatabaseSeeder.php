<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            RolePermissionSeeder::class,
            DefaultAdminSeeder::class,
        ]);

        if (filter_var(env('SEED_DEMO_DATA', ! app()->isProduction()), FILTER_VALIDATE_BOOL)) {
            $this->call(DemoFleetSeeder::class);
        }

        // After demo data so the sample Driver account can be linked to a driver profile.
        $this->call(DevelopmentUserSeeder::class);
    }
}
