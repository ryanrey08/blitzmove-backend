<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Services\Routing\RoutingProvider;
use Illuminate\Http\JsonResponse;

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

    public function optimize(Trip $trip): JsonResponse
    {
        return response()->json(['data' => $this->routing->optimize($trip->load('stops'))]);
    }
}
