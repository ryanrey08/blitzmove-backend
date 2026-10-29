<?php

namespace App\Http\Controllers\Api;

use App\Enums\TripStatus;
use App\Enums\VehicleDocumentType;
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
use Illuminate\Support\Facades\DB;

class VehicleController extends Controller
{
    use ListsResources;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Vehicle::query()->with(['assignedDriver', 'activeTrips', 'photos'])->withCount('documents');

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
        $vehicle = DB::transaction(function () use ($request) {
            $vehicle = Vehicle::create(collect($request->validated())->except(['registration_expiry', 'insurance_expiry'])->all());
            $this->syncExpiryDocuments($vehicle, $request);

            return $vehicle;
        });

        return VehicleResource::make($vehicle->load(['assignedDriver', 'registration', 'insurance', 'photos']))
            ->additional(['message' => "Vehicle {$vehicle->plate_number} added."])
            ->response()->setStatusCode(201);
    }

    public function show(Vehicle $vehicle): VehicleResource
    {
        return VehicleResource::make($vehicle->load([
            'assignedDriver',
            'activeTrips',
            'latestLocation',
            'registration',
            'insurance',
            'photos',
            'trips' => fn ($q) => $q->with('driver')->latest('departure_at')->limit(10),
            'fuelRecords' => fn ($q) => $q->latest('fueled_at')->limit(10),
        ])->loadCount(['documents', 'trips']));
    }

    public function update(VehicleRequest $request, Vehicle $vehicle): VehicleResource
    {
        DB::transaction(function () use ($request, $vehicle) {
            $vehicle->update(collect($request->validated())->except(['registration_expiry', 'insurance_expiry'])->all());
            $this->syncExpiryDocuments($vehicle, $request);
        });

        return VehicleResource::make($vehicle->fresh(['assignedDriver', 'registration', 'insurance', 'photos']))->additional(['message' => 'Vehicle updated.']);
    }

    /**
     * Registration and insurance expiry entered on the vehicle form are kept as the vehicle's
     * documents (the current one of each type is updated, or one is created), so they also show
     * under Fleet > Documents with expiry alerts.
     */
    private function syncExpiryDocuments(Vehicle $vehicle, VehicleRequest $request): void
    {
        foreach (['registration' => VehicleDocumentType::Registration, 'insurance' => VehicleDocumentType::Insurance] as $field => $type) {
            $date = $request->validated("{$field}_expiry");
            if (! $date) {
                continue;
            }
            $current = $vehicle->documents()->where('document_type', $type)->orderByDesc('expires_at')->orderByDesc('id')->first();
            $current
                ? $current->update(['expires_at' => $date])
                : $vehicle->documents()->create(['document_type' => $type, 'title' => $type->label(), 'expires_at' => $date, 'uploaded_by' => $request->user()->id]);
        }
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
