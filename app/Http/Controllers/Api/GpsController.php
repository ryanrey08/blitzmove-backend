<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVehicleLocationRequest;
use App\Http\Resources\VehicleLocationResource;
use App\Http\Resources\VehicleResource;
use App\Models\Trip;
use App\Models\TripStop;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use App\Services\Routing\RoutingProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Exposes positions reported by a telematics integration. When no device has
 * reported, the API says so instead of producing positions.
 */
class GpsController extends Controller
{
    public function index(RoutingProvider $routing): JsonResponse
    {
        $vehicles = Vehicle::with(['latestLocation', 'assignedDriver', 'activeTrips.driver'])->orderBy('plate_number')->get();
        // Place names for last positions (reverse geocoded, cached) when a map provider is set up.
        $places = $routing->isConfigured()
            ? $vehicles->filter(fn (Vehicle $v) => $v->latestLocation)
                ->mapWithKeys(fn (Vehicle $v) => [$v->id => $routing->placeName($v->latestLocation->latitude, $v->latestLocation->longitude)])
            : collect();

        return response()->json([
            'data' => VehicleResource::collection($vehicles),
            'meta' => [
                'map_provider_configured' => filled(config('services.maps.provider')),
                'has_gps_data' => VehicleLocation::exists(),
                // max() returns the raw UTC column value; send ISO 8601 so clients don't read it as local time.
                'last_reported_at' => ($last = VehicleLocation::max('recorded_at')) ? Carbon::parse($last)->toIso8601String() : null,
                'places' => (object) $places->filter()->all(),
            ],
        ]);
    }

    /**
     * Fleet map: one pin per vehicle at its last reported position. The client chooses labels and
     * colors (status), but positions always come from the database.
     */
    public function map(Request $request, RoutingProvider $routing): JsonResponse
    {
        $data = $request->validate([
            'markers' => ['present', 'array', 'max:60'],
            'markers.*.vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'markers.*.label' => ['required', 'string', 'regex:/^[a-z0-9]{1,2}$/i'],
            'markers.*.color' => ['required', 'string', 'regex:/^#?[0-9a-f]{6}$/i'],
            'style' => ['sometimes', 'in:streets,satellite'],
            'traffic' => ['sometimes', 'boolean'],
        ]);

        $positions = VehicleLocation::query()
            ->whereIn('id', VehicleLocation::query()->selectRaw('max(id)')->whereIn('vehicle_id', array_column($data['markers'], 'vehicle_id'))->groupBy('vehicle_id'))
            ->get()->keyBy('vehicle_id');
        $markers = collect($data['markers'])->filter(fn ($m) => $positions->has($m['vehicle_id']))->map(fn ($m) => [
            'lat' => $positions[$m['vehicle_id']]->latitude,
            'lng' => $positions[$m['vehicle_id']]->longitude,
            'label' => $m['label'],
            'color' => $m['color'],
        ])->values()->all();

        return response()->json(['data' => [
            'image' => $routing->isConfigured() ? $routing->fleetImage($markers, $data['style'] ?? 'streets', (bool) ($data['traffic'] ?? false)) : null,
            'plotted' => count($markers),
        ]]);
    }

    /** Today's trip and stop events (dispatches, departures, stop arrivals, completions), newest first. */
    public function events(Request $request): JsonResponse
    {
        $since = now()->startOfDay();
        $trips = Trip::with('vehicle')->where(fn ($q) => $q->where('dispatched_at', '>=', $since)->orWhere('actual_departure_at', '>=', $since)->orWhere('actual_arrival_at', '>=', $since))->get();
        $stops = TripStop::with('trip.vehicle')->where('actual_arrival_at', '>=', $since)->whereHas('trip')->get();

        $events = collect();
        foreach ($trips as $trip) {
            $plate = $trip->vehicle?->plate_number;
            if ($trip->dispatched_at?->gte($since)) {
                $events->push(['at' => $trip->dispatched_at, 'type' => 'dispatched', 'text' => "{$trip->trip_number} dispatched to {$plate}"]);
            }
            if ($trip->actual_departure_at?->gte($since)) {
                $events->push(['at' => $trip->actual_departure_at, 'type' => 'departed', 'text' => "{$plate} departed {$trip->origin}"]);
            }
            if ($trip->actual_arrival_at?->gte($since)) {
                $events->push(['at' => $trip->actual_arrival_at, 'type' => 'completed', 'text' => "{$plate} completed {$trip->trip_number}"]);
            }
        }
        foreach ($stops as $stop) {
            $plate = $stop->trip->vehicle?->plate_number;
            $verb = $stop->status->value === 'skipped' ? 'skipped' : 'arrived at';
            $events->push(['at' => $stop->actual_arrival_at, 'type' => $stop->status->value === 'skipped' ? 'skipped' : 'arrived', 'text' => "{$plate} {$verb} {$stop->location_name} (Stop {$stop->sequence})"]);
        }

        return response()->json(['data' => $events->sortByDesc('at')->take(min(50, max(1, (int) $request->query('limit', 15))))->map(fn ($e) => [...$e, 'at' => $e['at']->toIso8601String()])->values()]);
    }

    public function history(Request $request, Vehicle $vehicle): AnonymousResourceCollection
    {
        return VehicleLocationResource::collection(
            $vehicle->locations()->latest('recorded_at')->limit(min(500, max(1, (int) $request->query('limit', 100))))->get()
        );
    }

    /** Recent positions for one vehicle plus a map of its track (when a map provider is configured). */
    public function track(Request $request, Vehicle $vehicle, RoutingProvider $routing): JsonResponse
    {
        $locations = $vehicle->locations()->latest('recorded_at')->limit(min(500, max(1, (int) $request->query('limit', 200))))->get();
        $points = $locations->reverse()->map(fn (VehicleLocation $l) => ['lat' => $l->latitude, 'lng' => $l->longitude])->values()->all();

        return response()->json([
            'data' => [
                'vehicle' => VehicleResource::make($vehicle->load(['latestLocation', 'assignedDriver', 'activeTrips.driver'])),
                'locations' => VehicleLocationResource::collection($locations),
                'image' => $routing->isConfigured() ? $routing->trackImage($points) : null,
            ],
            'meta' => ['map_provider_configured' => $routing->isConfigured()],
        ]);
    }

    public function store(StoreVehicleLocationRequest $request): JsonResponse
    {
        $location = VehicleLocation::create($request->validated());

        return VehicleLocationResource::make($location)->response()->setStatusCode(201);
    }
}
