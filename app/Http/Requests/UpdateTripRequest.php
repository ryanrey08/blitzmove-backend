<?php

namespace App\Http\Requests;

use App\Enums\StopActivity;
use App\Enums\TripPriority;
use App\Enums\TripType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reference_no' => ['nullable', 'string', 'max:100'],
            'trip_type' => ['sometimes', Rule::enum(TripType::class)],
            'priority' => ['sometimes', Rule::enum(TripPriority::class)],
            'vehicle_id' => ['sometimes', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
            'driver_id' => ['sometimes', 'integer', Rule::exists('drivers', 'id')->whereNull('deleted_at')],
            'co_driver_id' => ['nullable', 'integer', Rule::exists('drivers', 'id')->whereNull('deleted_at')],
            'origin' => ['sometimes', 'string', 'max:255'],
            'destination' => ['sometimes', 'string', 'max:255'],
            'departure_at' => ['sometimes', 'date'],
            'estimated_arrival_at' => ['sometimes', 'date'],
            'distance_km' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // When present, replaces the stops between origin and destination.
            'stops' => ['sometimes', 'array', 'max:50'],
            'stops.*.location_name' => ['required', 'string', 'max:255'],
            'stops.*.activity' => ['nullable', Rule::enum(StopActivity::class)],
            'stops.*.address' => ['nullable', 'string', 'max:255'],
            'stops.*.order_id' => ['nullable', 'integer', Rule::exists('orders', 'id')->whereNull('deleted_at')],
            'stops.*.estimated_arrival_at' => ['nullable', 'date'],
            'stops.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
