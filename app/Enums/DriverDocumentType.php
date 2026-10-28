<?php

namespace App\Enums;

enum DriverDocumentType: string
{
    use HasOptions;

    case License = 'license';
    case MedicalCertificate = 'medical_certificate';
    case NbiClearance = 'nbi_clearance';
    case DrugTest = 'drug_test';
    case TrainingCertificate = 'training_certificate';
    case Contract = 'contract';
    case Other = 'other';
}
