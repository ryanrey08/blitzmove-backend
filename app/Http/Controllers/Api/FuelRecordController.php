<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ListsResources;
use App\Http\Controllers\Controller;
use App\Http\Requests\FuelRecordRequest;
use App\Http\Resources\FuelRecordResource;
use App\Models\FuelRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FuelRecordController extends Controller
{
    use ListsResources;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->filtered($request)->with(['vehicle', 'driver', 'trip']);
        $this->applySort($query, $request, ['fueled_at', 'liters', 'total_cost', 'created_at'], '-fueled_at');

        return FuelRecordResource::collection($query->paginate($this->perPage($request))->withQueryString());
    }

    /** Totals for the same filters as the list. */
    public function summary(Request $request): JsonResponse
    {
        $totals = $this->filtered($request)->toBase()
            ->selectRaw('count(*) as entries, coalesce(sum(liters), 0) as liters, coalesce(sum(total_cost), 0) as cost')
            ->first();

        return response()->json(['data' => [
            'entries' => (int) $totals->entries,
            'total_liters' => round((float) $totals->liters, 2),
            'total_cost' => round((float) $totals->cost, 2),
            'average_price_per_liter' => $totals->liters > 0 ? round($totals->cost / $totals->liters, 2) : null,
        ]]);
    }

    public function store(FuelRecordRequest $request): JsonResponse
    {
        $record = FuelRecord::create([...$this->withTotal($request->validated()), 'created_by' => $request->user()->id]);

        return FuelRecordResource::make($record->load(['vehicle', 'driver', 'trip']))
            ->additional(['message' => 'Fuel record saved.'])
            ->response()->setStatusCode(201);
    }

    public function show(FuelRecord $fuelRecord): FuelRecordResource
    {
        return FuelRecordResource::make($fuelRecord->load(['vehicle', 'driver', 'trip']));
    }

    public function update(FuelRecordRequest $request, FuelRecord $fuelRecord): FuelRecordResource
    {
        $fuelRecord->fill($request->validated());
        $fuelRecord->total_cost = round($fuelRecord->liters * $fuelRecord->price_per_liter, 2);
        $fuelRecord->save();

        return FuelRecordResource::make($fuelRecord->load(['vehicle', 'driver', 'trip']))->additional(['message' => 'Fuel record updated.']);
    }

    public function destroy(FuelRecord $fuelRecord): JsonResponse
    {
        $fuelRecord->delete();

        return response()->json(['message' => 'Fuel record deleted.']);
    }

    private function filtered(Request $request): Builder
    {
        $query = FuelRecord::query();
        $this->applySearch($query, $request, ['station', 'receipt_number', 'vehicle.plate_number', 'driver.full_name']);
        $this->applyFilters($query, $request, ['vehicle_id', 'driver_id', 'fuel_type']);

        if ($from = $request->query('from')) {
            $query->where('fueled_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('fueled_at', '<=', $to.' 23:59:59');
        }

        return $query;
    }

    private function withTotal(array $data): array
    {
        return [...$data, 'total_cost' => round($data['liters'] * $data['price_per_liter'], 2)];
    }
}
