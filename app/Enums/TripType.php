<?php

namespace App\Enums;

enum TripType: string
{
    use HasOptions;

    case Delivery = 'delivery';
    case Pickup = 'pickup';
    case Transfer = 'transfer';
    case Return = 'return';
}
