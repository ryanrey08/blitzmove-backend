<?php

namespace App\Http\Requests;

use App\Enums\FuelType;
use App\Enums\PaymentMethod;
use Illuminate\Validation\Rule;

/** Reports → Fuel Reports: the period and vehicle/driver filters plus fuel-record filters and a grouping. */
class FuelReportRequest extends ReportPeriodRequest
{
    public const GROUPS = ['vehicle', 'driver', 'date', 'fuel_type'];

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'fuel_type' => ['nullable', Rule::enum(FuelType::class)],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'station' => ['nullable', 'string', 'max:255'],
            'route_origin' => ['nullable', 'string', 'max:255', 'required_with:route_destination'],
            'route_destination' => ['nullable', 'string', 'max:255', 'required_with:route_origin'],
            'group_by' => ['nullable', Rule::in(self::GROUPS)],
        ];
    }
}
