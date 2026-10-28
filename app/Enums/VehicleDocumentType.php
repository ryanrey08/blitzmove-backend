<?php

namespace App\Enums;

enum VehicleDocumentType: string
{
    use HasOptions;

    case Registration = 'registration';
    case Insurance = 'insurance';
    case Permit = 'permit';
    case Inspection = 'inspection';
    case EmissionTest = 'emission_test';
    case Other = 'other';
}
