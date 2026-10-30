<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReportType;
use App\Http\Controllers\Concerns\ListsResources;
use App\Http\Controllers\Controller;
use App\Http\Requests\FuelReportRequest;
use App\Http\Requests\OperationsReportRequest;
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

    /** Reports → Trip Reports: stat cards, trips per day and status; the rows come from /trips. */
    public function trips(OperationsReportRequest $request): JsonResponse
    {
        return $this->operations($request, 'tripReport', ['vehicle_id', 'driver_id', 'trip_status']);
    }

    /** Reports → Driver Reports: one row per driver plus totals. */
    public function drivers(OperationsReportRequest $request): JsonResponse
    {
        return $this->operations($request, 'driverReport', ['vehicle_id', 'driver_id', 'driver_status']);
    }

    /** Reports → Maintenance Reports: totals and breakdowns; the rows come from /maintenance-records. */
    public function maintenance(OperationsReportRequest $request): JsonResponse
    {
        return $this->operations($request, 'maintenanceReport', ['vehicle_id', 'status', 'category', 'service_type']);
    }

    /** Reports → Utilization Reports: one row per vehicle plus fleet totals. */
    public function utilization(OperationsReportRequest $request): JsonResponse
    {
        return $this->operations($request, 'utilizationReport', ['vehicle_id', 'driver_id', 'vehicle_type', 'vehicle_status']);
    }

    /** Reports → Delivery Reports: totals and breakdowns; the rows come from /orders. */
    public function deliveries(OperationsReportRequest $request): JsonResponse
    {
        return $this->operations($request, 'deliveryReport', ['vehicle_id', 'driver_id', 'order_status', 'customer']);
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
            'filters' => [...$this->filters($data), ...isset($data['config']) ? ['config' => $data['config']] : []],
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

    private function operations(OperationsReportRequest $request, string $method, array $filters): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $this->reports->{$method}($data['from'], $data['to'], collect($request->filters())->only($filters)->all())]);
    }

    private function filters(array $data): array
    {
        return array_filter(['vehicle_id' => $data['vehicle_id'] ?? null, 'driver_id' => $data['driver_id'] ?? null]);
    }
}
