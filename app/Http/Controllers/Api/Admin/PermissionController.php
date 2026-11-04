<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    /** Every permission, grouped by module in display order (drives the permission matrix). */
    public function index(): JsonResponse
    {
        $modules = Permission::orderBy('sort_order')->get()->groupBy('module')->map(fn ($group, $module) => [
            'module' => $module,
            'label' => $group->first()->module_label,
            'permissions' => $group->map->only(['id', 'slug', 'name', 'action', 'description'])->values(),
        ])->values();

        return response()->json(['data' => $modules]);
    }
}
