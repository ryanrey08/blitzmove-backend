<?php

namespace App\Enums;

enum StopType: string
{
    use HasOptions;

    case Pickup = 'pickup';
    case Stop = 'stop';
    case Dropoff = 'dropoff';
}
