<?php

namespace App\Http\Requests;

use App\Enums\StopStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TripStopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'order_id' => ['nullable', 'integer', Rule::exists('orders', 'id')->whereNull('deleted_at')],
            'estimated_arrival_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
            'position' => ['sometimes', 'integer', 'min:2'],
            'status' => ['sometimes', Rule::enum(StopStatus::class)],
        ];
    }
}
