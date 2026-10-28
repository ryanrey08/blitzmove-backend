<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVehicleLocationRequest;
use App\Http\Resources\VehicleLocationResource;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Exposes positions reported by a telematics integration. When no device has
 * reported, the API says so instead of producing positions.
 */
class GpsController extends Controller
{
    public function index(): JsonResponse
    {
        $vehicles = Vehicle::with(['latestLocation', 'assignedDriver', 'activeTrips'])->orderBy('plate_number')->get();

        return response()->json([
            'data' => VehicleResource::collection($vehicles),
            'meta' => [
                'map_provider_configured' => filled(config('services.maps.provider')),
                'has_gps_data' => VehicleLocation::exists(),
                'last_reported_at' => VehicleLocation::max('recorded_at'),
            ],
        ]);
    }

    public function history(Request $request, Vehicle $vehicle): AnonymousResourceCollection
    {
        return VehicleLocationResource::collection(
            $vehicle->locations()->latest('recorded_at')->limit(min(500, max(1, (int) $request->query('limit', 100))))->get()
        );
    }

    public function store(StoreVehicleLocationRequest $request): JsonResponse
    {
        $location = VehicleLocation::create($request->validated());

        return VehicleLocationResource::make($location)->response()->setStatusCode(201);
    }
}
