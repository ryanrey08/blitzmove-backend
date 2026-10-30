<?php

namespace App\Http\Requests;

use App\Enums\ReportType;
use Illuminate\Validation\Rule;

class ReportRequest extends ReportPeriodRequest
{
    /** Data sources a saved Custom Report can read (each is an existing list endpoint). */
    public const CUSTOM_SOURCES = ['trips', 'orders', 'maintenance', 'fuel', 'drivers', 'vehicles'];

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(ReportType::class)],
            ...parent::rules(),
            // Custom Reports: the saved configuration (source, columns, sort and list filters).
            'config' => ['nullable', 'array', 'required_if:type,custom', 'prohibited_unless:type,custom'],
            'config.source' => ['required_with:config', Rule::in(self::CUSTOM_SOURCES)],
            'config.columns' => ['required_with:config', 'array', 'min:1', 'max:40'],
            'config.columns.*' => ['string', 'max:60'],
            'config.sort' => ['nullable', 'string', 'max:60'],
            'config.search' => ['nullable', 'string', 'max:255'],
            'config.filters' => ['nullable', 'array', 'max:20'],
            'config.filters.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
