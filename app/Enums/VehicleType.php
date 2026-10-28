<?php

namespace App\Enums;

enum VehicleType: string
{
    use HasOptions;

    case ClosedVan = 'closed_van';
    case WingVan = 'wing_van';
    case CargoTruck = 'cargo_truck';
    case RefrigeratedTruck = 'refrigerated_truck';
    case Flatbed = 'flatbed';
    case Tanker = 'tanker';
    case Pickup = 'pickup';
    case Motorcycle = 'motorcycle';
}
