<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoutePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'trip_ids' => ['required', 'array', 'min:1', 'max:10'],
            'trip_ids.*' => ['integer', 'distinct', Rule::exists('trips', 'id')->whereNull('deleted_at')],
            // Optional proposed stop order per trip (all of the trip's stop ids), keyed by trip id.
            'orders' => ['sometimes', 'array'],
            'orders.*' => ['array'],
            'orders.*.*' => ['integer'],
            'optimize' => ['sometimes', 'boolean'],
            'traffic' => ['sometimes', 'boolean'],
        ];
    }
}
