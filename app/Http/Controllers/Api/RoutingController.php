<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoutePlanRequest;
use App\Http\Requests\RoutePreviewRequest;
use App\Models\Trip;
use App\Services\Routing\RoutingProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RoutingController extends Controller
{
    public function __construct(private RoutingProvider $routing) {}

    public function status(): JsonResponse
    {
        return response()->json(['data' => [
            'configured' => $this->routing->isConfigured(),
            'provider' => $this->routing->name(),
        ]]);
    }

    /** Route (and optionally optimize) stops that are not saved yet, e.g. while creating a trip. */
    public function preview(RoutePreviewRequest $request): JsonResponse
    {
        $stops = array_map(fn ($stop) => ['label' => $stop['label'], 'query' => $stop['query'] ?? $stop['label']], $request->input('stops'));
        $optimize = $request->boolean('optimize');
        $legend = $request->boolean('legend_inset');

        $result = Cache::remember('routing:preview:v3:'.md5(json_encode([$stops, $optimize, $legend])), now()->addMinutes(10), fn () => $this->routing->preview($stops, $optimize, $legend));

        return response()->json(['data' => $result]);
    }

    public function optimize(Request $request, Trip $trip): JsonResponse
    {
        return response()->json(['data' => $this->routing->optimize($trip->load('stops'), $request->boolean('traffic'))]);
    }

    /** Route colors on the day map, in trip order. */
    private const COLORS = ['0284c7', '16a34a', 'f97316', '8b5cf6', 'db2777', '0d9488', 'ca8a04', '475569', 'e30613', '2563eb'];

    /**
     * Several trips on one map (Route Optimization dashboard). Each trip is routed in its saved
     * stop order, in a proposed order from `orders`, or optimized when `optimize` is set.
     * Nothing is saved here; applying an order goes through PUT trips/{trip}/stops/reorder.
     */
    public function plan(RoutePlanRequest $request): JsonResponse
    {
        $ids = array_map('intval', $request->input('trip_ids'));
        $trips = Trip::with('stops')->findMany($ids)->sortBy(fn (Trip $trip) => array_search($trip->id, $ids))->values();
        $proposed = $request->input('orders', []);

        $routes = [];
        $stopsByTrip = [];
        foreach ($trips as $i => $trip) {
            $stops = $trip->stops->values();
            if ($order = $proposed[$trip->id] ?? null) {
                if (collect($order)->map(fn ($id) => (int) $id)->sort()->values()->all() !== $stops->pluck('id')->sort()->values()->all()) {
                    throw BusinessRuleException::withField("orders.{$trip->id}", "The proposed order for {$trip->trip_number} must list each of its stops once.");
                }
                $byId = $stops->keyBy('id');
                $stops = collect($order)->map(fn ($id) => $byId[(int) $id])->values();
            }
            $stopsByTrip[$trip->id] = $stops;
            $routes[$trip->id] = [
                'stops' => $stops->map(fn ($stop) => ['label' => $stop->location_name, 'query' => $stop->address ?: $stop->location_name])->all(),
                'optimize' => $request->boolean('optimize') && $stops->count() >= 4,
                'color' => self::COLORS[$i % count(self::COLORS)],
            ];
        }

        $result = $this->routing->routes($routes, $request->boolean('traffic'));

        return response()->json(['data' => [
            'routes' => collect($result['routes'])->map(fn (array $route, $tripId) => [
                'trip_id' => (int) $tripId,
                'color' => '#'.$routes[$tripId]['color'],
                // Stop ids in driving order (the index-based order from the provider mapped back to stops).
                'stop_ids' => array_map(fn (int $index) => $stopsByTrip[$tripId][$index]->id, $route['order']),
                ...collect($route)->except('order')->all(),
            ])->values(),
            'image' => $result['image'],
        ]]);
    }
}
