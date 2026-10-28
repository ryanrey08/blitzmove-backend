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
        ];
    }

    private function required(): string
    {
        return $this->isMethod('PATCH') ? 'sometimes' : 'required';
    }
}
