<?php

/*
|--------------------------------------------------------------------------
| Role-based access control: permission catalogue and initial roles
|--------------------------------------------------------------------------
|
| Permissions follow "module.action" and cover only modules BlitzMove actually
| has. The seeders read this file; the database is the source of truth at run
| time (roles and their permissions are edited from Role Management).
|
| SMS notifications, system settings and system logs are not modules in this
| application yet, so they have no permissions here.
*/

return [
    'super_admin' => 'super-admin',

    'modules' => [
        'dashboard' => ['label' => 'Dashboard', 'permissions' => [
            'view' => 'View the operations dashboard',
        ]],
        'orders' => ['label' => 'Orders', 'permissions' => [
            'view' => 'View orders and deliveries',
            'create' => 'Create orders',
            'update' => 'Edit orders and change their status',
            'delete' => 'Delete orders',
        ]],
        'trips' => ['label' => 'Transportation', 'permissions' => [
            'view' => 'View all trips, itineraries and route plans',
            'view_assigned' => 'View only trips assigned to the user\'s driver profile',
            'create' => 'Schedule trips',
            'update' => 'Edit trips, stops and routes',
            'delete' => 'Delete trips',
            'update_status' => 'Start, complete or cancel trips (assigned trips only without Dispatch: Update)',
        ]],
        'dispatch' => ['label' => 'Dispatch', 'permissions' => [
            'view' => 'View the dispatch board',
            'update' => 'Dispatch trips and change any trip status',
            'assign' => 'Assign or reassign vehicles and drivers',
        ]],
        'vehicles' => ['label' => 'Vehicles', 'permissions' => [
            'view' => 'View vehicles',
            'create' => 'Add vehicles',
            'update' => 'Edit vehicles and photos',
            'delete' => 'Delete vehicles',
        ]],
        'drivers' => ['label' => 'Drivers', 'permissions' => [
            'view' => 'View drivers',
            'create' => 'Add drivers',
            'update' => 'Edit drivers and photos',
            'delete' => 'Delete drivers',
        ]],
        'fuel' => ['label' => 'Fuel Management', 'permissions' => [
            'view' => 'View fuel transactions',
            'create' => 'Record fuel transactions',
            'update' => 'Edit fuel transactions and receipts',
            'delete' => 'Delete fuel transactions',
        ]],
        'maintenance' => ['label' => 'Maintenance', 'permissions' => [
            'view' => 'View maintenance records and service history',
            'create' => 'Create maintenance records',
            'update' => 'Update maintenance records and their status',
            'delete' => 'Delete maintenance records',
        ]],
        'documents' => ['label' => 'Documents', 'permissions' => [
            'view' => 'View vehicle and driver documents',
            'create' => 'Upload documents',
            'update' => 'Edit documents and their files',
            'delete' => 'Delete documents',
        ]],
        'tracking' => ['label' => 'Tracking', 'permissions' => [
            'view' => 'View GPS and live tracking',
            'monitor' => 'Use the fleet monitoring dashboard',
            'manage' => 'Record vehicle positions manually',
        ]],
        'reports' => ['label' => 'Reports', 'permissions' => [
            'view' => 'View reports and analytics',
            'create' => 'Generate and save reports',
            'export' => 'Export reports (CSV / print)',
            'delete' => 'Delete any saved report (own reports can always be deleted)',
        ]],
        'users' => ['label' => 'User Management', 'permissions' => [
            'view' => 'View users',
            'create' => 'Create users',
            'update' => 'Edit users',
            'delete' => 'Delete users',
            'activate' => 'Activate user accounts',
            'deactivate' => 'Deactivate or suspend user accounts',
            'assign_roles' => 'Change a user\'s role',
            'reset_password' => 'Reset user passwords',
        ]],
        'roles' => ['label' => 'Role Management', 'permissions' => [
            'view' => 'View roles and permissions',
            'create' => 'Create roles',
            'update' => 'Edit roles',
            'delete' => 'Delete roles',
            'assign_permissions' => 'Change a role\'s permissions',
        ]],
    ],

    /*
     * Initial roles. '*' = every permission; 'module.*' = every action in a module.
     * All of these are system roles: they can be edited but not deleted.
     */
    'roles' => [
        'super-admin' => [
            'name' => 'Super Admin',
            'description' => 'Full, unrestricted access to every module and setting.',
            'permissions' => ['*'],
        ],
        'administrator' => [
            'name' => 'Administrator',
            'description' => 'Runs day-to-day administration: users, operations, fleet and reports. Cannot manage Super Admins or role permissions.',
            'permissions' => [
                'dashboard.view',
                'users.view', 'users.create', 'users.update', 'users.delete', 'users.activate', 'users.deactivate', 'users.assign_roles', 'users.reset_password',
                'roles.view',
                'orders.*', 'trips.view', 'trips.create', 'trips.update', 'trips.delete', 'trips.update_status', 'dispatch.*',
                'vehicles.*', 'drivers.*', 'fuel.*', 'maintenance.*', 'documents.*',
                'tracking.view', 'tracking.monitor',
                'reports.*',
            ],
        ],
        'dispatcher' => [
            'name' => 'Dispatcher',
            'description' => 'Schedules and dispatches trips, assigns drivers and vehicles, and monitors deliveries.',
            'permissions' => [
                'dashboard.view',
                'trips.view', 'trips.create', 'trips.update', 'trips.update_status',
                'dispatch.view', 'dispatch.update', 'dispatch.assign',
                'orders.view', 'vehicles.view', 'drivers.view',
                'tracking.view',
            ],
        ],
        'operations-manager' => [
            'name' => 'Operations Manager',
            'description' => 'Oversees orders and deliveries, trip schedules, fleet performance and operational reports.',
            'permissions' => [
                'dashboard.view',
                'orders.view', 'orders.create', 'orders.update',
                'trips.view', 'dispatch.view',
                'vehicles.view', 'drivers.view',
                'tracking.view', 'tracking.monitor',
                'reports.view', 'reports.create', 'reports.export',
            ],
        ],
        'fleet-manager' => [
            'name' => 'Fleet Manager',
            'description' => 'Manages vehicles, drivers, fuel, documents and maintenance, and monitors fleet status.',
            'permissions' => [
                'dashboard.view',
                'vehicles.*', 'drivers.*', 'fuel.*', 'documents.*', 'maintenance.*',
                'tracking.view', 'tracking.monitor',
                'reports.view', 'reports.create', 'reports.export',
            ],
        ],
        'driver' => [
            'name' => 'Driver',
            'description' => 'Sees only the trips assigned to their driver profile and updates their progress.',
            'permissions' => ['trips.view_assigned', 'trips.update_status'],
        ],
        'maintenance-staff' => [
            'name' => 'Maintenance Staff',
            'description' => 'Works maintenance records and views vehicle service history.',
            'permissions' => ['maintenance.view', 'maintenance.update', 'vehicles.view'],
        ],
        'viewer' => [
            'name' => 'Viewer',
            'description' => 'Read-only access to dashboards, operations, fleet, tracking and reports.',
            'permissions' => [
                'dashboard.view', 'orders.view', 'trips.view', 'dispatch.view',
                'vehicles.view', 'drivers.view', 'fuel.view', 'maintenance.view', 'documents.view',
                'tracking.view', 'tracking.monitor', 'reports.view',
            ],
        ],
    ],

    /* Roles the pre-RBAC `users.role` values map to when the migration runs. */
    'legacy_roles' => [
        'admin' => 'super-admin',
        'manager' => 'administrator',
        'dispatcher' => 'dispatcher',
        'viewer' => 'viewer',
    ],

    /* Development sample accounts (DevelopmentUserSeeder). */
    'sample_users' => [
        ['name' => 'Administrator', 'email' => 'admin@blitzmove.local', 'role' => 'administrator'],
        ['name' => 'Dispatcher', 'email' => 'dispatcher@blitzmove.local', 'role' => 'dispatcher'],
        ['name' => 'Operations Manager', 'email' => 'operations@blitzmove.local', 'role' => 'operations-manager'],
        ['name' => 'Fleet Manager', 'email' => 'fleet@blitzmove.local', 'role' => 'fleet-manager'],
        ['name' => 'Driver', 'email' => 'driver@blitzmove.local', 'role' => 'driver'],
        ['name' => 'Maintenance Staff', 'email' => 'maintenance@blitzmove.local', 'role' => 'maintenance-staff'],
        ['name' => 'Viewer', 'email' => 'viewer@blitzmove.local', 'role' => 'viewer'],
    ],
];
