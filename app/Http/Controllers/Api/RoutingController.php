<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoutePreviewRequest;
use App\Models\Trip;
use App\Services\Routing\RoutingProvider;
use Illuminate\Http\JsonResponse;
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

    public function optimize(Trip $trip): JsonResponse
    {
        return response()->json(['data' => $this->routing->optimize($trip->load('stops'))]);
    }
}
