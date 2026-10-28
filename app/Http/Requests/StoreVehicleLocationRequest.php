<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
            'trip_id' => ['nullable', 'integer', Rule::exists('trips', 'id')],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'speed_kph' => ['nullable', 'numeric', 'min:0', 'max:400'],
            'heading' => ['nullable', 'numeric', 'min:0', 'max:360'],
            'source' => ['nullable', 'string', 'max:60'],
            'recorded_at' => ['required', 'date', 'before_or_equal:now'],
        ];
    }
}
