<?php

namespace App\Services;

use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Enums\StopStatus;
use App\Enums\StopType;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\DispatchAssignment;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TripService
{
    public function __construct(private NumberGenerator $numbers) {}

    public function schedule(array $data, User $user): Trip
    {
        $start = Carbon::parse($data['departure_at']);
        $end = Carbon::parse($data['estimated_arrival_at']);

        return DB::transaction(function () use ($data, $user, $start, $end) {
            $order = isset($data['order_id']) ? Order::lockForUpdate()->findOrFail($data['order_id']) : null;

            if ($order && ! in_array($order->status, [OrderStatus::Pending, OrderStatus::Scheduled], true)) {
                throw BusinessRuleException::withField('order_id', "Order {$order->order_number} is {$order->status->label()} and cannot be scheduled.");
            }

            $vehicle = Vehicle::lockForUpdate()->findOrFail($data['vehicle_id']);
            $driver = Driver::lockForUpdate()->findOrFail($data['driver_id']);
            $this->assertAvailable($vehicle, $driver, $start, $end);

            $trip = Trip::create([
                'trip_number' => $this->numbers->next(Trip::class, 'trip_number', 'TRIP'),
                'order_id' => $order?->id,
                'vehicle_id' => $vehicle->id,
                'driver_id' => $driver->id,
                'origin' => $data['origin'],
                'destination' => $data['destination'],
                'departure_at' => $start,
                'estimated_arrival_at' => $end,
                'distance_km' => $data['distance_km'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => TripStatus::Scheduled,
                'created_by' => $user->id,
            ]);

            $this->createStops($trip, $data['stops'] ?? [], $order);

            DispatchAssignment::create([
                'trip_id' => $trip->id,
                'vehicle_id' => $vehicle->id,
                'driver_id' => $driver->id,
                'assigned_by' => $user->id,
                'assigned_at' => now(),
                'reason' => 'Initial assignment',
            ]);

            $order?->update([
                'status' => OrderStatus::Scheduled,
                'vehicle_id' => $vehicle->id,
                'driver_id' => $driver->id,
            ]);

            return $trip;
        });
    }

    public function update(Trip $trip, array $data): Trip
    {
        if ($trip->status !== TripStatus::Scheduled) {
            throw new BusinessRuleException('Only scheduled trips can be edited. Use dispatch actions for trips already released.');
        }

        return DB::transaction(function () use ($trip, $data) {
            $start = Carbon::parse($data['departure_at'] ?? $trip->departure_at);
            $end = Carbon::parse($data['estimated_arrival_at'] ?? $trip->estimated_arrival_at);

            if ($end->lte($start)) {
                throw BusinessRuleException::withField('estimated_arrival_at', 'Estimated arrival must be after departure.');
            }

            $this->assertAvailable($trip->vehicle, $trip->driver, $start, $end, $trip->id);

            $trip->update([
                ...collect($data)->only(['origin', 'destination', 'distance_km', 'notes'])->all(),
                'departure_at' => $start,
                'estimated_arrival_at' => $end,
            ]);

            $stops = $trip->stops()->get();
            $stops->first()?->update(['location_name' => $trip->origin, 'estimated_arrival_at' => $start]);
            if ($stops->count() > 1) {
                $stops->last()->update(['location_name' => $trip->destination, 'estimated_arrival_at' => $end]);
            }

            return $trip;
        });
    }

    public function reassign(Trip $trip, int $vehicleId, int $driverId, User $user, ?string $reason = null): Trip
    {
        if (! $trip->status->isReassignable()) {
            throw new BusinessRuleException("A {$trip->status->label()} trip cannot be reassigned.");
        }

        if ($trip->vehicle_id === $vehicleId && $trip->driver_id === $driverId) {
            throw new BusinessRuleException('The trip is already assigned to this vehicle and driver.');
        }

        return DB::transaction(function () use ($trip, $vehicleId, $driverId, $user, $reason) {
            $vehicle = Vehicle::lockForUpdate()->findOrFail($vehicleId);
            $driver = Driver::lockForUpdate()->findOrFail($driverId);
            $this->assertAvailable($vehicle, $driver, $trip->departure_at, $trip->estimated_arrival_at, $trip->id);

            $trip->assignments()->whereNull('released_at')->update(['released_at' => now()]);

            DispatchAssignment::create([
                'trip_id' => $trip->id,
                'vehicle_id' => $vehicle->id,
                'driver_id' => $driver->id,
                'assigned_by' => $user->id,
                'assigned_at' => now(),
                'reason' => $reason ?: 'Reassigned',
            ]);

            $trip->update(['vehicle_id' => $vehicle->id, 'driver_id' => $driver->id]);
            $trip->order?->update(['vehicle_id' => $vehicle->id, 'driver_id' => $driver->id]);

            return $trip;
        });
    }

    public function dispatch(Trip $trip, User $user, ?string $notes = null): Trip
    {
        $this->assertTransition($trip, TripStatus::Dispatched);

        $trip->loadMissing(['vehicle', 'driver']);
        $errors = [];

        if (! $trip->vehicle->status->isAssignable() || $trip->vehicle->trashed()) {
            $errors['vehicle_id'][] = "Vehicle {$trip->vehicle->plate_number} is {$trip->vehicle->status->label()}.";
        }
        if (! $trip->driver->status->isAssignable() || $trip->driver->trashed()) {
            $errors['driver_id'][] = "Driver {$trip->driver->full_name} is {$trip->driver->status->label()}.";
        }
        if ($trip->driver->license_expiry->lt($trip->departure_at->copy()->startOfDay())) {
            $errors['driver_id'][] = "Driver {$trip->driver->full_name}'s license has expired.";
        }
        if ($errors) {
            throw new BusinessRuleException('The trip cannot be dispatched yet.', $errors);
        }

        $trip->update([
            'status' => TripStatus::Dispatched,
            'dispatched_at' => now(),
            'dispatched_by' => $user->id,
            'dispatch_notes' => $notes,
        ]);

        return $trip;
    }

    public function changeStatus(Trip $trip, TripStatus $next, User $user, ?string $notes = null): Trip
    {
        return match ($next) {
            TripStatus::Dispatched => $this->dispatch($trip, $user, $notes),
            TripStatus::InProgress => $this->start($trip),
            TripStatus::Completed => $this->complete($trip),
            TripStatus::Cancelled => $this->cancel($trip, $notes),
            TripStatus::Scheduled => $this->recall($trip),
        };
    }

    public function start(Trip $trip): Trip
    {
        $this->assertTransition($trip, TripStatus::InProgress);

        return DB::transaction(function () use ($trip) {
            $trip->update(['status' => TripStatus::InProgress, 'actual_departure_at' => now()]);
            $trip->vehicle->update(['status' => VehicleStatus::OnTrip]);
            $trip->driver->update(['status' => DriverStatus::OnTrip]);
            $trip->order?->update(['status' => OrderStatus::InTransit]);
            $trip->stops()->where('type', StopType::Pickup)->where('sequence', 1)
                ->update(['status' => StopStatus::Completed, 'actual_arrival_at' => now()]);

            return $trip;
        });
    }

    public function complete(Trip $trip): Trip
    {
        $this->assertTransition($trip, TripStatus::Completed);

        return DB::transaction(function () use ($trip) {
            $trip->update(['status' => TripStatus::Completed, 'actual_arrival_at' => now()]);
            $trip->stops()->where('status', StopStatus::Pending)
                ->update(['status' => StopStatus::Completed, 'actual_arrival_at' => now()]);
            $trip->assignments()->whereNull('released_at')->update(['released_at' => now()]);
            $trip->order?->update(['status' => OrderStatus::Delivered, 'delivered_at' => now()]);
            $this->releaseResources($trip);

            return $trip;
        });
    }

    /** Pull a dispatched trip back to scheduled so it can be changed. */
    public function recall(Trip $trip): Trip
    {
        $this->assertTransition($trip, TripStatus::Scheduled);
        $trip->update(['status' => TripStatus::Scheduled, 'dispatched_at' => null, 'dispatched_by' => null]);

        return $trip;
    }

    public function cancel(Trip $trip, ?string $reason = null): Trip
    {
        $this->assertTransition($trip, TripStatus::Cancelled);

        return DB::transaction(function () use ($trip, $reason) {
            $trip->update([
                'status' => TripStatus::Cancelled,
                'notes' => trim(($trip->notes ? $trip->notes."\n" : '').($reason ? "Cancelled: {$reason}" : 'Cancelled')),
            ]);
            $trip->assignments()->whereNull('released_at')->update(['released_at' => now()]);

            $order = $trip->order;
            if ($order && $order->status === OrderStatus::Scheduled
                && ! $order->trips()->whereKeyNot($trip->id)->whereIn('status', TripStatus::blocking())->exists()) {
                $order->update(['status' => OrderStatus::Pending, 'vehicle_id' => null, 'driver_id' => null]);
            }

            return $trip;
        });
    }

    /**
     * Raise a validation-style error when the vehicle or driver cannot take a
     * trip in the given window.
     */
    public function assertAvailable(Vehicle $vehicle, Driver $driver, CarbonInterface $start, CarbonInterface $end, ?int $exceptTripId = null): void
    {
        $errors = [];

        if ($vehicle->trashed() || ! $vehicle->status->isAssignable()) {
            $errors['vehicle_id'][] = "Vehicle {$vehicle->plate_number} is {$vehicle->status->label()} and cannot be assigned.";
        } elseif ($conflict = $this->conflictingTrip('vehicle_id', $vehicle->id, $start, $end, $exceptTripId)) {
            $errors['vehicle_id'][] = "Vehicle {$vehicle->plate_number} is already booked on {$conflict->trip_number} ({$conflict->departure_at->format('M j, H:i')} – {$conflict->estimated_arrival_at->format('M j, H:i')}).";
        }

        if ($driver->trashed() || ! $driver->status->isAssignable()) {
            $errors['driver_id'][] = "Driver {$driver->full_name} is {$driver->status->label()} and cannot be assigned.";
        } elseif ($driver->license_expiry->lt($start->copy()->startOfDay())) {
            $errors['driver_id'][] = "Driver {$driver->full_name}'s license expires on {$driver->license_expiry->format('M j, Y')}, before departure.";
        } elseif ($conflict = $this->conflictingTrip('driver_id', $driver->id, $start, $end, $exceptTripId)) {
            $errors['driver_id'][] = "Driver {$driver->full_name} is already booked on {$conflict->trip_number} ({$conflict->departure_at->format('M j, H:i')} – {$conflict->estimated_arrival_at->format('M j, H:i')}).";
        }

        if ($errors) {
            throw new BusinessRuleException('The selected vehicle or driver is not available for this schedule.', $errors);
        }
    }

    /** @return array{vehicles: Collection<int, Vehicle>, drivers: Collection<int, Driver>} */
    public function availableResources(CarbonInterface $start, CarbonInterface $end, ?int $exceptTripId = null): array
    {
        $busy = fn (string $column) => Trip::query()
            ->overlapping($start, $end)
            ->when($exceptTripId, fn ($q) => $q->whereKeyNot($exceptTripId))
            ->pluck($column);

        return [
            'vehicles' => Vehicle::query()
                ->whereIn('status', [VehicleStatus::Available, VehicleStatus::OnTrip])
                ->whereNotIn('id', $busy('vehicle_id'))
                ->orderBy('plate_number')->get(),
            'drivers' => Driver::query()
                ->whereIn('status', [DriverStatus::Available, DriverStatus::OnTrip])
                ->whereDate('license_expiry', '>=', $start->toDateString())
                ->whereNotIn('id', $busy('driver_id'))
                ->orderBy('full_name')->get(),
        ];
    }

    private function conflictingTrip(string $column, int $id, CarbonInterface $start, CarbonInterface $end, ?int $exceptTripId): ?Trip
    {
        return Trip::query()
            ->where($column, $id)
            ->overlapping($start, $end)
            ->when($exceptTripId, fn ($q) => $q->whereKeyNot($exceptTripId))
            ->orderBy('departure_at')
            ->first();
    }

    private function createStops(Trip $trip, array $stops, ?Order $order): void
    {
        $rows = [[
            'type' => StopType::Pickup,
            'location_name' => $trip->origin,
            'address' => $order?->pickup_address,
            'order_id' => $order?->id,
            'estimated_arrival_at' => $trip->departure_at,
        ]];

        foreach ($stops as $stop) {
            $rows[] = [
                'type' => StopType::Stop,
                'location_name' => $stop['location_name'],
                'address' => $stop['address'] ?? null,
                'order_id' => $stop['order_id'] ?? null,
                'estimated_arrival_at' => $stop['estimated_arrival_at'] ?? null,
                'notes' => $stop['notes'] ?? null,
            ];
        }

        $rows[] = [
            'type' => StopType::Dropoff,
            'location_name' => $trip->destination,
            'address' => $order?->delivery_address,
            'order_id' => $order?->id,
            'estimated_arrival_at' => $trip->estimated_arrival_at,
        ];

        foreach ($rows as $index => $row) {
            $trip->stops()->create([...$row, 'sequence' => $index + 1, 'status' => StopStatus::Pending]);
        }
    }

    private function releaseResources(Trip $trip): void
    {
        $otherActive = fn (string $column, int $id) => Trip::query()
            ->where($column, $id)->whereKeyNot($trip->id)
            ->where('status', TripStatus::InProgress)->exists();

        if ($trip->vehicle->status === VehicleStatus::OnTrip && ! $otherActive('vehicle_id', $trip->vehicle_id)) {
            $trip->vehicle->update(['status' => VehicleStatus::Available]);
        }
        if ($trip->driver->status === DriverStatus::OnTrip && ! $otherActive('driver_id', $trip->driver_id)) {
            $trip->driver->update(['status' => DriverStatus::Available]);
        }
    }

    private function assertTransition(Trip $trip, TripStatus $next): void
    {
        if (! $trip->status->canTransitionTo($next)) {
            throw BusinessRuleException::withField(
                'status',
                "Cannot change trip {$trip->trip_number} from {$trip->status->label()} to {$next->label()}.",
            );
        }
    }
}
