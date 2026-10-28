<?php

namespace App\Enums;

enum VehicleStatus: string
{
    use HasOptions;

    case Available = 'available';
    case OnTrip = 'on_trip';
    case Maintenance = 'maintenance';
    case Inactive = 'inactive';

    public function isAssignable(): bool
    {
        return in_array($this, [self::Available, self::OnTrip], true);
    }
}
