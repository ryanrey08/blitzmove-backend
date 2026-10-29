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
            'color' => ['nullable', 'string', 'max:30'],
            'body_type' => ['nullable', Rule::in(self::BODY_TYPES)],
            'wheels' => ['nullable', 'integer', Rule::in([4, 6, 8, 10, 12, 14, 16, 18, 22])],
            'engine_number' => ['nullable', 'string', 'max:50'],
            'home_depot' => ['nullable', 'string', 'max:100'],
            'gps_device_id' => ['nullable', 'string', 'max:50', Rule::unique('vehicles')->ignore($this->route('vehicle'))],
            'odometer_km' => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'acquired_at' => ['nullable', 'date', 'before_or_equal:today'],
            'cargo_volume_m3' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'length_m' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'width_m' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'height_m' => ['nullable', 'numeric', 'min:0', 'max:6'],
            'fuel_tank_l' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'fuel_economy_kml' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'emission_standard' => ['nullable', Rule::in(['Euro 2', 'Euro 3', 'Euro 4', 'Euro 5', 'Euro 6'])],
            'features' => ['nullable', 'array'],
            'features.*' => [Rule::in(self::FEATURES)],
            // Saved as the vehicle's registration / insurance documents (Fleet > Documents).
            'registration_expiry' => ['nullable', 'date'],
            'insurance_expiry' => ['nullable', 'date'],
        ];
    }

    public const BODY_TYPES = ['closed_van', 'wing_van', 'dropside', 'open_cargo', 'flatbed', 'refrigerated', 'tanker', 'dump', 'other'];

    public const FEATURES = ['gps_tracker', 'dash_camera', 'ref_cooler', 'tail_lift', 'auxiliary_power'];

    public function messages(): array
    {
        return ['assigned_driver_id.unique' => 'This driver is already assigned to another vehicle.'];
    }

    private function required(): string
    {
        return $this->isMethod('PATCH') ? 'sometimes' : 'required';
    }
}
