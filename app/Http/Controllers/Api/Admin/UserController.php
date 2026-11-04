<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Concerns\ListsResources;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\RoleSummaryResource;
use App\Models\Driver;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    use ListsResources;

    public function __construct(private AccessControl $access) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::query()->with('role');

        $this->applySearch($query, $request, ['name', 'first_name', 'last_name', 'username', 'email']);
        $this->applyFilters($query, $request, ['status', 'role_id']);
        if ($from = $request->query('created_from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->query('created_to')) {
            $query->where('created_at', '<=', $to.' 23:59:59');
        }
        $this->applySort($query, $request, ['id', 'name', 'username', 'email', 'status', 'last_login_at', 'created_at'], '-created_at');

        return AdminUserResource::collection($query->paginate($this->perPage($request))->withQueryString())
            ->additional(['counts' => User::query()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->map(fn ($n) => (int) $n)]);
    }

    /** Roles the signed-in user may assign, and driver profiles not yet linked to a login. */
    public function options(Request $request): JsonResponse
    {
        $actor = $request->user();
        $roles = Role::with('permissions')->orderBy('id')->get();

        return response()->json(['data' => [
            'roles' => $roles->map(fn (Role $role) => [
                ...RoleSummaryResource::make($role)->resolve(),
                'assignable' => $role->is_active && $this->assignable($actor, $role),
            ])->values(),
            'statuses' => UserStatus::options(),
            'drivers' => Driver::query()
                ->where(fn ($q) => $q->whereNull('user_id')->when($request->integer('user_id'), fn ($q, $id) => $q->orWhere('user_id', $id)))
                ->orderBy('full_name')->get(['id', 'full_name', 'employee_code', 'user_id']),
        ]]);
    }

    public function store(UserRequest $request): JsonResponse
    {
        $actor = $request->user();
        $role = Role::findOrFail($request->integer('role_id'));
        $this->access->ensureCanAssignRole($actor, $role);

        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                ...collect($request->validated())->except(['driver_id', 'password_confirmation'])->all(),
                'password' => $request->input('password'),
            ]);
            $this->linkDriver($user, $request);

            return $user;
        });

        return AdminUserResource::make($user->load(['role', 'driver']))
            ->additional(['message' => "User {$user->name} created."])
            ->response()->setStatusCode(201);
    }

    public function show(User $user): AdminUserResource
    {
        $user->load(['role.permissions', 'driver']);
        $permissions = Permission::whereIn('slug', $user->permissionSlugs())->orderBy('sort_order')->get();

        return AdminUserResource::make($user)->additional(['permissions' => $permissions->groupBy('module_label')->map(
            fn ($group, $label) => ['module' => $group->first()->module, 'label' => $label, 'permissions' => $group->map->only(['slug', 'name', 'description'])->values()],
        )->values()]);
    }

    public function update(UserRequest $request, User $user): AdminUserResource
    {
        $actor = $request->user();
        $this->access->ensureCanManageUser($actor, $user);
        $data = collect($request->validated())->except(['driver_id', 'password'])->all();

        if (isset($data['role_id']) && (int) $data['role_id'] !== $user->role_id) {
            $this->ensureCan($actor, 'users.assign_roles', 'You do not have permission to change user roles.');
            if ($actor->is($user)) {
                throw ValidationException::withMessages(['role_id' => 'You cannot change your own role.']);
            }
            $this->access->ensureCanAssignRole($actor, Role::findOrFail($data['role_id']));
            $this->access->ensureNotLastSuperAdmin($user, 'role_id');
        } else {
            unset($data['role_id']);
        }

        if (isset($data['status']) && $data['status'] !== $user->status->value) {
            $this->checkStatusChange($actor, $user, UserStatus::from($data['status']));
        } else {
            unset($data['status']);
        }

        DB::transaction(function () use ($user, $data, $request) {
            $user->update($data);
            if ($request->has('driver_id')) {
                $this->linkDriver($user, $request);
            }
            if (! $user->canAuthenticate()) {
                $user->tokens()->delete();
            }
        });

        return AdminUserResource::make($user->fresh(['role', 'driver']))->additional(['message' => "User {$user->name} updated."]);
    }

    public function updateStatus(Request $request, User $user): AdminUserResource
    {
        $request->validate(['status' => ['required', Rule::enum(UserStatus::class)]]);
        $status = UserStatus::from($request->input('status'));
        $this->access->ensureCanManageUser($request->user(), $user);
        $this->checkStatusChange($request->user(), $user, $status);

        $user->update(['status' => $status]);
        if (! $status->canAuthenticate()) {
            // Existing sessions end immediately; the user cannot sign in again until reactivated.
            $user->tokens()->delete();
        }

        return AdminUserResource::make($user->fresh(['role', 'driver']))->additional(['message' => "{$user->name} is now {$status->label()}."]);
    }

    /**
     * mode=link emails the user a password reset link; mode=set sets a new password
     * chosen by the administrator. Either way every session of the user is revoked.
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['link', 'set'])],
            'password' => ['required_if:mode,set', 'nullable', 'confirmed', PasswordRule::min(10)->letters()->mixedCase()->numbers()->symbols()],
        ]);
        if ($request->user()->is($user)) {
            throw ValidationException::withMessages(['mode' => 'Change your own password from your profile.']);
        }
        $this->access->ensureCanManageUser($request->user(), $user);

        if ($data['mode'] === 'link') {
            $status = Password::sendResetLink(['email' => $user->email]);
            if ($status !== Password::ResetLinkSent) {
                throw ValidationException::withMessages(['mode' => __($status)]);
            }

            return response()->json(['message' => "A password reset link was sent to {$user->email}."]);
        }

        $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
        $user->tokens()->delete();

        return response()->json(['message' => "Password for {$user->name} was reset. They have been signed out everywhere."]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->is($user)) {
            throw ValidationException::withMessages(['user' => 'You cannot delete your own account.']);
        }
        $this->access->ensureCanManageUser($request->user(), $user);
        $this->access->ensureNotLastSuperAdmin($user, 'user');

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            Driver::where('user_id', $user->id)->update(['user_id' => null]);
            $user->delete();
        });

        return response()->json(['message' => "User {$user->name} deleted."]);
    }

    public function roles(User $user): JsonResponse
    {
        return response()->json(['data' => $user->role ? [RoleSummaryResource::make($user->role)] : []]);
    }

    /** Users hold one role; accepts {role_id} or {roles: [id]} with a single id. */
    public function updateRoles(Request $request, User $user): AdminUserResource
    {
        $request->merge(['role_id' => $request->input('role_id', $request->input('roles.0'))]);
        $request->validate([
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('is_active', true)],
            'roles' => ['sometimes', 'array', 'max:1'],
        ], ['roles.max' => 'A user can have only one role.']);

        $actor = $request->user();
        if ($actor->is($user)) {
            throw ValidationException::withMessages(['role_id' => 'You cannot change your own role.']);
        }
        $this->access->ensureCanManageUser($actor, $user);
        $this->access->ensureCanAssignRole($actor, $role = Role::findOrFail($request->integer('role_id')));
        if ($role->id !== $user->role_id) {
            $this->access->ensureNotLastSuperAdmin($user, 'role_id');
        }
        $user->update(['role_id' => $role->id]);

        return AdminUserResource::make($user->fresh(['role', 'driver']))->additional(['message' => "{$user->name} is now {$role->name}."]);
    }

    private function checkStatusChange(User $actor, User $user, UserStatus $status): void
    {
        $this->ensureCan($actor, $status->canAuthenticate() ? 'users.activate' : 'users.deactivate', 'You do not have permission to change this account\'s status.');
        if (! $status->canAuthenticate()) {
            if ($actor->is($user)) {
                throw ValidationException::withMessages(['status' => 'You cannot deactivate your own account.']);
            }
            $this->access->ensureNotLastSuperAdmin($user);
        }
    }

    private function linkDriver(User $user, Request $request): void
    {
        $driverId = $request->input('driver_id');
        Driver::where('user_id', $user->id)->when($driverId, fn ($q) => $q->where('id', '!=', $driverId))->update(['user_id' => null]);
        if ($driverId) {
            Driver::whereKey($driverId)->update(['user_id' => $user->id]);
        }
    }

    private function assignable(User $actor, Role $role): bool
    {
        try {
            $this->access->ensureCanAssignRole($actor, $role);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    private function ensureCan(User $actor, string $permission, string $message): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new AuthorizationException($message);
        }
    }
}
