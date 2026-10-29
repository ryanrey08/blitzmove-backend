<?php

namespace App\Services\Routing;

use App\Exceptions\BusinessRuleException;
use App\Models\Trip;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Mapbox Geocoding v6, Directions v5, Optimization v1 and Static Images.
 * Stops are geocoded from their address (or name); stops Mapbox cannot place
 * are reported back rather than guessed.
 */
class MapboxRoutingProvider implements RoutingProvider
{
    private const OPTIMIZATION_LIMIT = 12;

    private const DIRECTIONS_LIMIT = 25;

    public function isConfigured(): bool
    {
        return true;
    }

    public function name(): ?string
    {
        return 'mapbox';
    }

    public function optimize(Trip $trip): array
    {
        $stops = $trip->stops->values();
        $result = $this->preview(
            $stops->map(fn ($stop) => ['label' => $stop->location_name, 'query' => $stop->address ?: $stop->location_name])->all(),
            optimize: true,
        );

        if ($result['unresolved']) {
            throw new BusinessRuleException('Mapbox could not locate: '.implode(', ', $result['unresolved']).'. Add a fuller address to these stops and try again.');
        }

        return [
            'stop_ids' => array_map(fn (int $index) => $stops[$index]->id, $result['order']),
            'distance_km' => $result['distance_km'],
            'duration_minutes' => $result['duration_minutes'],
            'legs' => $result['legs'],
        ];
    }

    public function preview(array $stops, bool $optimize = false, bool $legendInset = false): array
    {
        $points = [];
        $unresolved = [];
        foreach (array_values($stops) as $index => $stop) {
            $place = $this->geocode($stop['query']);
            $points[] = [
                'index' => $index,
                'label' => $stop['label'],
                'lng' => $place['coordinates'][0] ?? null,
                'lat' => $place['coordinates'][1] ?? null,
                // What Mapbox matched, so a wrong match is visible to the user.
                'matched' => $place['address'] ?? null,
                'resolved' => $place !== null,
            ];
            if ($place === null) {
                $unresolved[] = $stop['label'];
            }
        }

        $resolved = array_values(array_filter($points, fn ($p) => $p['resolved']));
        $empty = ['points' => $points, 'order' => array_column($points, 'index'), 'distance_km' => null, 'duration_minutes' => null, 'legs' => [], 'image' => null, 'unresolved' => $unresolved];

        if (count($resolved) < 2) {
            return $empty;
        }

        // Only reorder when every intermediate stop could be placed; otherwise keep the user's order.
        if ($optimize && ! $unresolved && count($resolved) > 2) {
            if (count($resolved) > self::OPTIMIZATION_LIMIT) {
                throw new BusinessRuleException('Mapbox can optimize up to '.self::OPTIMIZATION_LIMIT.' stops at a time.');
            }
            $route = $this->optimizedTrip($resolved);
        } else {
            $route = $this->directions(array_slice($resolved, 0, self::DIRECTIONS_LIMIT));
        }

        $ordered = array_map(fn (int $i) => $resolved[$i], $route['sequence']);

        return [
            'points' => $points,
            'order' => $unresolved ? array_column($points, 'index') : array_column($ordered, 'index'),
            'distance_km' => round($route['distance'] / 1000, 1),
            'duration_minutes' => (int) round($route['duration'] / 60),
            'legs' => array_map(fn ($leg) => [
                'distance_km' => round($leg['distance'] / 1000, 1),
                'duration_minutes' => (int) round($leg['duration'] / 60),
            ], $route['legs']),
            'image' => $this->staticImage($ordered, $route['geometry'], $legendInset),
            'unresolved' => $unresolved,
        ];
    }

    /** @return array{coordinates: array{0: float, 1: float}, address: string|null}|null */
    private function geocode(string $query): ?array
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        $country = config('services.mapbox.country');

        $proximity = config('services.mapbox.proximity');

