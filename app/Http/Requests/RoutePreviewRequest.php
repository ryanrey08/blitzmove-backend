<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RoutePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stops' => ['required', 'array', 'min:2', 'max:25'],
            'stops.*.label' => ['required', 'string', 'max:255'],
            'stops.*.query' => ['nullable', 'string', 'max:500'],
            'optimize' => ['sometimes', 'boolean'],
            'legend_inset' => ['sometimes', 'boolean'],
        ];
    }
}
