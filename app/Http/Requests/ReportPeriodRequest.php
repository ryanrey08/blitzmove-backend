<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A reporting period (at most a year) with optional vehicle/driver filters. */
class ReportPeriodRequest extends FormRequest
{
    public const MAX_DAYS = 366;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', function (string $attribute, mixed $value, \Closure $fail) {
                $from = strtotime((string) $this->input('from'));
                if ($from && strtotime((string) $value) - $from >= self::MAX_DAYS * 86400) {
                    $fail('The period can be at most '.self::MAX_DAYS.' days.');
                }
            }],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')],
            'driver_id' => ['nullable', 'integer', Rule::exists('drivers', 'id')],
        ];
    }
}
