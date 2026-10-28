<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'customer_name' => $this->customer_name,
            'customer_contact' => $this->customer_contact,
            'pickup_address' => $this->pickup_address,
            'delivery_address' => $this->delivery_address,
            'scheduled_pickup_at' => $this->scheduled_pickup_at?->toIso8601String(),
            'scheduled_delivery_at' => $this->scheduled_delivery_at?->toIso8601String(),
            'vehicle_id' => $this->vehicle_id,
            'driver_id' => $this->driver_id,
            'vehicle' => VehicleResource::make($this->whenLoaded('vehicle')),
            'driver' => DriverResource::make($this->whenLoaded('driver')),
            'cargo_description' => $this->cargo_description,
            'quantity' => $this->quantity,
            'weight_kg' => $this->weight_kg !== null ? (float) $this->weight_kg : null,
            'delivery_instructions' => $this->delivery_instructions,
            'status' => $this->status?->value,
            'allowed_transitions' => array_map(fn ($s) => $s->value, $this->status?->allowedTransitions() ?? []),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'trips' => TripResource::collection($this->whenLoaded('trips')),
            'created_by' => UserResource::make($this->whenLoaded('creator')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
