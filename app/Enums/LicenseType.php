<?php

namespace App\Enums;

enum LicenseType: string
{
    use HasOptions;

    case Professional = 'professional';
    case NonProfessional = 'non_professional';
}
