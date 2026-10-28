<?php

namespace App\Http\Controllers\Api;

use App\Enums\TripStatus;
use App\Http\Controllers\Concerns\ListsResources;
use App\Http\Controllers\Controller;
use App\Http\Requests\AvailabilityRequest;
use App\Http\Requests\ReassignTripRequest;
use App\Http\Requests\StoreTripRequest;
use App\Http\Requests\UpdateTripRequest;
use App\Http\Requests\UpdateTripStatusRequest;
use App\Http\Resources\DriverResource;
use App\Http\Resources\TripResource;
use App\Http\Resources\VehicleResource;
use App\Models\Trip;
use App\Services\DashboardService;
use App\Services\TripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class TripController extends Controller
{
    use ListsResources;

    public function __construct(private TripService $trips) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Trip::query()->with(['order', 'vehicle', 'driver'])->withCount('stops');

        $this->applySearch($query, $request, ['trip_number', 'origin', 'destination', 'order.order_number', 'driver.full_name', 'vehicle.plate_number']);
        $this->applyFilters($query, $request, ['status', 'vehicle_id', 'driver_id', 'order_id']);

        if ($request->query('scope') === 'dispatch') {
            $query->whereIn('status', [TripStatus::Scheduled, TripStatus::Dispatched, TripStatus::InProgress]);
        }
        if ($from = $request->query('from')) {
            $query->where('departure_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('departure_at', '<=', $to.' 23:59:59');
        }

        $this->applySort($query, $request, ['trip_number', 'departure_at', 'estimated_arrival_at', 'status', 'created_at'], 'departure_at');

        return TripResource::collection($query->paginate($this->perPage($request))->withQueryString());
    }

    public function summary(DashboardService $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->distribution(Trip::query(), TripStatus::class)]);
    }

    public function availability(AvailabilityRequest $request): JsonResponse
    {
        $resources = $this->trips->availableResources(
            Carbon::parse($request->input('departure_at')),
            Carbon::parse($request->input('estimated_arrival_at')),
            $request->integer('exclude_trip_id') ?: null,
        );

        return response()->json(['data' => [
            'vehicles' => VehicleResource::collection($resources['vehicles']),
            'drivers' => DriverResource::collection($resources['drivers']),
        ]]);
    }

    public function store(StoreTripRequest $request): JsonResponse
    {
        $trip = $this->trips->schedule($request->validated(), $request->user());

        return TripResource::make($this->detailed($trip))
            ->additional(['message' => "Trip {$trip->trip_number} scheduled."])
            ->response()->setStatusCode(201);
    }

    public function show(Trip $trip): TripResource
    {
        return TripResource::make($this->detailed($trip));
    }

    public function update(UpdateTripRequest $request, Trip $trip): TripResource
    {
        $this->trips->update($trip, $request->validated());

        return TripResource::make($this->detailed($trip))->additional(['message' => 'Trip updated.']);
    }

    public function reassign(ReassignTripRequest $request, Trip $trip): TripResource
    {
        $this->trips->reassign($trip, $request->integer('vehicle_id'), $request->integer('driver_id'), $request->user(), $request->input('reason'));

        return TripResource::make($this->detailed($trip))->additional(['message' => 'Trip reassigned.']);
    }

    public function updateStatus(UpdateTripStatusRequest $request, Trip $trip): TripResource
    {
        $status = TripStatus::from($request->input('status'));
        $this->trips->changeStatus($trip, $status, $request->user(), $request->input('notes'));

        return TripResource::make($this->detailed($trip))->additional(['message' => "Trip {$trip->trip_number} is now {$status->label()}."]);
    }

    private function detailed(Trip $trip): Trip
    {
        return $trip->refresh()->load([
            'order', 'vehicle', 'driver', 'dispatcher', 'stops.order',
            'assignments.vehicle', 'assignments.driver', 'assignments.assigner',
        ]);
    }
}
