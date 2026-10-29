<?php

namespace App\Models;

use App\Enums\StopActivity;
use App\Enums\StopStatus;
use App\Enums\StopType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'trip_id', 'sequence', 'type', 'activity', 'location_name', 'address', 'order_id',
    'estimated_arrival_at', 'actual_arrival_at', 'status', 'notes',
])]
class TripStop extends Model
{
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'type' => StopType::class,
            'activity' => StopActivity::class,
            'status' => StopStatus::class,
            'estimated_arrival_at' => 'datetime',
            'actual_arrival_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
