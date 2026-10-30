<?php

namespace App\Services\Routing;

/**
 * Center and zoom of a static Web Mercator map (512 px tiles, as Mapbox Static Images uses), so a
 * client can place its own markers over the picture at the right pixels and zoom it in steps.
 */
class MapViewport
{
    public const TILE = 512;

    public const MIN_ZOOM = 1;

    public const MAX_ZOOM = 17;

    /** Zoom used when there is a single point to show. */
    public const SINGLE_ZOOM = 13;

    /**
     * Smallest view that fits every point inside the image minus $padding (top, right, bottom, left pixels,
     * e.g. where the client draws controls over the map), or the given fallback center.
     *
     * @param  list<array{lat: float, lng: float}>  $points
     * @param  array{lng: float, lat: float}  $fallback
     * @param  array{0: int, 1: int, 2: int, 3: int}  $padding
     * @return array{lng: float, lat: float, zoom: float, width: int, height: int}
     */
    public static function fit(array $points, int $width, int $height, array $fallback, float $fallbackZoom = 8.5, array $padding = [60, 60, 60, 60]): array
    {
        [$top, $right, $bottom, $left] = $padding;
        if (! $points) {
            return self::make($fallback['lng'], $fallback['lat'], $fallbackZoom, $width, $height);
        }

        $xs = array_map(fn ($p) => self::x($p['lng']), $points);
        $ys = array_map(fn ($p) => self::y($p['lat']), $points);
        $dx = max($xs) - min($xs);
        $dy = max($ys) - min($ys);
        $zoom = $dx == 0 && $dy == 0
            ? self::SINGLE_ZOOM
            : min(
                $dx > 0 ? log(max(1, $width - $left - $right) / $dx, 2) : self::MAX_ZOOM,
                $dy > 0 ? log(max(1, $height - $top - $bottom) / $dy, 2) : self::MAX_ZOOM,
                15,
            );
        $zoom = max(self::MIN_ZOOM, min(self::MAX_ZOOM, $zoom));

        // Put the points' middle in the middle of the padded area, not of the whole image.
        $scale = 2 ** $zoom;
        $x = (max($xs) + min($xs)) / 2 - ($left - $right) / 2 / $scale;
        $y = (max($ys) + min($ys)) / 2 - ($top - $bottom) / 2 / $scale;

        return self::make(self::lng($x), self::lat($y), $zoom, $width, $height);
    }

    /** @return array{lng: float, lat: float, zoom: float, width: int, height: int} */
    public static function make(float $lng, float $lat, float $zoom, int $width, int $height): array
    {
        return [
            'lng' => round(max(-180, min(180, $lng)), 6),
            'lat' => round(max(-85, min(85, $lat)), 6),
            'zoom' => round(max(self::MIN_ZOOM, min(self::MAX_ZOOM, $zoom)), 2),
            'width' => $width,
            'height' => $height,
        ];
    }

    /** World x at zoom 0, in pixels. */
    private static function x(float $lng): float
    {
        return ($lng + 180) / 360 * self::TILE;
    }

    /** World y at zoom 0, in pixels. */
    private static function y(float $lat): float
    {
        $rad = deg2rad(max(-85, min(85, $lat)));

        return (1 - log(tan($rad) + 1 / cos($rad)) / M_PI) / 2 * self::TILE;
    }

    private static function lng(float $x): float
    {
        return $x / self::TILE * 360 - 180;
    }

    private static function lat(float $y): float
    {
        return rad2deg(atan(sinh(M_PI * (1 - 2 * $y / self::TILE))));
    }
}
