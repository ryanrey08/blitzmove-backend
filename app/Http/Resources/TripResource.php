<?php

namespace App\Http\Resources;

use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Trip */
class TripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trip_number' => $this->trip_number,
            'reference_no' => $this->reference_no,
            'trip_type' => $this->trip_type?->value,
            'priority' => $this->priority?->value,
            'order_id' => $this->order_id,
            'vehicle_id' => $this->vehicle_id,
            'driver_id' => $this->driver_id,
            'co_driver_id' => $this->co_driver_id,
            'order' => OrderResource::make($this->whenLoaded('order')),
            'vehicle' => VehicleResource::make($this->whenLoaded('vehicle')),
            'driver' => DriverResource::make($this->whenLoaded('driver')),
            'co_driver' => DriverResource::make($this->whenLoaded('coDriver')),
            'origin' => $this->origin,
            'destination' => $this->destination,
            'departure_at' => $this->departure_at?->toIso8601String(),
            'estimated_arrival_at' => $this->estimated_arrival_at?->toIso8601String(),
            'actual_departure_at' => $this->actual_departure_at?->toIso8601String(),
            'actual_arrival_at' => $this->actual_arrival_at?->toIso8601String(),
            'distance_km' => $this->distance_km !== null ? (float) $this->distance_km : null,
            'route_optimized_at' => $this->route_optimized_at?->toIso8601String(),
            'status' => $this->status?->value,
            'allowed_transitions' => array_map(fn ($s) => $s->value, $this->status?->allowedTransitions() ?? []),
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'dispatched_by' => UserResource::make($this->whenLoaded('dispatcher')),
            'dispatch_notes' => $this->dispatch_notes,
            'notes' => $this->notes,
            'created_by' => UserResource::make($this->whenLoaded('creator')),
            'stops_count' => $this->whenCounted('stops'),
            'stops' => TripStopResource::collection($this->whenLoaded('stops')),
            'assignments' => DispatchAssignmentResource::collection($this->whenLoaded('assignments')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
