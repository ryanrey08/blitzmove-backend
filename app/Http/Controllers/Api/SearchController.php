<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Global header search across orders, trips, vehicles and drivers. */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        $like = "%{$term}%";
        $results = collect()
            ->merge(Order::where(fn ($q) => $q->where('order_number', 'like', $like)->orWhere('customer_name', 'like', $like))
                ->limit(5)->get()->map(fn (Order $o) => [
                    'type' => 'order', 'id' => $o->id, 'title' => $o->order_number,
                    'subtitle' => "{$o->customer_name} · {$o->status->label()}", 'url' => "/orders/{$o->id}",
                ]))
            ->merge(Trip::where(fn ($q) => $q->where('trip_number', 'like', $like)->orWhere('destination', 'like', $like))
                ->limit(5)->get()->map(fn (Trip $t) => [
                    'type' => 'trip', 'id' => $t->id, 'title' => $t->trip_number,
                    'subtitle' => "{$t->origin} → {$t->destination}", 'url' => "/transportation/itinerary?trip={$t->id}",
                ]))
            ->merge(Vehicle::where(fn ($q) => $q->where('plate_number', 'like', $like)->orWhere('make', 'like', $like)->orWhere('model', 'like', $like))
                ->limit(5)->get()->map(fn (Vehicle $v) => [
                    'type' => 'vehicle', 'id' => $v->id, 'title' => $v->plate_number,
                    'subtitle' => "{$v->make} {$v->model} · {$v->status->label()}", 'url' => "/fleet/vehicles/{$v->id}",
                ]))
            ->merge(Driver::where(fn ($q) => $q->where('full_name', 'like', $like)->orWhere('license_number', 'like', $like)->orWhere('phone', 'like', $like))
                ->limit(5)->get()->map(fn (Driver $d) => [
                    'type' => 'driver', 'id' => $d->id, 'title' => $d->full_name,
                    'subtitle' => "{$d->license_number} · {$d->status->label()}", 'url' => "/fleet/drivers?driver={$d->id}",
                ]));

        return response()->json(['data' => $results->values()]);
    }
}
