<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_name' => [$this->required(), 'string', 'max:255'],
            'customer_contact' => [$this->required(), 'string', 'max:60'],
            'pickup_address' => [$this->required(), 'string', 'max:255'],
            'delivery_address' => [$this->required(), 'string', 'max:255'],
            'scheduled_pickup_at' => [$this->required(), 'date'],
            'scheduled_delivery_at' => [$this->required(), 'date', 'after:scheduled_pickup_at'],
            'cargo_description' => [$this->required(), 'string', 'max:255'],
            'quantity' => [$this->required(), 'integer', 'min:1', 'max:1000000'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'delivery_instructions' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function required(): string
    {
        return $this->isMethod('PATCH') ? 'sometimes' : 'required';
    }
}
