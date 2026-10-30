<?php

namespace App\Http\Requests;

use App\Enums\ReportType;
use Illuminate\Validation\Rule;

class ReportRequest extends ReportPeriodRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(ReportType::class)],
            ...parent::rules(),
        ];
    }
}
