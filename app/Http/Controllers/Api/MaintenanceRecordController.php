<?php

namespace App\Http\Controllers\Api;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Concerns\ListsResources;
use App\Http\Controllers\Controller;
use App\Http\Requests\MaintenanceRecordRequest;
use App\Http\Resources\DocumentFileResource;
use App\Http\Resources\MaintenanceRecordResource;
use App\Models\DocumentFile;
use App\Models\MaintenanceRecord;
use App\Models\Vehicle;
use App\Services\DocumentService;
use App\Services\NumberGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaintenanceRecordController extends Controller
{
    use ListsResources;

    private const RELATIONS = ['vehicle.photos', 'vehicle.assignedDriver'];

    /** Statuses the list can filter by: the stored ones plus the date-based ones. */
    private const STATUS_FILTERS = ['scheduled', 'due_soon', 'overdue', 'upcoming', 'in_progress', 'completed', 'cancelled'];

    /** "Upcoming" covers scheduled work due in this many days. */
    private const UPCOMING_DAYS = 30;

    public function __construct(private readonly DocumentService $documents) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->withLastService($this->filtered($request))->with(self::RELATIONS)->withCount('files');

        if ($request->filled('sort')) {
            $this->applySort($query, $request, ['scheduled_date', 'service_date', 'reference_number', 'estimated_cost', 'actual_cost', 'created_at'], 'scheduled_date');
        } else {
            // Open work first, soonest due on top; then finished work, newest first.
            $open = "case when maintenance_records.status in ('scheduled', 'in_progress') then 0 else 1 end";
            $query->orderByRaw($open)
                ->orderByRaw("case when maintenance_records.status in ('scheduled', 'in_progress') then maintenance_records.scheduled_date end asc")
                ->orderByDesc('maintenance_records.scheduled_date')
                ->orderByDesc('maintenance_records.id');
        }

        return MaintenanceRecordResource::collection($query->paginate($this->perPage($request))->withQueryString());
    }

    /** Card counts (optionally for one vehicle) with the same figure a month earlier. */
    public function summary(Request $request): JsonResponse
    {
        $base = fn () => MaintenanceRecord::query()->when($request->query('vehicle_id'), fn (Builder $q, $ids) => $q->whereIn('vehicle_id', explode(',', (string) $ids)));
        $today = today();
        $then = now()->subMonth();
        $thenDay = $then->copy()->startOfDay();
        // Records that existed a month ago and were still waiting then.
        $waitingThen = fn () => $base()->where('created_at', '<=', $then)
            ->where('status', '!=', MaintenanceStatus::Cancelled->value)
            ->where(fn (Builder $q) => $q->whereNull('started_at')->orWhere('started_at', '>', $then))
            ->where(fn (Builder $q) => $q->whereNull('completed_at')->orWhere('completed_at', '>', $then));
        $scheduled = fn () => $base()->where('status', MaintenanceStatus::Scheduled->value);

        $stat = fn (int $count, int $previous) => ['count' => $count, 'previous' => $previous];

        return response()->json(['data' => [
            'total' => $stat($base()->count(), $base()->where('created_at', '<=', $then)->count()),
            'upcoming' => $stat(
                $this->dueBetween($scheduled(), $today, $today->copy()->addDays(self::UPCOMING_DAYS))->count(),
                $this->dueBetween($waitingThen(), $thenDay, $thenDay->copy()->addDays(self::UPCOMING_DAYS))->count(),
            ),
            'overdue' => $stat(
                $scheduled()->whereDate('scheduled_date', '<', $today)->count(),
                $waitingThen()->whereDate('scheduled_date', '<', $thenDay)->count(),
            ),
            'completed_this_month' => $stat(
                $this->completedBetween($base(), $today->copy()->startOfMonth(), $today->copy()->endOfMonth())->count(),
                $this->completedBetween($base(), $today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth())->count(),
            ),
            'in_progress' => $stat(
                $base()->where('status', MaintenanceStatus::InProgress->value)->count(),
                $base()->where('status', '!=', MaintenanceStatus::Cancelled->value)->where('started_at', '<=', $then)
                    ->where(fn (Builder $q) => $q->whereNull('completed_at')->orWhere('completed_at', '>', $then))->count(),
            ),
        ]]);
    }

    public function nextNumber(NumberGenerator $numbers): JsonResponse
    {
        return response()->json(['data' => ['reference_number' => $numbers->next(MaintenanceRecord::class, 'reference_number', 'MNT')]]);
    }

    /** Service providers and assignees used before, most frequent first, for the form's pick-lists. */
    public function options(): JsonResponse
    {
        $used = fn (string $column) => MaintenanceRecord::query()->whereNotNull($column)->where($column, '!=', '')
            ->groupBy($column)->orderByRaw('count(*) desc')->orderBy($column)->limit(50)->pluck($column);

        return response()->json(['data' => [
            'service_providers' => $used('service_provider'),
            'assignees' => $used('assigned_to'),
        ]]);
    }

    public function store(MaintenanceRecordRequest $request, NumberGenerator $numbers): JsonResponse
    {
        $record = DB::transaction(function () use ($request, $numbers) {
            $record = new MaintenanceRecord([...$request->validated(), 'created_by' => $request->user()->id]);
            $record->reference_number ??= $numbers->next(MaintenanceRecord::class, 'reference_number', 'MNT');
            $this->stampStatus($record);
            $record->save();
            $this->syncVehicle($record);

            return $record;
        });

        return MaintenanceRecordResource::make($this->detailed($record))
            ->additional(['message' => "Maintenance {$record->reference_number} saved."])
            ->response()->setStatusCode(201);
    }

    public function show(MaintenanceRecord $maintenanceRecord): MaintenanceRecordResource
    {
        return MaintenanceRecordResource::make($this->detailed($maintenanceRecord));
    }

    public function update(MaintenanceRecordRequest $request, MaintenanceRecord $maintenanceRecord): MaintenanceRecordResource
    {
        $data = $request->validated();
        if (array_key_exists('reference_number', $data) && ! $data['reference_number']) {
            unset($data['reference_number']); // keep the existing number
        }

        DB::transaction(function () use ($maintenanceRecord, $data) {
            $wasInProgress = $maintenanceRecord->status === MaintenanceStatus::InProgress;
            $previousVehicle = $maintenanceRecord->vehicle_id;
            $maintenanceRecord->fill($data);
            $this->stampStatus($maintenanceRecord);
            $maintenanceRecord->save();
            $this->syncVehicle($maintenanceRecord, $wasInProgress, $previousVehicle);
        });

        return MaintenanceRecordResource::make($this->detailed($maintenanceRecord))->additional(['message' => 'Maintenance updated.']);
    }

    public function destroy(MaintenanceRecord $maintenanceRecord): JsonResponse
    {
        DB::transaction(function () use ($maintenanceRecord) {
            $maintenanceRecord->delete();
            if ($maintenanceRecord->status === MaintenanceStatus::InProgress) {
                $this->releaseVehicle($maintenanceRecord->vehicle_id);
            }
        });

        return response()->json(['message' => 'Maintenance record deleted.']);
    }

    /** Adds one attachment (receipt, job order, photo). The form sends files one at a time. */
    public function storeFile(Request $request, MaintenanceRecord $maintenanceRecord): JsonResponse
    {
        $request->validate(
            ['file' => ['required', ...MaintenanceRecordRequest::FILE_RULES]],
            collect(MaintenanceRecordRequest::FILE_MESSAGES)->mapWithKeys(fn ($m, $rule) => ["file.{$rule}" => $m])->all(),
        );
        abort_if($maintenanceRecord->files()->count() >= DocumentService::MAX_FILES, 422, 'A maintenance record can hold up to '.DocumentService::MAX_FILES.' files.');

        $file = $this->documents->addFile($maintenanceRecord, $request->file('file'), 'maintenance', $request->user()->id);

        return DocumentFileResource::make($file)->additional(['message' => 'File uploaded.'])->response()->setStatusCode(201);
    }

    public function showFile(Request $request, MaintenanceRecord $maintenanceRecord, int $file): StreamedResponse
    {
        return $this->documents->serve($this->findFile($maintenanceRecord, $file), $request->boolean('download'));
    }

    public function destroyFile(MaintenanceRecord $maintenanceRecord, int $file): JsonResponse
    {
        $this->documents->removeFile($this->findFile($maintenanceRecord, $file));

        return response()->json(['message' => 'File removed.']);
    }

    private function findFile(MaintenanceRecord $record, int $fileId): DocumentFile
    {
        return $record->files()->findOrFail($fileId);
    }

    private function detailed(MaintenanceRecord $record): MaintenanceRecord
    {
        return $this->withLastService(MaintenanceRecord::query())->with([...self::RELATIONS, 'files', 'creator'])->findOrFail($record->id);
    }

    private function filtered(Request $request): Builder
    {
        $query = MaintenanceRecord::query();

        $term = trim((string) $request->query('search', ''));
        if ($term !== '') {
            $types = collect(MaintenanceType::cases())->filter(fn (MaintenanceType $t) => str_contains(strtolower($t->label()), strtolower($term)))->map->value->values()->all();
            $query->where(function (Builder $q) use ($term, $types) {
                foreach (['reference_number', 'service_provider', 'assigned_to', 'description'] as $column) {
                    $q->orWhere("maintenance_records.{$column}", 'like', "%{$term}%");
                }
                $q->orWhereHas('vehicle', fn (Builder $v) => $v->where('plate_number', 'like', "%{$term}%")
                    ->orWhere('make', 'like', "%{$term}%")
                    ->orWhere('model', 'like', "%{$term}%"));
                if ($types) {
                    $q->orWhereIn('maintenance_records.service_type', $types);
                }
            });
        }

        $this->applyFilters($query, $request, ['vehicle_id' => 'maintenance_records.vehicle_id', 'category' => 'maintenance_records.category', 'service_type' => 'maintenance_records.service_type']);

        $statuses = array_values(array_intersect(explode(',', (string) $request->query('status', '')), self::STATUS_FILTERS));
        if ($statuses) {
            $query->where(function (Builder $q) use ($statuses) {
                foreach ($statuses as $status) {
                    $q->orWhere(fn (Builder $w) => $this->whereStatus($w, $status));
                }
            });
        }

        if ($from = $request->query('from')) {
            $query->whereDate('maintenance_records.scheduled_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('maintenance_records.scheduled_date', '<=', $to);
        }

        return $query;
    }

    private function whereStatus(Builder $query, string $status): void
    {
        $today = today();
        $scheduled = fn () => $query->where('maintenance_records.status', MaintenanceStatus::Scheduled->value);

        match ($status) {
            'overdue' => $scheduled()->whereDate('maintenance_records.scheduled_date', '<', $today),
            'due_soon' => $this->dueBetween($scheduled(), $today, $today->copy()->addDays(MaintenanceRecord::DUE_SOON_DAYS)),
            'upcoming' => $this->dueBetween($scheduled(), $today, $today->copy()->addDays(self::UPCOMING_DAYS)),
            'scheduled' => $scheduled()->whereDate('maintenance_records.scheduled_date', '>', $today->copy()->addDays(MaintenanceRecord::DUE_SOON_DAYS)),
            default => $query->where('maintenance_records.status', $status),
        };
    }

    private function dueBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereDate('maintenance_records.scheduled_date', '>=', $from)->whereDate('maintenance_records.scheduled_date', '<=', $to);
    }

    private function completedBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->where('status', MaintenanceStatus::Completed->value)->whereDate('service_date', '>=', $from)->whereDate('service_date', '<=', $to);
    }

    /** Adds last_service_date: the previous completed service of the same type on the same vehicle. */
    private function withLastService(Builder $query): Builder
    {
        return $query->addSelect(['last_service_date' => DB::table('maintenance_records as prev')
            ->selectRaw('max(prev.service_date)')
            ->whereColumn('prev.vehicle_id', 'maintenance_records.vehicle_id')
            ->whereColumn('prev.service_type', 'maintenance_records.service_type')
            ->whereColumn('prev.id', '!=', 'maintenance_records.id')
            ->where('prev.status', MaintenanceStatus::Completed->value)
            ->whereNull('prev.deleted_at')
            ->whereRaw('prev.service_date <= coalesce(maintenance_records.service_date, maintenance_records.scheduled_date)')]);
    }

    /** Keeps started_at / completed_at in step with the status. */
    private function stampStatus(MaintenanceRecord $record): void
    {
        match ($record->status) {
            MaintenanceStatus::Scheduled => $record->forceFill(['started_at' => null, 'completed_at' => null]),
            MaintenanceStatus::InProgress => $record->forceFill(['started_at' => $record->started_at ?? now(), 'completed_at' => null]),
            MaintenanceStatus::Completed => $record->forceFill(['completed_at' => $record->completed_at ?? now()]),
            MaintenanceStatus::Cancelled => $record->forceFill(['completed_at' => null]),
        };
    }

    /**
     * Work in progress takes an available vehicle out of service; finishing it (or moving it
     * off the vehicle) puts the vehicle back once nothing else is in progress on it. Odometer
     * readings only move the vehicle's odometer forward, as with fuel records.
     */
    private function syncVehicle(MaintenanceRecord $record, bool $wasInProgress = false, ?int $previousVehicle = null): void
    {
        if ($record->status === MaintenanceStatus::InProgress) {
            Vehicle::whereKey($record->vehicle_id)->where('status', VehicleStatus::Available->value)->update(['status' => VehicleStatus::Maintenance->value]);
        }
        if ($wasInProgress && ($record->status !== MaintenanceStatus::InProgress || $previousVehicle !== $record->vehicle_id)) {
            $this->releaseVehicle($previousVehicle ?? $record->vehicle_id);
        }

        if ($record->odometer_km) {
            Vehicle::whereKey($record->vehicle_id)
                ->where(fn ($q) => $q->whereNull('odometer_km')->orWhere('odometer_km', '<', $record->odometer_km))
                ->update(['odometer_km' => $record->odometer_km]);
        }
    }

    private function releaseVehicle(int $vehicleId): void
    {
        $busy = MaintenanceRecord::where('vehicle_id', $vehicleId)->where('status', MaintenanceStatus::InProgress->value)->exists();
        if (! $busy) {
            Vehicle::whereKey($vehicleId)->where('status', VehicleStatus::Maintenance->value)->update(['status' => VehicleStatus::Available->value]);
        }
    }
}
