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
    'plate_number', 'vehicle_type', 'make', 'model', 'year', 'color', 'body_type', 'wheels', 'capacity_kg',
    'cargo_volume_m3', 'length_m', 'width_m', 'height_m', 'fuel_type', 'fuel_tank_l', 'fuel_economy_kml',
    'emission_standard', 'features', 'vin', 'engine_number', 'status', 'assigned_driver_id', 'home_depot',
    'gps_device_id', 'odometer_km', 'acquired_at', 'notes',
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
            'wheels' => 'integer',
            'cargo_volume_m3' => 'decimal:2',
            'length_m' => 'decimal:2',
            'width_m' => 'decimal:2',
            'height_m' => 'decimal:2',
            'fuel_tank_l' => 'decimal:2',
            'fuel_economy_kml' => 'decimal:2',
            'features' => 'array',
            'odometer_km' => 'integer',
            'acquired_at' => 'date',
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

    public function photos(): HasMany
    {
        return $this->hasMany(VehiclePhoto::class)->orderBy('position')->orderBy('id');
    }

    /** Current registration / insurance: the document of that type expiring last. */
    public function registration(): HasOne
    {
        return $this->hasOne(VehicleDocument::class)->ofMany(['expires_at' => 'max', 'id' => 'max'], fn ($q) => $q->where('document_type', 'registration'));
    }

    public function insurance(): HasOne
    {
        return $this->hasOne(VehicleDocument::class)->ofMany(['expires_at' => 'max', 'id' => 'max'], fn ($q) => $q->where('document_type', 'insurance'));
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class);
    }

    public function fuelRecords(): HasMany
    {
        return $this->hasMany(FuelRecord::class);
    }

    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
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
