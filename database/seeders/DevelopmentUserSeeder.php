<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Development sample accounts, one per initial role (config rbac.sample_users).
 * Runs only when SEED_DEV_USERS=true, and never in production unless
 * ALLOW_DEV_USERS_IN_PRODUCTION=true is also set.
 *
 * Password: DEV_USERS_PASSWORD, or a random one printed once. Accounts are
 * flagged is_sample and existing emails are never overwritten.
 */
class DevelopmentUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! filter_var(env('SEED_DEV_USERS', false), FILTER_VALIDATE_BOOL)) {
            return;
        }
        if (app()->isProduction() && ! filter_var(env('ALLOW_DEV_USERS_IN_PRODUCTION', false), FILTER_VALIDATE_BOOL)) {
            $this->command?->warn('Skipping development users in production (set ALLOW_DEV_USERS_IN_PRODUCTION=true to override).');

            return;
        }

        $password = (string) env('DEV_USERS_PASSWORD', '');
        $generated = $password === '';
        if ($generated) {
            $password = Str::password(14, symbols: false);
        }

        $created = 0;
        foreach (config('rbac.sample_users') as $sample) {
            if (User::withTrashed()->where('email', $sample['email'])->exists()) {
                continue;
            }
            $role = Role::where('slug', $sample['role'])->first();
            $user = User::create([
                'first_name' => $sample['name'],
                'last_name' => '(Sample)',
                'username' => Str::before($sample['email'], '@'),
                'email' => $sample['email'],
                'password' => $password,
                'role_id' => $role?->id,
                'job_title' => $role?->name,
                'status' => UserStatus::Active,
                'is_sample' => true,
            ]);
            $user->markEmailAsVerified();
            $created++;

            if ($sample['role'] === 'driver') {
                Driver::whereNull('user_id')->orderBy('id')->first()?->forceFill(['user_id' => $user->id])->save();
            }
        }

        if ($created) {
            $this->command?->info("Created {$created} development sample account(s).");
            if ($generated) {
                $this->command?->warn("Sample account password (shown once): {$password}");
            }
        }
    }
}
