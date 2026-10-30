<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ListsResources;
use App\Http\Controllers\Controller;
use App\Http\Requests\FuelRecordRequest;
use App\Http\Resources\FuelRecordResource;
use App\Models\FuelRecord;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $record = DB::transaction(function () use ($request) {
            $record = FuelRecord::create([...$this->withTotal($request->validated()), 'created_by' => $request->user()->id]);
            $this->syncOdometer($record);

            return $record;
        });

        return FuelRecordResource::make($record->load(['vehicle', 'driver', 'trip']))
            ->additional(['message' => 'Fuel transaction saved.'])
            ->response()->setStatusCode(201);
    }

    public function show(FuelRecord $fuelRecord): FuelRecordResource
    {
        return FuelRecordResource::make($fuelRecord->load(['vehicle.photos', 'driver', 'trip']));
    }

    public function update(FuelRecordRequest $request, FuelRecord $fuelRecord): FuelRecordResource
    {
        $fuelRecord->fill($request->validated());
        $fuelRecord->total_cost = round($fuelRecord->liters * $fuelRecord->price_per_liter, 2);
        DB::transaction(function () use ($fuelRecord) {
            $fuelRecord->save();
            $this->syncOdometer($fuelRecord);
        });

        return FuelRecordResource::make($fuelRecord->load(['vehicle', 'driver', 'trip']))->additional(['message' => 'Fuel transaction updated.']);
    }

    /** Stations used before, most frequent first, for the station pick-list. */
    public function stations(): JsonResponse
    {
        $stations = FuelRecord::query()->whereNotNull('station')->where('station', '!=', '')
            ->groupBy('station')->orderByRaw('count(*) desc')->orderBy('station')->limit(50)->pluck('station');

        return response()->json(['data' => $stations]);
    }

    public function storeReceipt(Request $request, FuelRecord $fuelRecord): JsonResponse
    {
        $request->validate(
            ['receipt' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120']],
            ['receipt.max' => 'Receipt photos can be up to 5 MB.', 'receipt.mimes' => 'Receipt photos must be JPG or PNG.'],
        );
        $previous = $fuelRecord->receipt_path;
        $fuelRecord->update(['receipt_path' => $request->file('receipt')->store("fuel-receipts/{$fuelRecord->id}", 'local')]);
        if ($previous) {
            Storage::disk('local')->delete($previous);
        }

        return response()->json(['data' => ['receipt' => "fuel-records/{$fuelRecord->id}/receipt"], 'message' => 'Receipt photo uploaded.']);
    }

    public function receipt(FuelRecord $fuelRecord): StreamedResponse
    {
        abort_unless($fuelRecord->receipt_path && Storage::disk('local')->exists($fuelRecord->receipt_path), 404);

        return Storage::disk('local')->response($fuelRecord->receipt_path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    public function destroyReceipt(FuelRecord $fuelRecord): JsonResponse
    {
        if ($fuelRecord->receipt_path) {
            Storage::disk('local')->delete($fuelRecord->receipt_path);
            $fuelRecord->update(['receipt_path' => null]);
        }

        return response()->json(['message' => 'Receipt photo removed.']);
    }

    public function destroy(FuelRecord $fuelRecord): JsonResponse
    {
        $fuelRecord->delete();

        return response()->json(['message' => 'Fuel record deleted.']);
    }

    private function filtered(Request $request): Builder
    {
        $query = FuelRecord::query();
        $this->applySearch($query, $request, ['station', 'receipt_number', 'reference_number', 'vehicle.plate_number', 'driver.full_name']);
        $this->applyFilters($query, $request, ['vehicle_id', 'driver_id', 'fuel_type']);

        if ($from = $request->query('from')) {
            $query->where('fueled_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('fueled_at', '<=', $to.' 23:59:59');
        }

        return $query;
    }

    /** A higher odometer reading moves the vehicle's odometer forward; older readings never wind it back. */
    private function syncOdometer(FuelRecord $record): void
    {
        if ($record->odometer_km) {
            Vehicle::whereKey($record->vehicle_id)
                ->where(fn ($q) => $q->whereNull('odometer_km')->orWhere('odometer_km', '<', $record->odometer_km))
                ->update(['odometer_km' => $record->odometer_km]);
        }
    }

    private function withTotal(array $data): array
    {
        return [...$data, 'total_cost' => round($data['liters'] * $data['price_per_liter'], 2)];
    }
}
