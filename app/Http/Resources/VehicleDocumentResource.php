<?php

namespace App\Http\Resources;

use App\Models\VehicleDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VehicleDocument */
class VehicleDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner_type' => 'vehicle',
            'vehicle_id' => $this->vehicle_id,
            'vehicle' => VehicleResource::make($this->whenLoaded('vehicle')),
            'document_type' => $this->document_type?->value,
            'title' => $this->title,
            'document_number' => $this->document_number,
            'issued_at' => $this->issued_at?->toDateString(),
            'expires_at' => $this->expires_at?->toDateString(),
            'expiry_status' => $this->expiryStatus(),
            'issuing_authority' => $this->issuing_authority,
            'files' => DocumentFileResource::collection($this->whenLoaded('files')),
            'files_count' => $this->whenLoaded('files', fn () => $this->files->count()),
            'has_file' => $this->whenLoaded('files', fn () => $this->files->isNotEmpty()),
            'notes' => $this->notes,
            'uploaded_by' => UserResource::make($this->whenLoaded('uploader')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
