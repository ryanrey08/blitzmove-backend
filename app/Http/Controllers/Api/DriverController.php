<?php

namespace App\Http\Controllers\Api;

use App\Enums\DriverStatus;
use App\Enums\TripStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ListsResources;
use App\Http\Controllers\Controller;
use App\Http\Requests\DriverRequest;
use App\Http\Resources\DriverResource;
use App\Models\Driver;
use App\Services\DashboardService;
use App\Services\NumberGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class DriverController extends Controller
{
    use ListsResources;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Driver::query()->with('assignedVehicle');

        $this->applySearch($query, $request, ['full_name', 'employee_code', 'license_number', 'phone', 'email']);
        $this->applyFilters($query, $request, ['status', 'license_type']);

        if ($type = $request->query('vehicle_type')) {
            $query->whereHas('assignedVehicle', fn ($q) => $q->where('vehicle_type', $type));
        }

        $this->applySort($query, $request, ['full_name', 'employee_code', 'license_expiry', 'status', 'created_at'], 'full_name');

        return DriverResource::collection($query->paginate($this->perPage($request))->withQueryString());
    }

    public function summary(DashboardService $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->distribution(Driver::query(), DriverStatus::class)]);
    }

    public function store(DriverRequest $request, NumberGenerator $numbers): JsonResponse
    {
        $driver = DB::transaction(fn () => Driver::create([
            ...$request->validated(),
            'employee_code' => $numbers->next(Driver::class, 'employee_code', 'DRV', yearly: false),
        ]));

        return DriverResource::make($driver)
            ->additional(['message' => "Driver {$driver->full_name} added."])
            ->response()->setStatusCode(201);
    }

    public function show(Driver $driver): DriverResource
    {
        return DriverResource::make($driver->load([
            'assignedVehicle',
            'trips' => fn ($q) => $q->with('vehicle')->latest('departure_at')->limit(10),
        ])->loadCount(['documents', 'trips', 'completedTrips']));
    }

    public function update(DriverRequest $request, Driver $driver): DriverResource
    {
        $driver->update($request->validated());

        return DriverResource::make($driver->fresh('assignedVehicle'))->additional(['message' => 'Driver updated.']);
    }

    public function destroy(Driver $driver): JsonResponse
    {
        if ($driver->trips()->whereIn('status', TripStatus::blocking())->exists()) {
            throw new BusinessRuleException('This driver has scheduled or active trips. Reassign or cancel them first.');
        }

        DB::transaction(function () use ($driver) {
            $driver->assignedVehicle()->update(['assigned_driver_id' => null]);
            $driver->delete();
        });

        return response()->json(['message' => "Driver {$driver->full_name} removed."]);
    }
}
