<?php

namespace App\Http\Resources;

use App\Models\FuelRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FuelRecord */
class FuelRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'driver_id' => $this->driver_id,
            'trip_id' => $this->trip_id,
            'vehicle' => VehicleResource::make($this->whenLoaded('vehicle')),
            'driver' => DriverResource::make($this->whenLoaded('driver')),
            'trip' => TripResource::make($this->whenLoaded('trip')),
            'fueled_at' => $this->fueled_at?->toIso8601String(),
            'fuel_type' => $this->fuel_type?->value,
            'liters' => (float) $this->liters,
            'price_per_liter' => (float) $this->price_per_liter,
            'total_cost' => (float) $this->total_cost,
            'odometer_km' => $this->odometer_km,
            'station' => $this->station,
            'receipt_number' => $this->receipt_number,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
