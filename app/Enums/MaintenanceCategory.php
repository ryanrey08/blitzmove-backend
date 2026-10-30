<?php

namespace App\Enums;

enum MaintenanceCategory: string
{
    use HasOptions;

    case Preventive = 'preventive';
    case Corrective = 'corrective';
    case Inspection = 'inspection';
    case Emergency = 'emergency';
}
