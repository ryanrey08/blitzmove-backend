<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(AdminUserSeeder::class);

        if (filter_var(env('SEED_DEMO_DATA', ! app()->isProduction()), FILTER_VALIDATE_BOOL)) {
            $this->call(DemoFleetSeeder::class);
        }
    }
}
