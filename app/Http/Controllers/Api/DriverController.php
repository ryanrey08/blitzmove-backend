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
use App\Models\Vehicle;
use App\Services\DashboardService;
use App\Services\NumberGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        return response()->json(['data' => $dashboard->distributionWithTrend(Driver::query(), DriverStatus::class)]);
    }

    public function store(DriverRequest $request, NumberGenerator $numbers): JsonResponse
    {
        $driver = DB::transaction(function () use ($request, $numbers) {
            $driver = Driver::create([
                ...collect($request->validated())->except('assigned_vehicle_id')->all(),
                'employee_code' => $numbers->next(Driver::class, 'employee_code', 'DRV', yearly: false),
            ]);
            $this->assignVehicle($driver, $request);

            return $driver;
        });

        return DriverResource::make($driver->load('assignedVehicle'))
            ->additional(['message' => "Driver {$driver->full_name} added."])
            ->response()->setStatusCode(201);
    }

    public function show(Driver $driver): DriverResource
    {
        $since = now()->subDays(30);
        $completed = $driver->trips()->where('status', TripStatus::Completed)->where('actual_arrival_at', '>=', $since)->get(['actual_arrival_at', 'estimated_arrival_at']);

        return DriverResource::make($driver->load([
            'assignedVehicle.photos',
            'trips' => fn ($q) => $q->with('vehicle')->latest('departure_at')->limit(10),
            'fuelRecords' => fn ($q) => $q->with('vehicle')->latest('fueled_at')->limit(10),
            'documents' => fn ($q) => $q->with('files')->orderByRaw('expires_at is null')->orderBy('expires_at'),
        ])->loadCount(['documents', 'trips', 'completedTrips', 'fuelRecords']))->additional(['performance' => [
            // Last 30 days, from trip records only (ratings and incidents aren't tracked).
            'completed_trips' => $completed->count(),
            'on_time_rate' => $completed->count() ? round($completed->filter(fn ($t) => $t->actual_arrival_at->lte($t->estimated_arrival_at))->count() / $completed->count() * 100) : null,
            'cancelled_trips' => $driver->trips()->where('status', TripStatus::Cancelled)->where('updated_at', '>=', $since)->count(),
        ]]);
    }

    public function update(DriverRequest $request, Driver $driver): DriverResource
    {
        DB::transaction(function () use ($request, $driver) {
            $driver->update(collect($request->validated())->except('assigned_vehicle_id')->all());
            $this->assignVehicle($driver, $request);
        });

        return DriverResource::make($driver->fresh('assignedVehicle'))->additional(['message' => 'Driver updated.']);
    }

    /** A vehicle has at most one regular driver; reassigning one that belongs to someone else is refused. */
    private function assignVehicle(Driver $driver, DriverRequest $request): void
    {
        if (! $request->has('assigned_vehicle_id')) {
            return;
        }
        $vehicleId = $request->validated('assigned_vehicle_id');
        if ($vehicleId) {
            $vehicle = Vehicle::lockForUpdate()->findOrFail($vehicleId);
            if ($vehicle->assigned_driver_id && $vehicle->assigned_driver_id !== $driver->id) {
                throw BusinessRuleException::withField('assigned_vehicle_id', "{$vehicle->plate_number} is already assigned to {$vehicle->assignedDriver?->full_name}.");
            }
        }
        Vehicle::where('assigned_driver_id', $driver->id)->when($vehicleId, fn ($q) => $q->whereKeyNot($vehicleId))->update(['assigned_driver_id' => null]);
        if ($vehicleId) {
            $vehicle->update(['assigned_driver_id' => $driver->id]);
        }
    }

    public function storePhoto(Request $request, Driver $driver): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120']], ['photo.max' => 'Photos can be up to 5 MB.', 'photo.mimes' => 'Photos must be JPG or PNG.']);
        $previous = $driver->photo_path;
        $driver->update(['photo_path' => $request->file('photo')->store("driver-photos/{$driver->id}", 'local')]);
        if ($previous) {
            Storage::disk('local')->delete($previous);
        }

        return response()->json(['data' => ['photo' => "drivers/{$driver->id}/photo"], 'message' => 'Photo updated.']);
    }

    public function photo(Driver $driver): StreamedResponse
    {
        abort_unless($driver->photo_path && Storage::disk('local')->exists($driver->photo_path), 404);

        return Storage::disk('local')->response($driver->photo_path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    public function destroyPhoto(Driver $driver): JsonResponse
    {
        if ($driver->photo_path) {
            Storage::disk('local')->delete($driver->photo_path);
            $driver->update(['photo_path' => null]);
        }

        return response()->json(['message' => 'Photo removed.']);
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
