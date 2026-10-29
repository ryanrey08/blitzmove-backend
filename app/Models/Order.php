<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'order_number', 'customer_name', 'customer_contact', 'pickup_address', 'delivery_address',
    'scheduled_pickup_at', 'scheduled_delivery_at', 'vehicle_id', 'driver_id', 'cargo_description',
    'quantity', 'weight_kg', 'amount', 'payment_terms', 'delivery_instructions', 'status', 'delivered_at', 'cancelled_at',
    'cancellation_reason', 'created_by',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'scheduled_pickup_at' => 'datetime',
            'scheduled_delivery_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'quantity' => 'integer',
            'weight_kg' => 'decimal:2',
            'amount' => 'decimal:2',
            'status' => OrderStatus::class,
        ];
    }

    /** Open orders whose scheduled delivery time has passed. */
    public function scopeDelayed(Builder $query): Builder
    {
        return $query->whereIn('status', OrderStatus::open())->where('scheduled_delivery_at', '<', now());
    }

    public function isDelayed(): bool
    {
        return in_array($this->status, OrderStatus::open(), true) && $this->scheduled_delivery_at?->isPast();
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }
}
