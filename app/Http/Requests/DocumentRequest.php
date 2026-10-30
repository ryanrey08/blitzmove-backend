<?php

namespace App\Http\Requests;

use App\Enums\DriverDocumentType;
use App\Enums\VehicleDocumentType;
use App\Services\DocumentService;
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
            'issuing_authority' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:255'],
            'files' => ['nullable', 'array', 'max:'.DocumentService::MAX_FILES],
            'files.*' => self::FILE_RULES,
        ];
    }

    /** Rules for one uploaded document file (also used when adding a file on its own). */
    public const FILE_RULES = ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'];

    public const FILE_MESSAGES = [
        'max' => 'Each file can be up to 10 MB.',
        'mimes' => 'Files must be PDF, JPG or PNG.',
    ];

    public function messages(): array
    {
        return collect(self::FILE_MESSAGES)->mapWithKeys(fn ($message, $rule) => ["files.*.{$rule}" => $message])->all();
    }

    /** @return class-string */
    private function documentTypeEnum(): string
    {
        $owner = $this->input('owner_type') ?? $this->route('ownerType');

        return $owner === 'driver' ? DriverDocumentType::class : VehicleDocumentType::class;
    }
}
