<?php

namespace App\Services;

use App\Enums\StopStatus;
use App\Enums\StopType;
use App\Enums\TripStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Trip;
use App\Models\TripStop;
use Illuminate\Support\Facades\DB;

/**
 * Manages the ordered stops of a trip. The first stop is always the pickup
 * (origin) and the last the drop-off (destination); intermediate stops can be
 * added, edited, removed and re-ordered while the trip is not finished.
 */
class ItineraryService
{
    public function addStop(Trip $trip, array $data): TripStop
    {
        $this->assertEditable($trip);

        return DB::transaction(function () use ($trip, $data) {
            $stops = $trip->stops()->lockForUpdate()->get();
            $dropoff = $stops->firstWhere('type', StopType::Dropoff);
            $position = $dropoff ? $dropoff->sequence : $stops->count() + 1;

            if (isset($data['position'])) {
                $position = max(2, min((int) $data['position'], $position));
            }

            $trip->stops()->where('sequence', '>=', $position)->increment('sequence');

            $this->clearOptimization($trip);

            return $trip->stops()->create([
                ...collect($data)->only(['location_name', 'address', 'order_id', 'estimated_arrival_at', 'notes'])->all(),
                'type' => StopType::Stop,
                'sequence' => $position,
                'status' => StopStatus::Pending,
            ]);
        });
    }

    public function updateStop(TripStop $stop, array $data): TripStop
    {
        if (isset($data['status'])) {
            $status = StopStatus::from($data['status']);
            if ($stop->trip->status !== TripStatus::InProgress && $status !== StopStatus::Pending) {
                throw BusinessRuleException::withField('status', 'Stop progress can only be recorded while the trip is in progress.');
            }
            $data['actual_arrival_at'] = $status === StopStatus::Pending ? null : ($stop->actual_arrival_at ?? now());
        } else {
            $this->assertEditable($stop->trip);
        }

        $stop->update($data);

        return $stop;
    }

    public function removeStop(TripStop $stop): void
    {
        $this->assertEditable($stop->trip);

        if ($stop->type !== StopType::Stop) {
            throw new BusinessRuleException('The origin and destination stops cannot be removed.');
        }

        DB::transaction(function () use ($stop) {
            $trip = $stop->trip;
            $sequence = $stop->sequence;
            $stop->delete();
            $trip->stops()->where('sequence', '>', $sequence)->decrement('sequence');
            $this->clearOptimization($trip);
        });
    }

    /**
     * @param  list<int>  $stopIds  intermediate stops in their new order
     * @param  bool  $optimized  the order came from the routing provider (marks the route as optimized)
     */
    public function reorder(Trip $trip, array $stopIds, bool $optimized = false, ?float $distanceKm = null): Trip
    {
        $this->assertEditable($trip);

        $intermediate = $trip->stops()->where('type', StopType::Stop)->pluck('id')->sort()->values()->all();
        $given = collect($stopIds)->map(fn ($id) => (int) $id);

        if ($given->sort()->values()->all() !== $intermediate) {
            throw BusinessRuleException::withField('stop_ids', 'Provide every intermediate stop of this trip exactly once.');
        }

        DB::transaction(function () use ($trip, $given, $optimized, $distanceKm) {
            foreach ($given->values() as $index => $id) {
                $trip->stops()->whereKey($id)->update(['sequence' => $index + 2]);
            }
            $trip->stops()->where('type', StopType::Dropoff)->update(['sequence' => $given->count() + 2]);
            $changes = ['route_optimized_at' => $optimized ? now() : null];
            if ($optimized && $distanceKm !== null) {
                $changes['distance_km'] = $distanceKm;
            }
            $trip->update($changes);
        });

        return $trip;
    }

    private function clearOptimization(Trip $trip): void
    {
        $trip->route_optimized_at && $trip->update(['route_optimized_at' => null]);
    }

    private function assertEditable(Trip $trip): void
    {
        if (in_array($trip->status, [TripStatus::Completed, TripStatus::Cancelled], true)) {
            throw new BusinessRuleException("The itinerary of a {$trip->status->label()} trip cannot be changed.");
        }
    }
}
