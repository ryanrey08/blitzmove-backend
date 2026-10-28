<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the first administrator from SEED_ADMIN_* environment variables.
 * No password is hard-coded: when SEED_ADMIN_PASSWORD is empty a random one
 * is generated and printed once.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower(env('SEED_ADMIN_EMAIL', 'admin@blitzmove.local'));

        if (User::where('email', $email)->exists()) {
            $this->command?->info("Admin {$email} already exists; leaving it unchanged.");

            return;
        }

        $password = (string) env('SEED_ADMIN_PASSWORD', '');
        $generated = $password === '';
        if ($generated) {
            $password = Str::password(16, symbols: false);
        }

        User::create([
            'name' => env('SEED_ADMIN_NAME', 'BlitzMove Administrator'),
            'email' => $email,
            'password' => $password,
            'role' => UserRole::Admin,
            'job_title' => 'Operations Manager',
            'is_active' => true,
        ])->markEmailAsVerified();

        $this->command?->info("Admin user created: {$email}");
        if ($generated) {
            $this->command?->warn("Generated password (shown once): {$password}");
        }
    }
}
