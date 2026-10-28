<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderTripStopsRequest;
use App\Http\Requests\TripStopRequest;
use App\Http\Resources\TripStopResource;
use App\Models\Trip;
use App\Models\TripStop;
use App\Services\ItineraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TripStopController extends Controller
{
    public function __construct(private ItineraryService $itinerary) {}

    public function index(Trip $trip): AnonymousResourceCollection
    {
        return TripStopResource::collection($trip->stops()->with('order')->get());
    }

    public function store(TripStopRequest $request, Trip $trip): JsonResponse
    {
        $stop = $this->itinerary->addStop($trip, $request->validated());

        return TripStopResource::make($stop)->additional(['message' => 'Stop added.'])->response()->setStatusCode(201);
    }

    public function update(TripStopRequest $request, Trip $trip, TripStop $stop): TripStopResource
    {
        $this->itinerary->updateStop($stop, collect($request->validated())->except('position')->all());

        return TripStopResource::make($stop->fresh('order'))->additional(['message' => 'Stop updated.']);
    }

    public function destroy(Trip $trip, TripStop $stop): JsonResponse
    {
        $this->itinerary->removeStop($stop);

        return response()->json(['message' => 'Stop removed.']);
    }

    public function reorder(ReorderTripStopsRequest $request, Trip $trip): AnonymousResourceCollection
    {
        $this->itinerary->reorder($trip, $request->input('stop_ids', []));

        return TripStopResource::collection($trip->stops()->with('order')->get())->additional(['message' => 'Stop order saved.']);
    }
}
