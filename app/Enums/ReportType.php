<?php

namespace App\Enums;

/** What a generated report is about ("Type" in the Report List). */
enum ReportType: string
{
    use HasOptions;

    case Fleet = 'fleet';
    case Trips = 'trips';
    case Fuel = 'fuel';
    case Driver = 'driver';
    case Maintenance = 'maintenance';
    case Geofence = 'geofence';
    case Delivery = 'delivery';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Fleet => 'Fleet Utilization',
            self::Trips => 'Trips',
            self::Delivery => 'Orders / Delivery',
            default => ucfirst($this->value),
        };
    }
}
