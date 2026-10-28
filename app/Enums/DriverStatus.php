<?php

namespace App\Enums;

enum DriverStatus: string
{
    use HasOptions;

    case Available = 'available';
    case OnTrip = 'on_trip';
    case OnLeave = 'on_leave';
    case Suspended = 'suspended';
    case Inactive = 'inactive';

    public function isAssignable(): bool
    {
        return in_array($this, [self::Available, self::OnTrip], true);
    }
}
