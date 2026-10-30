<?php

namespace Database\Factories;

use App\Enums\ReportType;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Report> */
class ReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Fleet Utilization Report',
            'type' => ReportType::Fleet,
            'period_from' => today()->startOfMonth(),
            'period_to' => today()->endOfMonth(),
            'filters' => [],
            'generated_by' => User::factory(),
        ];
    }
}
