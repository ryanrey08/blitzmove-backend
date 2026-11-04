<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /** Roles and permissions are seeded by their migration, so every test database has them. */
    protected function actingAsRole(string $role = 'super-admin'): User
    {
        $user = User::factory()->role($role)->create();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function api(string $path): string
    {
        return '/api/v1/'.ltrim($path, '/');
    }
}
