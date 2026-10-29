<?php

namespace App\Services\Routing;

use App\Exceptions\BusinessRuleException;
use App\Models\Trip;

/** Bound when ROUTING_PROVIDER is empty. */
class NullRoutingProvider implements RoutingProvider
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function name(): ?string
    {
        return null;
    }

    public function preview(array $stops, bool $optimize = false, bool $legendInset = false): array
    {
        return $this->optimize(new Trip);
    }

    public function trackImage(array $points): ?string
    {
        return null;
    }

    public function routes(array $routes, bool $traffic = false): array
    {
        return $this->optimize(new Trip);
    }

    public function optimize(Trip $trip, bool $traffic = false): array
    {
        throw new BusinessRuleException(
            'No routing provider is configured. Set ROUTING_PROVIDER and its credentials to enable route optimisation.',
            status: 503,
        );
    }
}
