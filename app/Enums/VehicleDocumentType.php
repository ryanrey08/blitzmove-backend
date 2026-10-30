<?php

namespace App\Enums;

enum VehicleDocumentType: string
{
    use HasOptions;

    case Registration = 'registration';
    case OfficialReceipt = 'official_receipt';
    case Insurance = 'insurance';
    case Permit = 'permit';
    case Inspection = 'inspection';
    case EmissionTest = 'emission_test';
    case Other = 'other';
}
