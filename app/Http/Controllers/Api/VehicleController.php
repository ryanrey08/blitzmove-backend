<?php

namespace App\Http\Controllers\Api;

use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ListsResources;
use App\Http\Controllers\Controller;
use App\Http\Requests\VehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VehicleController extends Controller
{
    use ListsResources;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Vehicle::query()->with(['assignedDriver', 'activeTrips'])->withCount('documents');

        $this->applySearch($query, $request, ['plate_number', 'make', 'model', 'vin', 'assignedDriver.full_name']);
        $this->applyFilters($query, $request, ['status', 'vehicle_type', 'fuel_type']);
        $this->applySort($query, $request, ['plate_number', 'make', 'year', 'capacity_kg', 'status', 'created_at'], 'plate_number');

        return VehicleResource::collection($query->paginate($this->perPage($request))->withQueryString());
    }

    public function summary(DashboardService $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->distribution(Vehicle::query(), VehicleStatus::class)]);
    }

    public function store(VehicleRequest $request): JsonResponse
    {
        $vehicle = Vehicle::create($request->validated());

        return VehicleResource::make($vehicle->load('assignedDriver'))
            ->additional(['message' => "Vehicle {$vehicle->plate_number} added."])
            ->response()->setStatusCode(201);
    }

    public function show(Vehicle $vehicle): VehicleResource
    {
        return VehicleResource::make($vehicle->load([
            'assignedDriver',
            'activeTrips',
            'latestLocation',
            'trips' => fn ($q) => $q->with('driver')->latest('departure_at')->limit(10),
            'fuelRecords' => fn ($q) => $q->latest('fueled_at')->limit(10),
        ])->loadCount(['documents', 'trips']));
    }

    public function update(VehicleRequest $request, Vehicle $vehicle): VehicleResource
    {
        $vehicle->update($request->validated());

        return VehicleResource::make($vehicle->fresh('assignedDriver'))->additional(['message' => 'Vehicle updated.']);
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        if ($vehicle->trips()->whereIn('status', TripStatus::blocking())->exists()) {
            throw new BusinessRuleException('This vehicle has scheduled or active trips. Reassign or cancel them first.');
        }

        $vehicle->update(['assigned_driver_id' => null]);
        $vehicle->delete();

        return response()->json(['message' => "Vehicle {$vehicle->plate_number} removed."]);
    }
}
