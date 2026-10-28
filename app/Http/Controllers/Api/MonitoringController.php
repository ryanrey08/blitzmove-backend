<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverDocumentResource;
use App\Http\Resources\DriverResource;
use App\Http\Resources\TripResource;
use App\Http\Resources\VehicleDocumentResource;
use App\Http\Resources\VehicleResource;
use App\Services\MonitoringService;
use Illuminate\Http\JsonResponse;

class MonitoringController extends Controller
{
    public function __invoke(MonitoringService $monitoring): JsonResponse
    {
        $data = $monitoring->overview();

        return response()->json(['data' => [
            ...$data,
            'active_assignments' => TripResource::collection($data['active_assignments']),
            'upcoming_trips' => TripResource::collection($data['upcoming_trips']),
            'vehicles' => VehicleResource::collection($data['vehicles']),
            'expiring_vehicle_documents' => VehicleDocumentResource::collection($data['expiring_vehicle_documents']),
            'expiring_driver_documents' => DriverDocumentResource::collection($data['expiring_driver_documents']),
            'expiring_licenses' => DriverResource::collection($data['expiring_licenses']),
        ]]);
    }
}
