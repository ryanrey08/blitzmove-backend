<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['vehicle_id', 'file_path', 'original_name', 'mime_type', 'file_size', 'position', 'uploaded_by'])]
class VehiclePhoto extends Model
{
    protected function casts(): array
    {
        return ['file_size' => 'integer', 'position' => 'integer'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }
}
