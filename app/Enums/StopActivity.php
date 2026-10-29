<?php

namespace App\Enums;

/** What happens at an intermediate stop: goods are collected or dropped off. */
enum StopActivity: string
{
    use HasOptions;

    case Pickup = 'pickup';
    case Delivery = 'delivery';
}
