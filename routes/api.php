<?php

use App\Http\Controllers\Api\Admin\PermissionController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\FuelRecordController;
use App\Http\Controllers\Api\GpsController;
use App\Http\Controllers\Api\MaintenanceRecordController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\MonitoringController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoutingController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Api\TripStopController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\VehiclePhotoController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public authentication endpoints
    Route::prefix('auth')->middleware('throttle:auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::get('auth/me/permissions', [AuthController::class, 'permissions']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::put('auth/profile', [ProfileController::class, 'update']);
        Route::put('auth/password', [ProfileController::class, 'updatePassword']);

        // Shared lookups for any signed-in user; search results are filtered by permission.
        Route::get('meta/options', MetaController::class);
        Route::get('search', SearchController::class);
        Route::get('routing/status', [RoutingController::class, 'status']);

        Route::get('dashboard', DashboardController::class)->middleware('permission:dashboard.view');
        Route::get('fleet/monitoring', MonitoringController::class)->middleware('permission:tracking.monitor');

        // Orders
        Route::middleware('permission:orders.view')->group(function () {
            Route::get('orders/summary', [OrderController::class, 'summary']);
            Route::get('orders/filter-options', [OrderController::class, 'filterOptions']);
            Route::apiResource('orders', OrderController::class)->only(['index', 'show']);
        });
        Route::post('orders', [OrderController::class, 'store'])->middleware('permission:orders.create');
        Route::match(['put', 'patch'], 'orders/{order}', [OrderController::class, 'update'])->middleware('permission:orders.update');
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->middleware('permission:orders.update');
        Route::delete('orders/{order}', [OrderController::class, 'destroy'])->middleware('permission:orders.delete');

        // Vehicles
        Route::middleware('permission:vehicles.view')->group(function () {
            Route::get('vehicles/summary', [VehicleController::class, 'summary']);
            Route::apiResource('vehicles', VehicleController::class)->only(['index', 'show']);
            Route::get('vehicles/{vehicle}/photos/{photo}', [VehiclePhotoController::class, 'show'])->scopeBindings();
        });
        Route::post('vehicles', [VehicleController::class, 'store'])->middleware('permission:vehicles.create');
        Route::match(['put', 'patch'], 'vehicles/{vehicle}', [VehicleController::class, 'update'])->middleware('permission:vehicles.update');
        Route::delete('vehicles/{vehicle}', [VehicleController::class, 'destroy'])->middleware('permission:vehicles.delete');
        Route::scopeBindings()->group(function () {
            Route::post('vehicles/{vehicle}/photos', [VehiclePhotoController::class, 'store'])->middleware('permission:vehicles.create|vehicles.update');
            Route::patch('vehicles/{vehicle}/photos/{photo}/cover', [VehiclePhotoController::class, 'cover'])->middleware('permission:vehicles.update');
            Route::delete('vehicles/{vehicle}/photos/{photo}', [VehiclePhotoController::class, 'destroy'])->middleware('permission:vehicles.update');
        });

        // Drivers
        Route::middleware('permission:drivers.view')->group(function () {
            Route::get('drivers/summary', [DriverController::class, 'summary']);
            Route::apiResource('drivers', DriverController::class)->only(['index', 'show']);
            Route::get('drivers/{driver}/photo', [DriverController::class, 'photo']);
        });
        Route::post('drivers', [DriverController::class, 'store'])->middleware('permission:drivers.create');
        Route::match(['put', 'patch'], 'drivers/{driver}', [DriverController::class, 'update'])->middleware('permission:drivers.update');
        Route::delete('drivers/{driver}', [DriverController::class, 'destroy'])->middleware('permission:drivers.delete');
        Route::post('drivers/{driver}/photo', [DriverController::class, 'storePhoto'])->middleware('permission:drivers.create|drivers.update');
        Route::delete('drivers/{driver}/photo', [DriverController::class, 'destroyPhoto'])->middleware('permission:drivers.update');

        Route::get('trips/availability', [TripController::class, 'availability'])->middleware('permission:trips.create|trips.update|dispatch.update|dispatch.assign');
        Route::get('trips/next-number', [TripController::class, 'nextNumber'])->middleware('permission:trips.create');
        // Trips. trips.view_assigned (drivers) sees only their own trips; controllers scope it.
        Route::middleware('permission:trips.view|dispatch.view|trips.view_assigned')->group(function () {
            Route::get('trips/summary', [TripController::class, 'summary']);
            Route::apiResource('trips', TripController::class)->only(['index', 'show']);
            Route::get('trips/{trip}/stops', [TripStopController::class, 'index']);
            Route::get('stops', [TripStopController::class, 'board']);
        });
        Route::post('trips', [TripController::class, 'store'])->middleware('permission:trips.create');
        Route::match(['put', 'patch'], 'trips/{trip}', [TripController::class, 'update'])->middleware('permission:trips.update');
        Route::delete('trips/{trip}', [TripController::class, 'destroy'])->middleware('permission:trips.delete');
        Route::post('trips/{trip}/reassign', [TripController::class, 'reassign'])->middleware('permission:dispatch.assign');
        Route::patch('trips/{trip}/status', [TripController::class, 'updateStatus'])->middleware('permission:dispatch.update|trips.update_status');
        Route::post('trips/{trip}/route/optimize', [RoutingController::class, 'optimize'])->middleware('permission:trips.update');
        Route::scopeBindings()->middleware('permission:trips.update')->group(function () {
            Route::post('trips/{trip}/stops', [TripStopController::class, 'store']);
            Route::put('trips/{trip}/stops/reorder', [TripStopController::class, 'reorder']);
            Route::match(['put', 'patch'], 'trips/{trip}/stops/{stop}', [TripStopController::class, 'update']);
            Route::delete('trips/{trip}/stops/{stop}', [TripStopController::class, 'destroy']);
        });
        Route::middleware('permission:trips.view|trips.create|trips.update')->group(function () {
            Route::post('routing/preview', [RoutingController::class, 'preview'])->middleware('throttle:60,1');
            Route::post('routing/plan', [RoutingController::class, 'plan'])->middleware('throttle:30,1');
        });

        // Fuel
        Route::middleware('permission:fuel.view')->group(function () {
            Route::get('fuel-records/summary', [FuelRecordController::class, 'summary']);
            Route::get('fuel-records/stations', [FuelRecordController::class, 'stations']);
            Route::apiResource('fuel-records', FuelRecordController::class)->only(['index', 'show']);
            Route::get('fuel-records/{fuel_record}/receipt', [FuelRecordController::class, 'receipt']);
        });
        Route::post('fuel-records', [FuelRecordController::class, 'store'])->middleware('permission:fuel.create');
        Route::match(['put', 'patch'], 'fuel-records/{fuel_record}', [FuelRecordController::class, 'update'])->middleware('permission:fuel.update');
        Route::delete('fuel-records/{fuel_record}', [FuelRecordController::class, 'destroy'])->middleware('permission:fuel.delete');
        Route::post('fuel-records/{fuel_record}/receipt', [FuelRecordController::class, 'storeReceipt'])->middleware('permission:fuel.create|fuel.update');
        Route::delete('fuel-records/{fuel_record}/receipt', [FuelRecordController::class, 'destroyReceipt'])->middleware('permission:fuel.update');

        // Maintenance
        Route::middleware('permission:maintenance.view')->group(function () {
            Route::get('maintenance-records/summary', [MaintenanceRecordController::class, 'summary']);
            Route::get('maintenance-records/next-number', [MaintenanceRecordController::class, 'nextNumber']);
            Route::get('maintenance-records/options', [MaintenanceRecordController::class, 'options']);
            Route::apiResource('maintenance-records', MaintenanceRecordController::class)->only(['index', 'show']);
            Route::get('maintenance-records/{maintenance_record}/files/{file}', [MaintenanceRecordController::class, 'showFile'])->whereNumber('file');
        });
        Route::post('maintenance-records', [MaintenanceRecordController::class, 'store'])->middleware('permission:maintenance.create');
        Route::match(['put', 'patch'], 'maintenance-records/{maintenance_record}', [MaintenanceRecordController::class, 'update'])->middleware('permission:maintenance.update');
        Route::delete('maintenance-records/{maintenance_record}', [MaintenanceRecordController::class, 'destroy'])->middleware('permission:maintenance.delete');
        Route::post('maintenance-records/{maintenance_record}/files', [MaintenanceRecordController::class, 'storeFile'])->middleware('permission:maintenance.create|maintenance.update');
        Route::delete('maintenance-records/{maintenance_record}/files/{file}', [MaintenanceRecordController::class, 'destroyFile'])->whereNumber('file')->middleware('permission:maintenance.update');

        // Documents
        Route::middleware('permission:documents.view')->group(function () {
            Route::get('documents', [DocumentController::class, 'index']);
            Route::get('documents/{ownerType}/{id}', [DocumentController::class, 'show'])->whereIn('ownerType', ['vehicle', 'driver'])->whereNumber('id');
            Route::get('documents/{ownerType}/{id}/download', [DocumentController::class, 'download'])->whereIn('ownerType', ['vehicle', 'driver'])->whereNumber('id');
            Route::get('documents/{ownerType}/{id}/files/{fileId}', [DocumentController::class, 'showFile'])->whereIn('ownerType', ['vehicle', 'driver'])->whereNumber(['id', 'fileId']);
        });
        Route::post('documents', [DocumentController::class, 'store'])->middleware('permission:documents.create');
        Route::match(['put', 'patch'], 'documents/{ownerType}/{id}', [DocumentController::class, 'update'])->whereIn('ownerType', ['vehicle', 'driver'])->whereNumber('id')->middleware('permission:documents.update');
        Route::delete('documents/{ownerType}/{id}', [DocumentController::class, 'destroy'])->whereIn('ownerType', ['vehicle', 'driver'])->whereNumber('id')->middleware('permission:documents.delete');
        Route::post('documents/{ownerType}/{id}/files', [DocumentController::class, 'storeFile'])->whereIn('ownerType', ['vehicle', 'driver'])->whereNumber('id')->middleware('permission:documents.create|documents.update');
        Route::delete('documents/{ownerType}/{id}/files/{fileId}', [DocumentController::class, 'destroyFile'])->whereIn('ownerType', ['vehicle', 'driver'])->whereNumber(['id', 'fileId'])->middleware('permission:documents.update');

        // Reports (deleting your own saved report needs no extra permission; the controller checks)
        Route::middleware('permission:reports.view')->group(function () {
            Route::get('reports/overview', [ReportController::class, 'overview']);
            Route::get('reports/fuel', [ReportController::class, 'fuel']);
            Route::get('reports/trips', [ReportController::class, 'trips']);
            Route::get('reports/drivers', [ReportController::class, 'drivers']);
            Route::get('reports/maintenance', [ReportController::class, 'maintenance']);
            Route::get('reports/utilization', [ReportController::class, 'utilization']);
            Route::get('reports/deliveries', [ReportController::class, 'deliveries']);
            Route::apiResource('reports', ReportController::class)->only(['index', 'show', 'destroy']);
        });
        Route::post('reports', [ReportController::class, 'store'])->middleware('permission:reports.create');

        // GPS / tracking
        Route::middleware('permission:tracking.view|tracking.monitor')->group(function () {
            Route::get('gps/vehicles', [GpsController::class, 'index']);
            Route::post('gps/map', [GpsController::class, 'map'])->middleware('throttle:60,1');
            Route::post('gps/viewport', [GpsController::class, 'viewport'])->middleware('throttle:60,1');
            Route::get('gps/fleet-trend', [GpsController::class, 'fleetTrend']);
            Route::get('gps/events', [GpsController::class, 'events']);
            Route::get('gps/vehicles/{vehicle}/history', [GpsController::class, 'history']);
            Route::get('gps/vehicles/{vehicle}/track', [GpsController::class, 'track']);
        });
        Route::post('gps/locations', [GpsController::class, 'store'])->middleware('permission:tracking.manage');

        // User & role management
        Route::prefix('admin')->group(function () {
            Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view');
            Route::post('users', [UserController::class, 'store'])->middleware('permission:users.create');
            Route::get('users/options', [UserController::class, 'options'])->middleware('permission:users.view|users.create|users.update');
            Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:users.view');
            Route::put('users/{user}', [UserController::class, 'update'])->middleware('permission:users.update');
            Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete');
            Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])->middleware('permission:users.activate|users.deactivate');
            Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('permission:users.reset_password');
            Route::get('users/{user}/roles', [UserController::class, 'roles'])->middleware('permission:users.view');
            Route::put('users/{user}/roles', [UserController::class, 'updateRoles'])->middleware('permission:users.assign_roles');

            Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:roles.view|roles.create|roles.update');
            Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view|users.view|users.create|users.update');
            Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
            Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.view');
            Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');
            Route::get('roles/{role}/permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.view');
            Route::put('roles/{role}/permissions', [RoleController::class, 'updatePermissions'])->middleware('permission:roles.assign_permissions');
        });
    });
});
