<?php

namespace App\Enums;

enum UserStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';

    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }
}
