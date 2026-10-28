<?php

namespace App\Models;

use App\Enums\FuelType;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'plate_number', 'vehicle_type', 'make', 'model', 'year', 'capacity_kg',
    'fuel_type', 'vin', 'status', 'assigned_driver_id', 'notes',
])]
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory, SoftDeletes;

    protected $attributes = [
        'status' => 'available',
    ];

    protected function casts(): array
    {
        return [
            'vehicle_type' => VehicleType::class,
            'fuel_type' => FuelType::class,
            'status' => VehicleStatus::class,
            'year' => 'integer',
            'capacity_kg' => 'decimal:2',
        ];
    }

    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'assigned_driver_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function activeTrips(): HasMany
    {
        return $this->trips()->whereIn('status', TripStatus::active());
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class);
    }

    public function fuelRecords(): HasMany
    {
        return $this->hasMany(FuelRecord::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(VehicleLocation::class);
    }

    public function latestLocation(): HasOne
    {
        return $this->hasOne(VehicleLocation::class)->latestOfMany('recorded_at');
    }
}
