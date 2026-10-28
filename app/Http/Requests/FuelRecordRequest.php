<?php

namespace App\Http\Requests;

use App\Enums\FuelType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FuelRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => [$this->required(), 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
            'driver_id' => ['nullable', 'integer', Rule::exists('drivers', 'id')->whereNull('deleted_at')],
            'trip_id' => ['nullable', 'integer', Rule::exists('trips', 'id')->whereNull('deleted_at')],
            'fueled_at' => [$this->required(), 'date', 'before_or_equal:now'],
            'fuel_type' => [$this->required(), Rule::enum(FuelType::class)],
            'liters' => [$this->required(), 'numeric', 'gt:0', 'max:10000'],
            'price_per_liter' => [$this->required(), 'numeric', 'gt:0', 'max:10000'],
            'odometer_km' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'station' => ['nullable', 'string', 'max:255'],
            'receipt_number' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function required(): string
    {
        return $this->isMethod('PATCH') ? 'sometimes' : 'required';
    }
}
