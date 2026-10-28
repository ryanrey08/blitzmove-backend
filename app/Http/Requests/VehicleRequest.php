<?php

namespace App\Http\Requests;

use App\Enums\FuelType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plate_number' => [$this->required(), 'string', 'max:20', Rule::unique('vehicles')->ignore($this->route('vehicle'))],
            'vehicle_type' => [$this->required(), Rule::enum(VehicleType::class)],
            'make' => [$this->required(), 'string', 'max:60'],
            'model' => [$this->required(), 'string', 'max:60'],
            'year' => [$this->required(), 'integer', 'min:1980', 'max:'.(now()->year + 1)],
            'capacity_kg' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'fuel_type' => [$this->required(), Rule::enum(FuelType::class)],
            'vin' => ['nullable', 'string', 'max:50', Rule::unique('vehicles')->ignore($this->route('vehicle'))],
            'status' => ['sometimes', Rule::enum(VehicleStatus::class)],
            'assigned_driver_id' => ['nullable', 'integer', Rule::exists('drivers', 'id')->whereNull('deleted_at'), Rule::unique('vehicles')->ignore($this->route('vehicle'))->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['assigned_driver_id.unique' => 'This driver is already assigned to another vehicle.'];
    }

    private function required(): string
    {
        return $this->isMethod('PATCH') ? 'sometimes' : 'required';
    }
}
