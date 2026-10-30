<?php

namespace App\Http\Requests;

use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MaintenanceRecordRequest extends FormRequest
{
    /** Attachment rules (receipts, job orders, photos): same limits as fleet documents. */
    public const FILE_RULES = DocumentRequest::FILE_RULES;

    public const FILE_MESSAGES = DocumentRequest::FILE_MESSAGES;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reference_number')) {
            $this->merge(['reference_number' => strtoupper(trim((string) $this->input('reference_number'))) ?: null]);
        }
    }

    public function rules(): array
    {
        $record = $this->route('maintenance_record');

        return [
            'reference_number' => ['nullable', 'string', 'max:30', Rule::unique('maintenance_records', 'reference_number')->ignore($record)],
            'vehicle_id' => [$this->required(), 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
            'service_type' => [$this->required(), Rule::enum(MaintenanceType::class)],
            'category' => [$this->required(), Rule::enum(MaintenanceCategory::class)],
            'status' => [$this->required(), Rule::enum(MaintenanceStatus::class)],
            'scheduled_date' => [$this->required(), 'date'],
            // Work that is done needs the day it was done, and that day can't be in the future.
            'service_date' => ['nullable', 'date', 'before_or_equal:today', Rule::requiredIf(fn () => $this->input('status', $record?->status?->value) === MaintenanceStatus::Completed->value)],
            'odometer_km' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'actual_cost' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'service_provider' => ['nullable', 'string', 'max:255'],
            'assigned_to' => ['nullable', 'string', 'max:120'],
            'reminder_days' => ['nullable', 'integer', Rule::in([1, 3, 7, 14, 30])],
            'description' => ['nullable', 'string', 'max:500'],
            'parts' => ['nullable', 'array', 'max:50'],
            'parts.*.name' => ['required', 'string', 'max:120'],
            'parts.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'parts.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'checklist' => ['nullable', 'array', 'max:30'],
            'checklist.*.item' => ['required', 'string', 'max:100'],
            'checklist.*.done' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'service_date.required' => 'Enter the date the work was done to mark it completed.',
            'service_date.before_or_equal' => "The service date can't be in the future.",
            'reminder_days.in' => 'Pick a reminder from the list.',
        ];
    }

    private function required(): string
    {
        return $this->isMethod('PATCH') ? 'sometimes' : 'required';
    }
}
