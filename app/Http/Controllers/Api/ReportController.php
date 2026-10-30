<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReportType;
use App\Http\Controllers\Concerns\ListsResources;
use App\Http\Controllers\Controller;
use App\Http\Requests\FuelReportRequest;
use App\Http\Requests\ReportPeriodRequest;
use App\Http\Requests\ReportRequest;
use App\Http\Resources\ReportResource;
use App\Models\Report;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    use ListsResources;

    public function __construct(private readonly ReportService $reports) {}

    /** Stat cards, daily distance/fuel series and trip status for Reports → Overview. */
    public function overview(ReportPeriodRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $this->reports->overview($data['from'], $data['to'], $this->filters($data))]);
    }

    /** Stat cards, charts and route options for Reports → Fuel Reports; the transactions come from /fuel-records. */
    public function fuel(FuelReportRequest $request): JsonResponse
    {
        $data = $request->validated();
        $filters = [
            ...$this->filters($data),
            ...array_filter(collect($data)->only(['fuel_type', 'payment_method', 'station', 'route_origin', 'route_destination'])->all()),
        ];

        return response()->json(['data' => $this->reports->fuelReport($data['from'], $data['to'], $filters, $data['group_by'] ?? 'vehicle')]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['type' => ['nullable', Rule::enum(ReportType::class)]]);
        $query = Report::query()->with('generator');
        $this->applySearch($query, $request, ['name', 'generator.name']);
        $this->applyFilters($query, $request, ['type']);
        $query->latest()->orderByDesc('id');

        return ReportResource::collection($query->paginate($this->perPage($request))->withQueryString());
    }

    /** "Generate Report": stores the parameters; figures are computed when the report is opened. */
    public function store(ReportRequest $request): JsonResponse
    {
        $data = $request->validated();
        $report = Report::create([
            'name' => $data['name'],
            'type' => $data['type'],
            'period_from' => $data['from'],
            'period_to' => $data['to'],
            'filters' => $this->filters($data),
            'generated_by' => $request->user()->id,
        ]);

        return ReportResource::make($report->load('generator'))->additional(['message' => 'Report generated.'])->response()->setStatusCode(201);
    }

    public function show(Report $report): JsonResponse
    {
        return response()->json([
            'data' => [...ReportResource::make($report->load('generator'))->resolve(), 'preview' => $this->reports->preview($report)],
        ]);
    }

    /** The author, or anyone who manages operations, can delete a report. */
    public function destroy(Request $request, Report $report): JsonResponse
    {
        abort_unless($report->generated_by === $request->user()->id || $request->user()->canManageOperations(), 403, 'You can only delete reports you generated.');
        $report->delete();

        return response()->json(['message' => 'Report deleted.']);
    }

    private function filters(array $data): array
    {
        return array_filter(['vehicle_id' => $data['vehicle_id'] ?? null, 'driver_id' => $data['driver_id'] ?? null]);
    }
}
