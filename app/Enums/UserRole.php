<?php

namespace App\Enums;

enum UserRole: string
{
    use HasOptions;

    case Admin = 'admin';
    case Manager = 'manager';
    case Dispatcher = 'dispatcher';
    case Viewer = 'viewer';

    public function canManageOperations(): bool
    {
        return $this !== self::Viewer;
    }

    public function canManageFleet(): bool
    {
        return in_array($this, [self::Admin, self::Manager], true);
    }
}
