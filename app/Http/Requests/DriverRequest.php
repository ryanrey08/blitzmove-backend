<?php

namespace App\Http\Requests;

use App\Enums\DriverStatus;
use App\Enums\LicenseType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => [$this->required(), 'string', 'max:255'],
            'phone' => [$this->required(), 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('drivers')->ignore($this->route('driver'))],
            'address' => ['nullable', 'string', 'max:255'],
            'license_number' => [$this->required(), 'string', 'max:50', Rule::unique('drivers')->ignore($this->route('driver'))],
            'license_type' => [$this->required(), Rule::enum(LicenseType::class)],
            'license_expiry' => [$this->required(), 'date'],
            'date_hired' => ['nullable', 'date', 'before_or_equal:today'],
            'status' => ['sometimes', Rule::enum(DriverStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:'.now()->subYears(18)->toDateString()],
            'license_restrictions' => ['nullable', 'string', 'max:100'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'required_with:emergency_contact_name', 'string', 'max:30'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:50'],
            // The vehicle this driver regularly drives (stored on vehicles.assigned_driver_id).
            'assigned_vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return ['date_of_birth.before_or_equal' => 'Drivers must be at least 18 years old.'];
    }

    private function required(): string
    {
        return $this->isMethod('PATCH') ? 'sometimes' : 'required';
    }
}
