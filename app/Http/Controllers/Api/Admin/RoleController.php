<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ListsResources;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    use ListsResources;

    public function __construct(private AccessControl $access) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Role::query()->withCount(['users', 'permissions']);
        $this->applySearch($query, $request, ['name', 'slug', 'description']);
        if (in_array($request->query('status'), ['active', 'inactive'], true)) {
            $query->where('is_active', $request->query('status') === 'active');
        }
        $this->applySort($query, $request, ['id', 'name', 'users_count', 'permissions_count', 'created_at'], 'id');

        return RoleResource::collection($query->paginate($this->perPage($request, 25))->withQueryString());
    }

    public function show(Role $role): RoleResource
    {
        $role->load('permissions')->loadCount(['users', 'permissions']);

        return RoleResource::make($role)->additional([
            'users' => AdminUserResource::collection($role->users()->with('role')->orderBy('name')->limit(100)->get()),
        ]);
    }

    public function store(RoleRequest $request): JsonResponse
    {
        $actor = $request->user();
        $slugs = $request->input('permissions', []);
        if ($slugs) {
            $this->ensureCan($actor, 'roles.assign_permissions');
            $this->access->ensureCanGrantPermissions($actor, $slugs);
        }

        $role = DB::transaction(function () use ($request, $slugs) {
            $role = Role::create([
                ...$request->safe()->only(['name', 'slug', 'description']),
                'is_active' => $request->boolean('is_active', true),
                'is_system' => false,
            ]);
            $role->permissions()->sync($this->access->permissionIds($slugs));

            return $role;
        });

        return RoleResource::make($role->load('permissions')->loadCount(['users', 'permissions']))
            ->additional(['message' => "Role {$role->name} created."])
            ->response()->setStatusCode(201);
    }

    public function update(RoleRequest $request, Role $role): RoleResource
    {
        $actor = $request->user();
        $this->access->ensureCanEditRole($actor, $role);
        $data = $request->safe()->only(['name', 'slug', 'description', 'is_active']);

        if ($role->is_system && isset($data['slug']) && $data['slug'] !== $role->slug) {
            throw ValidationException::withMessages(['slug' => 'The identifier of a system role cannot be changed.']);
        }
        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            if ($role->isSuperAdmin()) {
                throw ValidationException::withMessages(['is_active' => 'The Super Admin role cannot be deactivated.']);
            }
            if ($actor->role_id === $role->id) {
                throw ValidationException::withMessages(['is_active' => 'You cannot deactivate your own role.']);
            }
        }

        DB::transaction(function () use ($role, $data, $request, $actor) {
            $role->update($data);
            if ($request->has('permissions')) {
                $this->syncPermissions($actor, $role, $request->input('permissions', []));
            }
        });

        return RoleResource::make($role->fresh('permissions')->loadCount(['users', 'permissions']))
            ->additional(['message' => "Role {$role->name} updated."]);
    }

    /**
     * System roles are never deleted. A role with users can be deleted only by
     * moving them to another role in the same request (reassign_role_id).
     */
    public function destroy(Request $request, Role $role): JsonResponse
    {
        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => 'System roles cannot be deleted.']);
        }
        $actor = $request->user();
        $this->access->ensureCanEditRole($actor, $role);
        $count = $role->users()->count();

        DB::transaction(function () use ($request, $role, $count, $actor) {
            if ($count) {
                $request->validate([
                    'reassign_role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('is_active', true), Rule::notIn([$role->id])],
                ], ['reassign_role_id.required' => "{$count} user(s) still have this role. Choose a role to move them to first."]);
                $this->ensureCan($actor, 'users.assign_roles');
                if ($role->users()->whereKey($actor->id)->exists()) {
                    throw ValidationException::withMessages(['role' => 'You cannot delete your own role.']);
                }
                $target = Role::findOrFail($request->integer('reassign_role_id'));
                $this->access->ensureCanAssignRole($actor, $target);
                User::withTrashed()->where('role_id', $role->id)->update(['role_id' => $target->id]);
            } else {
                User::onlyTrashed()->where('role_id', $role->id)->update(['role_id' => null]);
            }
            $role->delete();
        });

        return response()->json(['message' => "Role {$role->name} deleted."]);
    }

    public function permissions(Role $role): JsonResponse
    {
        return response()->json(['data' => $role->load('permissions')->permissionSlugs()]);
    }

    public function updatePermissions(Request $request, Role $role): RoleResource
    {
        $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'slug')],
        ]);
        $this->access->ensureCanEditRole($request->user(), $role);
        DB::transaction(fn () => $this->syncPermissions($request->user(), $role, $request->input('permissions', [])));

        return RoleResource::make($role->fresh('permissions')->loadCount(['users', 'permissions']))
            ->additional(['message' => "Permissions for {$role->name} saved."]);
    }

    /** Changes take effect on each user's next request (permissions are read per request). */
    private function syncPermissions(User $actor, Role $role, array $slugs): void
    {
        $this->ensureCan($actor, 'roles.assign_permissions');
        if ($role->isSuperAdmin()) {
            throw ValidationException::withMessages(['permissions' => 'The Super Admin role always has every permission.']);
        }
        if (! $actor->isSuperAdmin() && $actor->role_id === $role->id) {
            throw ValidationException::withMessages(['permissions' => 'You cannot change the permissions of your own role.']);
        }
        // Only permissions being added need to be held by the actor; removing is always allowed.
        $current = $role->permissions()->pluck('slug')->all();
        $this->access->ensureCanGrantPermissions($actor, array_values(array_diff($slugs, $current)));
        $role->permissions()->sync($this->access->permissionIds($slugs));
    }

    private function ensureCan(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new AuthorizationException('You do not have permission to change role permissions.');
        }
    }
}
