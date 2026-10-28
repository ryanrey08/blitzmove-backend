<?php

namespace App\Models\Concerns;

trait HasExpiryStatus
{
    public const EXPIRING_SOON_DAYS = 30;

    /** valid | expiring_soon | expired | no_expiry */
    public function expiryStatus(): string
    {
        if (! $this->expires_at) {
            return 'no_expiry';
        }

        $today = now()->startOfDay();

        if ($this->expires_at->lt($today)) {
            return 'expired';
        }

        return $this->expires_at->lte($today->copy()->addDays(self::EXPIRING_SOON_DAYS))
            ? 'expiring_soon'
            : 'valid';
    }
}
