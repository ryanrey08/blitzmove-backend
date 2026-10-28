<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id' => ['nullable', 'integer', Rule::exists('orders', 'id')->whereNull('deleted_at')],
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
            'driver_id' => ['required', 'integer', Rule::exists('drivers', 'id')->whereNull('deleted_at')],
            'origin' => ['required', 'string', 'max:255'],
            'destination' => ['required', 'string', 'max:255'],
            'departure_at' => ['required', 'date'],
            'estimated_arrival_at' => ['required', 'date', 'after:departure_at'],
            'distance_km' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'stops' => ['sometimes', 'array', 'max:50'],
            'stops.*.location_name' => ['required', 'string', 'max:255'],
            'stops.*.address' => ['nullable', 'string', 'max:255'],
            'stops.*.order_id' => ['nullable', 'integer', Rule::exists('orders', 'id')->whereNull('deleted_at')],
            'stops.*.estimated_arrival_at' => ['nullable', 'date', 'after_or_equal:departure_at', 'before_or_equal:estimated_arrival_at'],
            'stops.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
