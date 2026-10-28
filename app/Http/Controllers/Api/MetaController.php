<?php

namespace App\Http\Controllers\Api;

use App\Enums;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Status and type vocabularies, so the UI always matches the API. */
class MetaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'order_statuses' => Enums\OrderStatus::options(),
            'trip_statuses' => Enums\TripStatus::options(),
            'vehicle_statuses' => Enums\VehicleStatus::options(),
            'driver_statuses' => Enums\DriverStatus::options(),
            'stop_types' => Enums\StopType::options(),
            'stop_statuses' => Enums\StopStatus::options(),
            'vehicle_types' => Enums\VehicleType::options(),
            'license_types' => Enums\LicenseType::options(),
            'fuel_types' => Enums\FuelType::options(),
            'vehicle_document_types' => Enums\VehicleDocumentType::options(),
            'driver_document_types' => Enums\DriverDocumentType::options(),
            'user_roles' => Enums\UserRole::options(),
        ]]);
    }
}
