<?php

namespace App\Http\Resources;

use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Driver */
class DriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_code' => $this->employee_code,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'license_number' => $this->license_number,
            'license_type' => $this->license_type?->value,
            'license_expiry' => $this->license_expiry?->toDateString(),
            'license_expired' => $this->license_expiry?->isPast() && ! $this->license_expiry->isToday(),
            'date_hired' => $this->date_hired?->toDateString(),
            'status' => $this->status?->value,
            'notes' => $this->notes,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'license_restrictions' => $this->license_restrictions,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'emergency_contact_relation' => $this->emergency_contact_relation,
            // API path of the photo; open it through the API client.
            'photo' => $this->photo_path ? "drivers/{$this->id}/photo" : null,
            'assigned_vehicle' => VehicleResource::make($this->whenLoaded('assignedVehicle')),
            'documents_count' => $this->whenCounted('documents'),
            'trips_count' => $this->whenCounted('trips'),
            'completed_trips_count' => $this->whenCounted('completedTrips'),
            'recent_trips' => TripResource::collection($this->whenLoaded('trips')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
