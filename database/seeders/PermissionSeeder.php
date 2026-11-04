<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Creates or refreshes the permission catalogue from config/rbac.php. Safe to re-run. */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $order = 0;
        foreach (config('rbac.modules') as $module => $definition) {
            foreach ($definition['permissions'] as $action => $description) {
                Permission::updateOrCreate(['slug' => "{$module}.{$action}"], [
                    'name' => $definition['label'].': '.Str::headline($action),
                    'module' => $module,
                    'module_label' => $definition['label'],
                    'action' => $action,
                    'description' => $description,
                    'sort_order' => ++$order,
                ]);
            }
        }
    }
}
