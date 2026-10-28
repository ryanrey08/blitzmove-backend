<?php

namespace App\Http\Resources;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Vehicle */
class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plate_number' => $this->plate_number,
            'vehicle_type' => $this->vehicle_type?->value,
            'make' => $this->make,
            'model' => $this->model,
            'year' => $this->year,
            'capacity_kg' => $this->capacity_kg !== null ? (float) $this->capacity_kg : null,
            'fuel_type' => $this->fuel_type?->value,
            'vin' => $this->vin,
            'status' => $this->status?->value,
            'assigned_driver_id' => $this->assigned_driver_id,
            'assigned_driver' => DriverResource::make($this->whenLoaded('assignedDriver')),
            'active_trip' => $this->whenLoaded('activeTrips', fn () => TripResource::make($this->activeTrips->first())),
            'latest_location' => VehicleLocationResource::make($this->whenLoaded('latestLocation')),
            'documents_count' => $this->whenCounted('documents'),
            'trips_count' => $this->whenCounted('trips'),
            'recent_trips' => TripResource::collection($this->whenLoaded('trips')),
            'recent_fuel_records' => FuelRecordResource::collection($this->whenLoaded('fuelRecords')),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
