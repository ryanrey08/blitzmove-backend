<?php

namespace App\Enums;

enum FuelType: string
{
    use HasOptions;

    case Diesel = 'diesel';
    case Gasoline = 'gasoline';
    case Electric = 'electric';
    case Lpg = 'lpg';
}
