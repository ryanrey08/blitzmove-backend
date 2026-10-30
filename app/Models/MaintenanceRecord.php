<?php

namespace App\Models;

use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use Database\Factories\MaintenanceRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'reference_number', 'vehicle_id', 'service_type', 'category', 'status', 'scheduled_date', 'service_date',
    'odometer_km', 'estimated_cost', 'actual_cost', 'service_provider', 'assigned_to', 'reminder_days',
    'description', 'parts', 'checklist', 'notes', 'started_at', 'completed_at', 'created_by',
])]
class MaintenanceRecord extends Model
{
    /** @use HasFactory<MaintenanceRecordFactory> */
    use HasFactory, SoftDeletes;

    /** A scheduled record due within this many days shows as "Due Soon". */
    public const DUE_SOON_DAYS = 7;

    protected $attributes = [
        'status' => 'scheduled',
    ];

    protected function casts(): array
    {
        return [
            'service_type' => MaintenanceType::class,
            'category' => MaintenanceCategory::class,
            'status' => MaintenanceStatus::class,
            'scheduled_date' => 'date',
            'service_date' => 'date',
            'odometer_km' => 'integer',
            'estimated_cost' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'reminder_days' => 'integer',
            'parts' => 'array',
            'checklist' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function files(): MorphMany
    {
        return $this->morphMany(DocumentFile::class, 'document')->orderBy('id');
    }

    /** scheduled → overdue / due_soon / scheduled by date; other statuses as stored. */
    public function displayStatus(): string
    {
        if ($this->status !== MaintenanceStatus::Scheduled || ! $this->scheduled_date) {
            return $this->status->value;
        }
        $today = today();

        return match (true) {
            $this->scheduled_date->lt($today) => 'overdue',
            $this->scheduled_date->lte($today->copy()->addDays(self::DUE_SOON_DAYS)) => 'due_soon',
            default => 'scheduled',
        };
    }

    public function partsTotal(): float
    {
        return round(collect($this->parts ?? [])->sum(fn ($p) => (float) ($p['quantity'] ?? 0) * (float) ($p['unit_cost'] ?? 0)), 2);
    }
}
