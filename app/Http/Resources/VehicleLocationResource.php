<?php

namespace App\Http\Resources;

use App\Models\VehicleLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VehicleLocation */
class VehicleLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'trip_id' => $this->trip_id,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'speed_kph' => $this->speed_kph,
            'heading' => $this->heading,
            'source' => $this->source,
            'recorded_at' => $this->recorded_at?->toIso8601String(),
        ];
    }
}
