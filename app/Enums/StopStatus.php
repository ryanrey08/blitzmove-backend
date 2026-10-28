<?php

namespace App\Enums;

enum StopStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Arrived = 'arrived';
    case Completed = 'completed';
    case Skipped = 'skipped';
}
