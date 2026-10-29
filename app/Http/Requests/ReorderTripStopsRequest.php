<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderTripStopsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stop_ids' => ['present', 'array'],
            'stop_ids.*' => ['integer', 'distinct'],
            // True when the order came from the routing provider's optimization.
            'optimized' => ['sometimes', 'boolean'],
            'distance_km' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }
}
