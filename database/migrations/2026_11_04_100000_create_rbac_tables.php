<?php

use Database\Seeders\RbacSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Role-based access control. Replaces the single `users.role` string (admin,
 * manager, dispatcher, viewer) and `users.is_active` flag with a role_id and a
 * status, keeping every existing account signed in with an equivalent role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('slug', 80)->unique();
            $table->string('description', 500)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 80)->unique();
            $table->string('module', 40)->index();
            $table->string('module_label', 80);
            $table->string('action', 40);
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        // Roles and permissions are reference data the app cannot run without.
        (new RbacSeeder)->run();

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->unique()->after('id');
            $table->string('first_name', 80)->nullable()->after('name');
            $table->string('middle_name', 80)->nullable()->after('first_name');
            $table->string('last_name', 80)->nullable()->after('middle_name');
            $table->foreignId('role_id')->nullable()->after('password')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('active')->after('phone')->index();
            $table->boolean('is_sample')->default(false)->after('status');
            $table->softDeletes();
        });

        Schema::table('drivers', function (Blueprint $table) {
            // Login account of the driver (Driver role sees only this driver's trips).
            $table->foreignId('user_id')->nullable()->unique()->after('id')->constrained()->nullOnDelete();
        });

        $roleIds = DB::table('roles')->pluck('id', 'slug');
        $taken = [];
        foreach (DB::table('users')->orderBy('id')->get() as $user) {
            $parts = preg_split('/\s+/', trim($user->name)) ?: [];
            $first = array_shift($parts) ?: $user->name;
            $username = Str::of(Str::before($user->email, '@'))->lower()->replaceMatches('/[^a-z0-9._-]/', '')->limit(40, '')->value() ?: 'user';
            $candidate = $username;
            for ($i = 2; in_array($candidate, $taken, true); $i++) {
                $candidate = "{$username}{$i}";
            }
            $taken[] = $candidate;

            DB::table('users')->where('id', $user->id)->update([
                'username' => $candidate,
                'first_name' => $first,
                'last_name' => $parts ? implode(' ', $parts) : null,
                'role_id' => $roleIds[config("rbac.legacy_roles.{$user->role}", 'viewer')] ?? $roleIds['viewer'],
                'status' => $user->is_active ? 'active' : 'inactive',
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('viewer')->index();
            $table->boolean('is_active')->default(true);
        });

        $legacy = array_flip(config('rbac.legacy_roles'));
        foreach (DB::table('users')->leftJoin('roles', 'roles.id', '=', 'users.role_id')->get(['users.id', 'users.status', 'roles.slug']) as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'role' => $legacy[$user->slug] ?? ($user->slug === 'fleet-manager' || $user->slug === 'operations-manager' ? 'manager' : 'viewer'),
                'is_active' => $user->status === 'active',
            ]);
        }

        Schema::table('drivers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropIndex(['status']);
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'first_name', 'middle_name', 'last_name', 'status', 'is_sample', 'deleted_at']);
        });
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
