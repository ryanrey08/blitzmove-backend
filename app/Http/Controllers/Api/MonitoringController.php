<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MonitoringService;
use Illuminate\Http\JsonResponse;

class MonitoringController extends Controller
{
    public function __invoke(MonitoringService $monitoring): JsonResponse
    {
        return response()->json(['data' => $monitoring->overview()]);
    }
}
