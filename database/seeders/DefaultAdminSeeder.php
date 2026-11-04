<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the first Super Admin from the environment:
 *   SEED_DEFAULT_ADMIN=true
 *   DEFAULT_ADMIN_NAME="System Administrator"
 *   DEFAULT_ADMIN_EMAIL=superadmin@blitzmove.local
 *   DEFAULT_ADMIN_PASSWORD=...
 * (SEED_ADMIN_NAME / SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD are still read as fallbacks.)
 *
 * No password is ever hard-coded. In production a missing password means no
 * account is created; elsewhere a random one is generated and printed once.
 * An existing account with the same email is left untouched.
 */
class DefaultAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! filter_var(env('SEED_DEFAULT_ADMIN', true), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $email = strtolower((string) (env('DEFAULT_ADMIN_EMAIL') ?: env('SEED_ADMIN_EMAIL') ?: 'superadmin@blitzmove.local'));
        if (User::withTrashed()->where('email', $email)->exists()) {
            $this->command?->info("Super Admin {$email} already exists; leaving it unchanged.");

            return;
        }

        $password = (string) (env('DEFAULT_ADMIN_PASSWORD') ?: env('SEED_ADMIN_PASSWORD') ?: '');
        $generated = $password === '';
        if ($generated && app()->isProduction()) {
            $this->command?->error('DEFAULT_ADMIN_PASSWORD is not set; no Super Admin account was created.');

            return;
        }
        if ($generated) {
            $password = Str::password(16, symbols: false);
        }

        $name = (string) (env('DEFAULT_ADMIN_NAME') ?: env('SEED_ADMIN_NAME') ?: 'System Administrator');
        $parts = preg_split('/\s+/', trim($name)) ?: [$name];
        $first = array_shift($parts);

        $user = User::create([
            'first_name' => $first,
            'last_name' => $parts ? implode(' ', $parts) : null,
            'name' => $name,
            'username' => $this->username($email),
            'email' => $email,
            'password' => $password,
            'role_id' => Role::where('slug', config('rbac.super_admin'))->value('id'),
            'job_title' => 'System Administrator',
            'status' => UserStatus::Active,
        ]);
        $user->markEmailAsVerified();

        $this->command?->info("Super Admin created: {$email}");
        if ($generated) {
            $this->command?->warn("Generated password (shown once): {$password}");
        }
    }

    private function username(string $email): string
    {
        $base = Str::of(Str::before($email, '@'))->lower()->replaceMatches('/[^a-z0-9._-]/', '')->value() ?: 'superadmin';
        $candidate = $base;
        for ($i = 2; User::withTrashed()->where('username', $candidate)->exists(); $i++) {
            $candidate = "{$base}{$i}";
        }

        return $candidate;
    }
}
