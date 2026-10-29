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
    public function optimize(Trip $trip, bool $traffic = false): array;

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

    /**
     * Route several stop lists at once (e.g. a day's trips) and draw them on one map.
     * Each route: stops (label/query, first = origin, last = destination), optimize flag, hex color.
     * With $traffic, drive times use live and typical traffic.
     *
     * @param  array<array-key, array{stops: list<array{label: string, query: string}>, optimize?: bool, color?: string}>  $routes
     * @return array{routes: array<array-key, array<string, mixed>>, image: string|null}
     */
    public function routes(array $routes, bool $traffic = false): array;

    /**
     * Map of vehicle positions. `$style` is "streets" or "satellite"; `$traffic` adds the traffic layer (streets only).
     * Returns null when no map provider is configured.
     *
     * @param  list<array{lat: float, lng: float, label: string, color: string}>  $markers
     */
    public function fleetImage(array $markers, string $style = 'streets', bool $traffic = false): ?string;

    /** Short place name for a coordinate (e.g. "SLEX, Calamba, Laguna"), or null. */
    public function placeName(float $lat, float $lng): ?string;
}
