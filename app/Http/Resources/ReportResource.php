<?php

namespace App\Http\Resources;

use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Report */
class ReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'vehicle_id' => $this->filters['vehicle_id'] ?? null,
            'driver_id' => $this->filters['driver_id'] ?? null,
            'config' => $this->filters['config'] ?? null,
            'generated_by' => $this->whenLoaded('generator', fn () => $this->generator ? ['id' => $this->generator->id, 'name' => $this->generator->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
