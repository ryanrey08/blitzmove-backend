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

    /**
     * Route an unsaved list of stops (first = origin, last = destination) and
     * render it as a map image. With $optimize, intermediate stops are reordered.
     * $legendInset keeps the right-hand part of the image clear for a stop list drawn over it.
     *
     * @param  list<array{label: string, query: string}>  $stops
     * @return array{points: list<array<string, mixed>>, order: list<int>, distance_km: float|null, duration_minutes: int|null, legs: list<array<string, mixed>>, image: string|null, unresolved: list<string>}
     */
    public function preview(array $stops, bool $optimize = false, bool $legendInset = false): array;

    /**
     * Map image of reported GPS positions, oldest first, or null when no map provider is configured.
     *
     * @param  list<array{lat: float, lng: float}>  $points
     */
    public function trackImage(array $points): ?string;
}
