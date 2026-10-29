<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ListsResources;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderTripStopsRequest;
use App\Http\Requests\TripStopRequest;
use App\Http\Resources\TripStopResource;
use App\Models\Trip;
use App\Models\TripStop;
use App\Services\ItineraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class TripStopController extends Controller
{
    use ListsResources;

    public function __construct(private ItineraryService $itinerary) {}

    /**
     * Stops across all trips (the Itinerary board). A stop's date is its ETA,
     * or its trip's departure when no ETA is set.
     */
    public function board(Request $request): AnonymousResourceCollection
    {
        $query = TripStop::query()
            ->select('trip_stops.*')
            ->join('trips', 'trips.id', '=', 'trip_stops.trip_id')
            ->whereNull('trips.deleted_at')
            ->with(['order', 'trip.vehicle', 'trip.driver']);

        // from/to are dates or ISO datetimes (the UI sends the viewer's local day bounds).
        $when = 'COALESCE(trip_stops.estimated_arrival_at, trips.departure_at)';
        $bound = fn (string $value, bool $end) => strlen($value) <= 10
            ? ($end ? Carbon::parse($value)->endOfDay() : Carbon::parse($value)->startOfDay())
            : Carbon::parse($value)->utc();
        if ($from = $request->query('from')) {
            $query->whereRaw("{$when} >= ?", [$bound($from, false)->toDateTimeString()]);
        }
        if ($to = $request->query('to')) {
            $query->whereRaw("{$when} <= ?", [$bound($to, true)->toDateTimeString()]);
        }
        $this->applyFilters($query, $request, ['status' => 'trip_stops.status', 'type' => 'trip_stops.type', 'trip_status' => 'trips.status', 'trip_id' => 'trip_stops.trip_id']);

        if ($term = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q
                ->where('trip_stops.location_name', 'like', "%{$term}%")
                ->orWhere('trip_stops.address', 'like', "%{$term}%")
                ->orWhere('trips.trip_number', 'like', "%{$term}%")
                ->orWhereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$term}%")->orWhere('customer_name', 'like', "%{$term}%"))
                ->orWhereHas('trip.driver', fn ($d) => $d->where('full_name', 'like', "%{$term}%")));
        }

        $direction = $request->query('sort') === '-eta' ? 'desc' : 'asc';
        $query->orderByRaw("COALESCE(trip_stops.estimated_arrival_at, trips.departure_at) {$direction}")
            ->orderBy('trip_stops.trip_id')->orderBy('trip_stops.sequence');

        return TripStopResource::collection($query->paginate($this->perPage($request, 15))->withQueryString());
    }

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
