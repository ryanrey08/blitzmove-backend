<?php

namespace App\Enums;

/**
 * Stored status. "Due Soon" and "Overdue" are not stored: they are how a
 * scheduled record looks against today's date (see MaintenanceRecord::displayStatus()).
 */
enum MaintenanceStatus: string
{
    use HasOptions;

    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function open(): array
    {
        return [self::Scheduled->value, self::InProgress->value];
    }
}
