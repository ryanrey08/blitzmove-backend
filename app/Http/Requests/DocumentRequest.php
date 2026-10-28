<?php

namespace App\Http\Requests;

use App\Enums\DriverDocumentType;
use App\Enums\VehicleDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'owner_type' => [$this->isMethod('POST') ? 'required' : 'prohibited', Rule::in(['vehicle', 'driver'])],
            'owner_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'integer', Rule::exists($this->input('owner_type') === 'driver' ? 'drivers' : 'vehicles', 'id')->whereNull('deleted_at')],
            'document_type' => [$this->isMethod('POST') ? 'required' : 'sometimes', Rule::enum($this->documentTypeEnum())],
            'title' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:255'],
            'document_number' => ['nullable', 'string', 'max:80'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', ...($this->filled('issued_at') ? ['after_or_equal:issued_at'] : [])],
            'notes' => ['nullable', 'string', 'max:255'],
            'file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx'],
        ];
    }

    /** @return class-string */
    private function documentTypeEnum(): string
    {
        $owner = $this->input('owner_type') ?? $this->route('ownerType');

        return $owner === 'driver' ? DriverDocumentType::class : VehicleDocumentType::class;
    }
}
