<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Models\Driver;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'first_name' => 'Maria', 'middle_name' => 'L.', 'last_name' => 'Santos',
            'username' => 'msantos', 'email' => 'Maria@Example.com', 'phone' => '0917 123 4567',
            'password' => 'Str0ng!Pass', 'password_confirmation' => 'Str0ng!Pass',
            'role_id' => Role::where('slug', 'dispatcher')->value('id'), 'status' => 'active',
            ...$overrides,
        ];
    }

    public function test_seeded_roles_and_permissions_exist(): void
    {
        $this->assertSame(8, Role::where('is_system', true)->count());
        $super = Role::where('slug', 'super-admin')->first();
        $this->assertSame(Permission::count(), $super->permissions()->count());
        $this->assertDatabaseMissing('permissions', ['module' => 'sms']);
        $this->assertTrue(Role::where('slug', 'driver')->first()->permissions->pluck('slug')->contains('trips.view_assigned'));
    }

    public function test_me_returns_role_and_permission_slugs(): void
    {
        $this->actingAsRole('dispatcher');

        $this->getJson($this->api('auth/me'))->assertOk()
            ->assertJsonPath('data.role.slug', 'dispatcher')
            ->assertJsonPath('data.roles', ['dispatcher'])
            ->assertJsonFragment(['trips.create'])
            ->assertJsonMissing(['users.view']);
        $this->getJson($this->api('auth/me/permissions'))->assertOk()->assertJsonPath('data.role', 'dispatcher');
    }

    public function test_admin_creates_lists_filters_and_views_users(): void
    {
        $this->actingAsRole('administrator');

        $id = $this->postJson($this->api('admin/users'), $this->payload())->assertCreated()
            ->assertJsonPath('data.name', 'Maria L. Santos')
            ->assertJsonPath('data.email', 'maria@example.com')
            ->assertJsonMissingPath('data.password')
            ->json('data.id');
        $this->assertTrue(\Hash::check('Str0ng!Pass', User::find($id)->password));

        $this->getJson($this->api('admin/users?search=msant'))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->api('admin/users?status=inactive'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->api('admin/users?role_id='.Role::where('slug', 'dispatcher')->value('id')))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->api("admin/users/{$id}"))->assertOk()
            ->assertJsonPath('data.role.slug', 'dispatcher')
            ->assertJsonStructure(['permissions' => [['module', 'label', 'permissions']]]);
    }

    public function test_user_validation(): void
    {
        $this->actingAsRole('administrator');
        User::factory()->create(['email' => 'taken@example.com', 'username' => 'taken']);

        $this->postJson($this->api('admin/users'), $this->payload([
            'email' => 'TAKEN@example.com', 'username' => 'taken', 'phone' => '12345',
            'password' => 'weak', 'password_confirmation' => 'weak',
        ]))->assertStatus(422)->assertJsonValidationErrors(['email', 'username', 'phone', 'password']);

        $this->postJson($this->api('admin/users'), [])->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'last_name', 'username', 'email', 'phone', 'password', 'role_id', 'status']);
    }

    public function test_update_keeps_unchanged_fields_and_ignores_own_email_for_uniqueness(): void
    {
        $this->actingAsRole('administrator');
        $user = User::factory()->role('viewer')->create(['email' => 'v@example.com', 'phone' => '09171234567']);

        $this->putJson($this->api("admin/users/{$user->id}"), ['email' => 'v@example.com', 'job_title' => 'Analyst'])
            ->assertOk()->assertJsonPath('data.job_title', 'Analyst')->assertJsonPath('data.phone', '09171234567');
    }

    public function test_users_without_permission_are_forbidden(): void
    {
        $this->actingAsRole('dispatcher');

        $this->getJson($this->api('admin/users'))->assertForbidden();
        $this->postJson($this->api('admin/users'), $this->payload())->assertForbidden();
        $this->getJson($this->api('admin/roles/1'))->assertForbidden();
        $this->putJson($this->api('admin/roles/2/permissions'), ['permissions' => []])->assertForbidden();
        $this->postJson($this->api('orders'), [])->assertForbidden();
    }

    public function test_administrator_cannot_create_or_manage_super_admins(): void
    {
        $this->actingAsRole('administrator');
        $super = User::factory()->role('super-admin')->create();

        $this->postJson($this->api('admin/users'), $this->payload(['role_id' => Role::where('slug', 'super-admin')->value('id')]))->assertForbidden();
        $this->putJson($this->api("admin/users/{$super->id}"), ['job_title' => 'x'])->assertForbidden();
        $this->patchJson($this->api("admin/users/{$super->id}/status"), ['status' => 'inactive'])->assertForbidden();
        $this->deleteJson($this->api("admin/users/{$super->id}"))->assertForbidden();
        $this->getJson($this->api('admin/users/options'))->assertOk()
            ->assertJsonFragment(['slug' => 'super-admin', 'is_active' => true, 'assignable' => false]);
    }

    public function test_deactivating_revokes_tokens_and_blocks_sign_in(): void
    {
        $admin = $this->actingAsRole('administrator');
        $user = User::factory()->create(['email' => 'ops@example.com', 'password' => 'Secret123']);
        $token = $user->createToken('web')->plainTextToken;

        $this->patchJson($this->api("admin/users/{$user->id}/status"), ['status' => 'suspended'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertSame(0, $user->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson($this->api('auth/me'))->assertUnauthorized();
        $this->postJson($this->api('auth/login'), ['email' => 'ops@example.com', 'password' => 'Secret123'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'This account has been suspended. Contact your administrator.');

        Sanctum::actingAs($admin);
        $this->patchJson($this->api("admin/users/{$admin->id}/status"), ['status' => 'inactive'])->assertStatus(422);
        $this->deleteJson($this->api("admin/users/{$admin->id}"))->assertStatus(422);
    }

    public function test_last_super_admin_cannot_be_disabled_and_own_role_cannot_change(): void
    {
        $super = $this->actingAsRole('super-admin');
        $other = User::factory()->role('super-admin')->create();

        $this->patchJson($this->api("admin/users/{$other->id}/status"), ['status' => 'inactive'])->assertOk();
        $this->putJson($this->api("admin/users/{$super->id}"), ['role_id' => Role::where('slug', 'viewer')->value('id')])->assertStatus(422);
    }

    public function test_reset_password_and_soft_delete(): void
    {
        $this->actingAsRole('administrator');
        $user = User::factory()->create();
        $user->createToken('web');

        $this->postJson($this->api("admin/users/{$user->id}/reset-password"), ['mode' => 'set', 'password' => 'N3w!Password', 'password_confirmation' => 'N3w!Password'])->assertOk();
        $this->assertTrue(\Hash::check('N3w!Password', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());

        $this->deleteJson($this->api("admin/users/{$user->id}"))->assertOk();
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_driver_sees_only_assigned_trips_and_can_only_progress_them(): void
    {
        $user = $this->actingAsRole('driver');
        $driver = Driver::factory()->create();
        $driver->forceFill(['user_id' => $user->id])->save();
        $mine = Trip::factory()->create(['driver_id' => $driver->id, 'status' => TripStatus::Dispatched]);
        $other = Trip::factory()->create();

        $this->getJson($this->api('trips'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->getJson($this->api("trips/{$other->id}"))->assertNotFound();
        $this->getJson($this->api('orders'))->assertForbidden();
        $this->getJson($this->api('dashboard'))->assertForbidden();
        $this->getJson($this->api('admin/users'))->assertForbidden();

        $this->patchJson($this->api("trips/{$other->id}/status"), ['status' => 'in_progress'])->assertForbidden();
        $this->patchJson($this->api("trips/{$mine->id}/status"), ['status' => 'cancelled'])->assertForbidden();
        $this->patchJson($this->api("trips/{$mine->id}/status"), ['status' => 'in_progress'])->assertOk();
    }
}
