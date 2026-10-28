<?php

namespace App\Models;

use App\Enums\DriverStatus;
use App\Enums\LicenseType;
use App\Enums\TripStatus;
use Database\Factories\DriverFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'employee_code', 'full_name', 'phone', 'email', 'address', 'license_number',
    'license_type', 'license_expiry', 'date_hired', 'status', 'notes',
])]
class Driver extends Model
{
    /** @use HasFactory<DriverFactory> */
    use HasFactory, SoftDeletes;

    protected $attributes = [
        'status' => 'available',
    ];

    protected function casts(): array
    {
        return [
            'license_type' => LicenseType::class,
            'license_expiry' => 'date',
            'date_hired' => 'date',
            'status' => DriverStatus::class,
        ];
    }

    public function assignedVehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class, 'assigned_driver_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function activeTrips(): HasMany
    {
        return $this->trips()->whereIn('status', TripStatus::active());
    }

    public function completedTrips(): HasMany
    {
        return $this->trips()->where('status', TripStatus::Completed);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }

    public function fuelRecords(): HasMany
    {
        return $this->hasMany(FuelRecord::class);
    }
}
