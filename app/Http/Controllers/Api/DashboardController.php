<?php

namespace App\Http\Controllers\Api;

use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\TripResource;
use App\Models\Order;
use App\Models\Trip;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): JsonResponse
    {
        $days = in_array((int) $request->query('days'), [7, 14, 30], true) ? (int) $request->query('days') : 14;

        return response()->json(['data' => [
            ...$dashboard->summary($days),
            'recent_orders' => OrderResource::collection(Order::with(['vehicle', 'driver'])->latest()->limit(6)->get()),
            'active_trips' => TripResource::collection(
                Trip::with(['vehicle', 'driver', 'order'])->whereIn('status', TripStatus::active())->orderBy('estimated_arrival_at')->limit(6)->get()
            ),
        ]]);
    }
}
