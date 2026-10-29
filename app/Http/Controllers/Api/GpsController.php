<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVehicleLocationRequest;
use App\Http\Resources\VehicleLocationResource;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use App\Services\Routing\RoutingProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Exposes positions reported by a telematics integration. When no device has
 * reported, the API says so instead of producing positions.
 */
class GpsController extends Controller
{
    public function index(): JsonResponse
    {
        $vehicles = Vehicle::with(['latestLocation', 'assignedDriver', 'activeTrips.driver'])->orderBy('plate_number')->get();

        return response()->json([
            'data' => VehicleResource::collection($vehicles),
            'meta' => [
                'map_provider_configured' => filled(config('services.maps.provider')),
                'has_gps_data' => VehicleLocation::exists(),
                // max() returns the raw UTC column value; send ISO 8601 so clients don't read it as local time.
                'last_reported_at' => ($last = VehicleLocation::max('recorded_at')) ? Carbon::parse($last)->toIso8601String() : null,
            ],
        ]);
    }

    public function history(Request $request, Vehicle $vehicle): AnonymousResourceCollection
    {
        return VehicleLocationResource::collection(
            $vehicle->locations()->latest('recorded_at')->limit(min(500, max(1, (int) $request->query('limit', 100))))->get()
        );
    }

    /** Recent positions for one vehicle plus a map of its track (when a map provider is configured). */
    public function track(Request $request, Vehicle $vehicle, RoutingProvider $routing): JsonResponse
    {
        $locations = $vehicle->locations()->latest('recorded_at')->limit(min(500, max(1, (int) $request->query('limit', 200))))->get();
        $points = $locations->reverse()->map(fn (VehicleLocation $l) => ['lat' => $l->latitude, 'lng' => $l->longitude])->values()->all();

        return response()->json([
            'data' => [
                'vehicle' => VehicleResource::make($vehicle->load(['latestLocation', 'assignedDriver', 'activeTrips.driver'])),
                'locations' => VehicleLocationResource::collection($locations),
                'image' => $routing->isConfigured() ? $routing->trackImage($points) : null,
            ],
            'meta' => ['map_provider_configured' => $routing->isConfigured()],
        ]);
    }

    public function store(StoreVehicleLocationRequest $request): JsonResponse
    {
        $location = VehicleLocation::create($request->validated());

        return VehicleLocationResource::make($location)->response()->setStatusCode(201);
    }
}
