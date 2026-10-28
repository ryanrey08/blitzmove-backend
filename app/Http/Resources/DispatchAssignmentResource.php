<?php

namespace App\Http\Resources;

use App\Models\DispatchAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DispatchAssignment */
class DispatchAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle' => VehicleResource::make($this->whenLoaded('vehicle')),
            'driver' => DriverResource::make($this->whenLoaded('driver')),
            'assigned_by' => UserResource::make($this->whenLoaded('assigner')),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
            'reason' => $this->reason,
        ];
    }
}
