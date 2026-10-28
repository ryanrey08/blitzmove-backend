<?php

namespace App\Enums;

enum OrderStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /** Manual status changes allowed from the Orders screen. Trip events drive the rest. */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Scheduled, self::Cancelled],
            self::Scheduled => [self::Pending, self::InTransit, self::Cancelled],
            self::InTransit => [self::Delivered],
            self::Delivered => [],
            self::Cancelled => [self::Pending],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Orders that still need to be delivered. */
    public static function open(): array
    {
        return [self::Pending, self::Scheduled, self::InTransit];
    }
}
