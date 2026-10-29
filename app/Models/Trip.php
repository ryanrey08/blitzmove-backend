<?php

namespace App\Models;

use App\Enums\TripPriority;
use App\Enums\TripStatus;
use App\Enums\TripType;
use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'trip_number', 'reference_no', 'trip_type', 'priority', 'order_id', 'vehicle_id', 'driver_id', 'co_driver_id', 'origin', 'destination', 'departure_at',
    'estimated_arrival_at', 'actual_departure_at', 'actual_arrival_at', 'distance_km', 'status',
    'dispatched_at', 'dispatched_by', 'dispatch_notes', 'notes', 'created_by',
])]
class Trip extends Model
{
    /** @use HasFactory<TripFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'departure_at' => 'datetime',
            'estimated_arrival_at' => 'datetime',
            'actual_departure_at' => 'datetime',
            'actual_arrival_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'distance_km' => 'decimal:2',
            'status' => TripStatus::class,
            'trip_type' => TripType::class,
            'priority' => TripPriority::class,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class)->withTrashed();
    }

    public function coDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'co_driver_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function stops(): HasMany
    {
        return $this->hasMany(TripStop::class)->orderBy('sequence');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DispatchAssignment::class)->latest('assigned_at');
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(DispatchAssignment::class)->whereNull('released_at')->latestOfMany('assigned_at');
    }

    /** Trips whose window overlaps [$start, $end] and still hold their vehicle/driver. */
    public function scopeOverlapping(Builder $query, $start, $end): Builder
    {
        return $query
            ->whereIn('status', TripStatus::blocking())
            ->where('departure_at', '<', $end)
            ->where('estimated_arrival_at', '>', $start);
    }
}
