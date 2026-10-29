<?php

namespace App\Http\Requests;

use App\Enums\TripStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTripStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(TripStatus::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Dispatch only: when it was released (defaults to now) and an optional last-minute reassignment.
            'dispatched_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(5)->toDateTimeString()],
            'vehicle_id' => ['nullable', 'required_with:driver_id', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
            'driver_id' => ['nullable', 'required_with:vehicle_id', 'integer', Rule::exists('drivers', 'id')->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return ['dispatched_at.before_or_equal' => 'The dispatch time cannot be in the future.'];
    }
}
