<?php

namespace App\Services\Routing;

use App\Models\Trip;

/**
 * Integration boundary for a route optimisation / directions provider
 * (e.g. Google Routes, Mapbox Optimization, OSRM). Implementations must return
 * real provider results; nothing here may estimate or invent travel times.
 */
interface RoutingProvider
{
    public function isConfigured(): bool;

    public function name(): ?string;

    /**
     * @return array{stop_ids: list<int>, distance_km: float, duration_minutes: int, legs: list<array<string, mixed>>}
     */
    public function optimize(Trip $trip): array;
}
