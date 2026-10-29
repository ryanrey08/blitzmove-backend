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
            'color' => $this->color,
            'body_type' => $this->body_type,
            'wheels' => $this->wheels,
            'engine_number' => $this->engine_number,
            'home_depot' => $this->home_depot,
            'gps_device_id' => $this->gps_device_id,
            'odometer_km' => $this->odometer_km,
            'acquired_at' => $this->acquired_at?->toDateString(),
            'cargo_volume_m3' => $this->cargo_volume_m3 !== null ? (float) $this->cargo_volume_m3 : null,
            'length_m' => $this->length_m !== null ? (float) $this->length_m : null,
            'width_m' => $this->width_m !== null ? (float) $this->width_m : null,
            'height_m' => $this->height_m !== null ? (float) $this->height_m : null,
            'fuel_tank_l' => $this->fuel_tank_l !== null ? (float) $this->fuel_tank_l : null,
            'fuel_economy_kml' => $this->fuel_economy_kml !== null ? (float) $this->fuel_economy_kml : null,
            'emission_standard' => $this->emission_standard,
            'features' => $this->features ?? [],
            'registration_expiry' => $this->whenLoaded('registration', fn () => $this->registration?->expires_at?->toDateString()),
            'insurance_expiry' => $this->whenLoaded('insurance', fn () => $this->insurance?->expires_at?->toDateString()),
            'photos' => VehiclePhotoResource::collection($this->whenLoaded('photos')),
            'cover_photo' => $this->whenLoaded('photos', fn () => $this->photos->first() ? "vehicles/{$this->id}/photos/{$this->photos->first()->id}" : null),
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
