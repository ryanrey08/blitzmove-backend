<?php

namespace App\Http\Resources;

use App\Models\VehiclePhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VehiclePhoto */
class VehiclePhotoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'position' => $this->position,
            // Served through the API (private disk); relative so clients prefix their own API base.
            'path' => "vehicles/{$this->vehicle_id}/photos/{$this->id}",
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
