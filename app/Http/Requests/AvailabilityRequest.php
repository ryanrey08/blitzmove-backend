<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'departure_at' => ['required', 'date'],
            'estimated_arrival_at' => ['required', 'date', 'after:departure_at'],
            'exclude_trip_id' => ['nullable', 'integer'],
        ];
    }
}
