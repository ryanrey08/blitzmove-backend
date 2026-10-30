<?php

namespace App\Http\Resources;

use App\Models\DocumentFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DocumentFile */
class DocumentFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $owner = $this->document_type === 'driver_document' ? 'driver' : 'vehicle';

        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            // Served through the API (private disk); relative so clients prefix their own API base. Add ?download=1 to save it.
            'path' => "documents/{$owner}/{$this->document_id}/files/{$this->id}",
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
