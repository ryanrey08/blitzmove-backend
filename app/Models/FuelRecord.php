<?php

namespace App\Models;

use App\Enums\FuelType;
use App\Enums\PaymentMethod;
use Database\Factories\FuelRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'vehicle_id', 'driver_id', 'trip_id', 'fueled_at', 'fuel_type', 'liters', 'price_per_liter',
    'total_cost', 'payment_method', 'odometer_km', 'station', 'receipt_number', 'reference_number', 'receipt_path',
    'notes', 'created_by',
])]
class FuelRecord extends Model
{
    /** @use HasFactory<FuelRecordFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'fueled_at' => 'datetime',
            'fuel_type' => FuelType::class,
            'liters' => 'decimal:2',
            'price_per_liter' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'odometer_km' => 'integer',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class)->withTrashed();
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
