<?php

namespace App\Enums;

enum TripStatus: string
{
    use HasOptions;

    case Scheduled = 'scheduled';
    case Dispatched = 'dispatched';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Scheduled => [self::Dispatched, self::Cancelled],
            self::Dispatched => [self::InProgress, self::Scheduled, self::Cancelled],
            self::InProgress => [self::Completed],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Trips that occupy a vehicle and driver. */
    public static function blocking(): array
    {
        return [self::Scheduled, self::Dispatched, self::InProgress];
    }

    /** Trips that are on the road or released to the driver. */
    public static function active(): array
    {
        return [self::Dispatched, self::InProgress];
    }

    public function isReassignable(): bool
    {
        return in_array($this, [self::Scheduled, self::Dispatched], true);
    }
}
