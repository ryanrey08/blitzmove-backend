<?php

namespace App\Http\Requests;

use App\Enums\DriverStatus;
use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceType;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use Illuminate\Validation\Rule;

/** Reports → Trip, Driver, Maintenance, Utilization and Delivery Reports: the period plus each page's filters. */
class OperationsReportRequest extends ReportPeriodRequest
{
    /** Maintenance status as shown (scheduled work is Due Soon or Overdue by its date). */
    public const MAINTENANCE_STATUSES = ['scheduled', 'due_soon', 'overdue', 'in_progress', 'completed', 'cancelled'];

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'trip_status' => ['nullable', Rule::enum(TripStatus::class)],
            'driver_status' => ['nullable', Rule::enum(DriverStatus::class)],
            'vehicle_status' => ['nullable', Rule::enum(VehicleStatus::class)],
            'vehicle_type' => ['nullable', Rule::enum(VehicleType::class)],
            'order_status' => ['nullable', Rule::enum(OrderStatus::class)],
            'customer' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(self::MAINTENANCE_STATUSES)],
            'category' => ['nullable', Rule::enum(MaintenanceCategory::class)],
            'service_type' => ['nullable', Rule::enum(MaintenanceType::class)],
        ];
    }

    /** Validated filters without the period and blanks. */
    public function filters(): array
    {
        return array_filter(collect($this->validated())->except(['from', 'to'])->all(), fn ($v) => $v !== null && $v !== '');
    }
}
