<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\FuelRecordController;
use App\Http\Controllers\Api\GpsController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\MonitoringController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RoutingController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Api\TripStopController;
use App\Http\Controllers\Api\VehicleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public authentication endpoints
    Route::prefix('auth')->middleware('throttle:auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::put('auth/profile', [ProfileController::class, 'update']);
        Route::put('auth/password', [ProfileController::class, 'updatePassword']);

        // Read access for every signed-in role
        Route::get('meta/options', MetaController::class);
        Route::get('search', SearchController::class);
        Route::get('dashboard', DashboardController::class);
        Route::get('fleet/monitoring', MonitoringController::class);

        Route::get('orders/summary', [OrderController::class, 'summary']);
        Route::get('orders/filter-options', [OrderController::class, 'filterOptions']);
        Route::apiResource('orders', OrderController::class)->only(['index', 'show']);

        Route::get('vehicles/summary', [VehicleController::class, 'summary']);
        Route::apiResource('vehicles', VehicleController::class)->only(['index', 'show']);

        Route::get('drivers/summary', [DriverController::class, 'summary']);
        Route::apiResource('drivers', DriverController::class)->only(['index', 'show']);

        Route::get('trips/summary', [TripController::class, 'summary']);
        Route::get('trips/availability', [TripController::class, 'availability']);
        Route::get('trips/next-number', [TripController::class, 'nextNumber']);
        Route::apiResource('trips', TripController::class)->only(['index', 'show']);
        Route::get('trips/{trip}/stops', [TripStopController::class, 'index']);
        Route::get('stops', [TripStopController::class, 'board']);

        Route::get('fuel-records/summary', [FuelRecordController::class, 'summary']);
        Route::apiResource('fuel-records', FuelRecordController::class)->only(['index', 'show']);

        Route::get('documents', [DocumentController::class, 'index']);
        Route::get('documents/{ownerType}/{id}', [DocumentController::class, 'show'])->whereIn('ownerType', ['vehicle', 'driver'])->whereNumber('id');
        Route::get('documents/{ownerType}/{id}/download', [DocumentController::class, 'download'])->whereIn('ownerType', ['vehicle', 'driver'])->whereNumber('id');

        Route::get('routing/status', [RoutingController::class, 'status']);
        Route::post('routing/preview', [RoutingController::class, 'preview'])->middleware('throttle:60,1');
        Route::get('gps/vehicles', [GpsController::class, 'index']);
        Route::get('gps/vehicles/{vehicle}/history', [GpsController::class, 'history']);

        // Day-to-day operations: admin, manager, dispatcher
        Route::middleware('can:manage-operations')->group(function () {
            Route::apiResource('orders', OrderController::class)->only(['store', 'update', 'destroy']);
            Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus']);

            Route::apiResource('trips', TripController::class)->only(['store', 'update', 'destroy']);
            Route::post('trips/{trip}/reassign', [TripController::class, 'reassign']);
            Route::patch('trips/{trip}/status', [TripController::class, 'updateStatus']);
            Route::post('trips/{trip}/route/optimize', [RoutingController::class, 'optimize']);

            Route::scopeBindings()->group(function () {
                Route::post('trips/{trip}/stops', [TripStopController::class, 'store']);
                Route::put('trips/{trip}/stops/reorder', [TripStopController::class, 'reorder']);
                Route::match(['put', 'patch'], 'trips/{trip}/stops/{stop}', [TripStopController::class, 'update']);
                Route::delete('trips/{trip}/stops/{stop}', [TripStopController::class, 'destroy']);
            });

            Route::apiResource('fuel-records', FuelRecordController::class)->only(['store', 'update', 'destroy']);

            Route::post('documents', [DocumentController::class, 'store']);
            Route::match(['put', 'patch'], 'documents/{ownerType}/{id}', [DocumentController::class, 'update'])->whereIn('ownerType', ['vehicle', 'driver'])->whereNumber('id');
            Route::delete('documents/{ownerType}/{id}', [DocumentController::class, 'destroy'])->whereIn('ownerType', ['vehicle', 'driver'])->whereNumber('id');
        });

        // Fleet master data: admin, manager
        Route::middleware('can:manage-fleet')->group(function () {
            Route::apiResource('vehicles', VehicleController::class)->only(['store', 'update', 'destroy']);
            Route::apiResource('drivers', DriverController::class)->only(['store', 'update', 'destroy']);
            Route::post('gps/locations', [GpsController::class, 'store']);
        });
    });
});
