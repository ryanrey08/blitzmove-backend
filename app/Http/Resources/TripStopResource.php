<?php

namespace App\Http\Resources;

use App\Models\TripStop;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TripStop */
class TripStopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trip_id' => $this->trip_id,
            'sequence' => $this->sequence,
            'type' => $this->type?->value,
            'location_name' => $this->location_name,
            'address' => $this->address,
            'order_id' => $this->order_id,
            'order' => OrderResource::make($this->whenLoaded('order')),
            'estimated_arrival_at' => $this->estimated_arrival_at?->toIso8601String(),
            'actual_arrival_at' => $this->actual_arrival_at?->toIso8601String(),
            'status' => $this->status?->value,
            'notes' => $this->notes,
        ];
    }
}