        return Cache::remember('mapbox:geocode:v2:'.md5($country.'|'.$proximity.'|'.mb_strtolower($query)), now()->addDays(30), function () use ($query, $country, $proximity) {
            $lookup = fn (string $q) => $this->http()->get('search/geocode/v6/forward', array_filter([
                'q' => $q,
                'limit' => 1,
                'country' => $country,
                'proximity' => $proximity,
            ]))->json('features.0');

            $feature = $lookup($query);

            // Street-level matches are often wrong for PH addresses (e.g. "Bldg 3 FTI Complex, Taguig City" -> "Barangay 3, Manila").
            // When the match isn't in the address's own city, fall back to the city/province part.
            $parts = array_values(array_filter(array_map('trim', explode(',', $query))));
            if (count($parts) >= 2) {
                $city = end($parts);
                $matched = (string) ($feature['properties']['full_address'] ?? '');
                $cityName = trim(preg_replace('/\b(city|province)\b/i', '', $city));
                if (! $feature || ($cityName !== '' && stripos($matched, $cityName) === false)) {
                    // Drop street parts (those with numbers): "Bldg 3 FTI Complex, Taguig City" -> "Taguig City".
                    $place = array_slice(array_values(array_filter($parts, fn ($part) => ! preg_match('/\d/', $part))), -2);
                    $feature = ($place ? $lookup(implode(', ', $place)) : null) ?? $feature;
                }
            }

            return $feature ? ['coordinates' => $feature['geometry']['coordinates'], 'address' => $feature['properties']['full_address'] ?? null] : null;
        });
    }

    /** @param list<array{lng: float, lat: float}> $points */
    private function directions(array $points): array
    {
        $response = $this->http()->get('directions/v5/mapbox/driving/'.$this->coordinates($points), [
            'geometries' => 'polyline',
            'overview' => 'simplified',
        ]);
        $route = $response->json('routes.0') ?? throw new BusinessRuleException('Mapbox could not find a driving route between these stops.');

        return [
            'sequence' => array_keys($points),
            'distance' => $route['distance'],
            'duration' => $route['duration'],
            'legs' => $route['legs'],
            'geometry' => $route['geometry'],
        ];
    }

    /** First stop stays the origin and last stays the destination. */
    private function optimizedTrip(array $points): array
    {
        $response = $this->http()->get('optimized-trips/v1/mapbox/driving/'.$this->coordinates($points), [
            'source' => 'first',
            'destination' => 'last',
            'roundtrip' => 'false',
            'geometries' => 'polyline',
            'overview' => 'simplified',
        ]);
        $trip = $response->json('trips.0') ?? throw new BusinessRuleException('Mapbox could not optimize a route between these stops.');

        // waypoint_index is each input point's position in the optimized trip.
        $positions = array_column($response->json('waypoints'), 'waypoint_index');
        asort($positions);
        // Pin the origin and destination; Mapbox may swap them with a stop at the same coordinates.
        $last = count($points) - 1;
        $middle = array_values(array_diff(array_keys($positions), [0, $last]));

        return [
            'sequence' => [0, ...$middle, $last],
            'distance' => $trip['distance'],
            'duration' => $trip['duration'],
            'legs' => $trip['legs'],
            'geometry' => $trip['geometry'],
        ];
    }

    /** PNG of the route with numbered pins, as a data URI (keeps the secret token off the client). */
    private function staticImage(array $ordered, ?string $polyline, bool $legendInset): ?string
    {
        $last = count($ordered) - 1;
        $pins = collect($ordered)->map(function ($point, $i) use ($last) {
            $color = $i === 0 ? '16a34a' : ($i === $last ? 'e30613' : '0284c7');
            $label = $i < 99 ? (string) ($i + 1) : '';

            return sprintf('pin-l-%s+%s(%F,%F)', $label, $color, $point['lng'], $point['lat']);
        })->implode(',');

        $overlay = $polyline ? 'path-4+e30613-0.85('.rawurlencode($polyline).'),'.$pins : $pins;
        if (strlen($overlay) > 7000) {
            $overlay = $pins;
        }

        // A missing picture shouldn't fail the whole preview, so this call doesn't throw.
        $response = $this->http(throw: false)->get('styles/v1/mapbox/streets-v12/static/'.$overlay.'/auto/640x520@2x', [
            'padding' => $legendInset ? '50,280,50,50' : '50',
        ]);

        return $response->successful() ? 'data:image/png;base64,'.base64_encode($response->body()) : null;
    }

    public function trackImage(array $points): ?string
    {
        if (! $points) {
            return null;
        }

        $last = end($points);
        $overlay = sprintf('pin-l-car+e30613(%F,%F)', $last['lng'], $last['lat']);
        if (count($points) > 1) {
            $start = $points[0];
            $overlay = sprintf('path-4+0284c7-0.8(%s),pin-s+16a34a(%F,%F),', rawurlencode($this->encodePolyline($points)), $start['lng'], $start['lat']).$overlay;
        }

        $position = count($points) > 1 ? 'auto' : sprintf('%F,%F,13', $last['lng'], $last['lat']);
        $response = $this->http(throw: false)->get("styles/v1/mapbox/streets-v12/static/{$overlay}/{$position}/640x420@2x", count($points) > 1 ? ['padding' => 50] : []);

        return $response->successful() ? 'data:image/png;base64,'.base64_encode($response->body()) : null;
    }

    /** Google/Mapbox encoded polyline (precision 5). */
    private function encodePolyline(array $points): string
    {
        $encoded = '';
        $previous = [0, 0];
        foreach ($points as $point) {
            foreach ([[(int) round($point['lat'] * 1e5), 0], [(int) round($point['lng'] * 1e5), 1]] as [$value, $axis]) {
                $delta = $value - $previous[$axis];
                $previous[$axis] = $value;
                $delta = $delta < 0 ? ~($delta << 1) : $delta << 1;
                while ($delta >= 0x20) {
                    $encoded .= chr((0x20 | ($delta & 0x1F)) + 63);
                    $delta >>= 5;
                }
                $encoded .= chr($delta + 63);
            }
        }

        return $encoded;
    }

    private function coordinates(array $points): string
    {
        return collect($points)->map(fn ($p) => sprintf('%F,%F', $p['lng'], $p['lat']))->implode(';');
    }

    private function http(bool $throw = true): PendingRequest
    {
        $request = Http::baseUrl('https://api.mapbox.com/')
            ->withQueryParameters(['access_token' => config('services.mapbox.token')])
            ->timeout(15);

        return $throw
            ? $request->throw(fn ($response) => throw new BusinessRuleException('Mapbox request failed ('.$response->status().'). Check the Mapbox token and try again.', status: 502))
            : $request;
    }
}
