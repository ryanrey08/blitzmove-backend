<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_permissions_are_grouped_by_module(): void
    {
        $this->actingAsRole('super-admin');

        $modules = $this->getJson($this->api('admin/permissions'))->assertOk()->json('data');
        $this->assertSame('dashboard', $modules[0]['module']);
        $this->assertContains('users', array_column($modules, 'module'));
    }

    public function test_super_admin_creates_edits_and_deletes_a_role(): void
    {
        $this->actingAsRole('super-admin');

        $id = $this->postJson($this->api('admin/roles'), ['name' => 'Billing Clerk', 'description' => 'Orders only', 'permissions' => ['orders.view', 'orders.update']])
            ->assertCreated()->assertJsonPath('data.slug', 'billing-clerk')->assertJsonPath('data.permissions_count', 2)->json('data.id');

        $this->postJson($this->api('admin/roles'), ['name' => 'Billing Clerk'])->assertStatus(422)->assertJsonValidationErrors(['name', 'slug']);

        $this->putJson($this->api("admin/roles/{$id}/permissions"), ['permissions' => ['orders.view']])->assertOk()->assertJsonPath('data.permissions', ['orders.view']);
        $this->getJson($this->api('admin/roles?search=billing'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.users_count', 0);

        $user = User::factory()->create(['role_id' => $id]);
        $this->deleteJson($this->api("admin/roles/{$id}"))->assertStatus(422)->assertJsonValidationErrors('reassign_role_id');
        $viewer = Role::where('slug', 'viewer')->value('id');
        $this->deleteJson($this->api("admin/roles/{$id}"), ['reassign_role_id' => $viewer])->assertOk();
        $this->assertSame($viewer, $user->fresh()->role_id);
    }

    public function test_system_roles_cannot_be_deleted_and_super_admin_is_fixed(): void
    {
        $this->actingAsRole('super-admin');
        $super = Role::where('slug', 'super-admin')->first();

        $this->deleteJson($this->api('admin/roles/'.Role::where('slug', 'viewer')->value('id')))->assertStatus(422);
        $this->putJson($this->api("admin/roles/{$super->id}/permissions"), ['permissions' => []])->assertStatus(422);
        $this->putJson($this->api("admin/roles/{$super->id}"), ['is_active' => false])->assertStatus(422);
    }

    public function test_permission_changes_apply_on_the_next_request(): void
    {
        $role = Role::where('slug', 'viewer')->first();
        $this->actingAsRole('viewer');
        $this->getJson($this->api('orders'))->assertOk();

        $role->permissions()->detach(Permission::where('slug', 'orders.view')->value('id'));
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::latest('id')->first(), 'sanctum');
        $this->getJson($this->api('orders'))->assertForbidden();
    }

    public function test_cannot_grant_permissions_you_do_not_hold(): void
    {
        $this->actingAsRole('super-admin');
        $role = Role::create(['name' => 'Helpers', 'slug' => 'helpers']);

        // An Administrator-like actor without roles.assign_permissions is refused outright.
        $actor = $this->actingAsRole('administrator');
        $this->putJson($this->api("admin/roles/{$role->id}/permissions"), ['permissions' => ['orders.view']])->assertForbidden();

        // Given assign_permissions, they still cannot hand out tracking.manage, which they lack.
        Role::where('slug', 'administrator')->first()->permissions()->attach(Permission::where('slug', 'roles.assign_permissions')->value('id'));
        $actor->flushPermissionCache(); // a new request would load the role afresh
        $this->putJson($this->api("admin/roles/{$role->id}/permissions"), ['permissions' => ['tracking.manage']])->assertForbidden();
        $this->putJson($this->api("admin/roles/{$role->id}/permissions"), ['permissions' => ['orders.view']])->assertOk();
    }

    public function test_seeders_are_idempotent_and_keep_edited_permissions(): void
    {
        $dispatcher = Role::where('slug', 'dispatcher')->first();
        $dispatcher->permissions()->sync([Permission::where('slug', 'dashboard.view')->value('id')]);

        (new RbacSeeder)->run();
        (new RbacSeeder)->run();

        $this->assertSame(8, Role::count());
        $this->assertSame(['dashboard.view'], $dispatcher->fresh('permissions')->permissionSlugs());
    }
}
