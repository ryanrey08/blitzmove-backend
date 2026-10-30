<?php

namespace App\Http\Resources;

use App\Models\MaintenanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MaintenanceRecord */
class MaintenanceRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'vehicle_id' => $this->vehicle_id,
            'vehicle' => VehicleResource::make($this->whenLoaded('vehicle')),
            'service_type' => $this->service_type?->value,
            'category' => $this->category?->value,
            'status' => $this->status?->value,
            // scheduled records show as due_soon / overdue by date
            'display_status' => $this->displayStatus(),
            'scheduled_date' => $this->scheduled_date?->toDateString(),
            'service_date' => $this->service_date?->toDateString(),
            // Previous completed service of the same type on this vehicle (list/detail queries only).
            'last_service_date' => $this->when(array_key_exists('last_service_date', $this->getAttributes()), fn () => $this->last_service_date ? substr((string) $this->last_service_date, 0, 10) : null),
            'odometer_km' => $this->odometer_km,
            'estimated_cost' => $this->estimated_cost === null ? null : (float) $this->estimated_cost,
            'actual_cost' => $this->actual_cost === null ? null : (float) $this->actual_cost,
            'parts_total' => $this->partsTotal(),
            'service_provider' => $this->service_provider,
            'assigned_to' => $this->assigned_to,
            'reminder_days' => $this->reminder_days,
            'description' => $this->description,
            'parts' => $this->parts ?? [],
            'checklist' => $this->checklist ?? [],
            'notes' => $this->notes,
            'files' => DocumentFileResource::collection($this->whenLoaded('files')),
            'files_count' => $this->whenCounted('files'),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
