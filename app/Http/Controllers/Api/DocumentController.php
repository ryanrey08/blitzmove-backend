<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ListsResources;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentRequest;
use App\Http\Resources\DriverDocumentResource;
use App\Http\Resources\VehicleDocumentResource;
use App\Models\DriverDocument;
use App\Models\VehicleDocument;
use App\Services\DocumentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Vehicle and driver documents share one controller; {ownerType} is
 * "vehicle" or "driver" and selects the table.
 */
class DocumentController extends Controller
{
    use ListsResources;

    private const OWNERS = [
        'vehicle' => [VehicleDocument::class, VehicleDocumentResource::class, 'vehicle', 'plate_number'],
        'driver' => [DriverDocument::class, DriverDocumentResource::class, 'driver', 'full_name'],
    ];

    public function __construct(private DocumentService $documents) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $ownerType = $request->query('owner_type') === 'driver' ? 'driver' : 'vehicle';
        [$model, $resource, $relation, $ownerLabel] = self::OWNERS[$ownerType];

        $query = $model::query()->with([$relation, 'uploader']);
        $this->applySearch($query, $request, ['title', 'document_number', 'original_name', "{$relation}.{$ownerLabel}"]);
        $this->applyFilters($query, $request, ['document_type', 'owner_id' => "{$relation}_id"]);

        $today = now()->toDateString();
        $soon = now()->addDays(VehicleDocument::EXPIRING_SOON_DAYS)->toDateString();
        match ($request->query('expiry_status')) {
            'expired' => $query->whereDate('expires_at', '<', $today),
            'expiring_soon' => $query->whereDate('expires_at', '>=', $today)->whereDate('expires_at', '<=', $soon),
            'valid' => $query->whereDate('expires_at', '>', $soon),
            'no_expiry' => $query->whereNull('expires_at'),
            default => null,
        };

        $this->applySort($query, $request, ['title', 'document_type', 'expires_at', 'created_at']);

        return $resource::collection($query->paginate($this->perPage($request))->withQueryString());
    }

    public function store(DocumentRequest $request): JsonResponse
    {
        $ownerType = $request->input('owner_type');
        [$model, $resource, $relation] = self::OWNERS[$ownerType];

        $document = DB::transaction(function () use ($request, $model, $relation, $ownerType) {
            $document = $model::create([
                ...collect($request->validated())->except(['owner_type', 'owner_id', 'file'])->all(),
                "{$relation}_id" => $request->integer('owner_id'),
                'uploaded_by' => $request->user()->id,
            ]);

            if ($request->hasFile('file')) {
                $this->documents->attachFile($document, $request->file('file'), $ownerType.'s');
            }

            return $document;
        });

        return $resource::make($document->load([$relation, 'uploader']))
            ->additional(['message' => 'Document saved.'])
            ->response()->setStatusCode(201);
    }

    public function show(string $ownerType, int $id): JsonResource
    {
        [, $resource, $relation] = self::OWNERS[$ownerType];

        return $resource::make($this->find($ownerType, $id)->load([$relation, 'uploader']));
    }

    public function update(DocumentRequest $request, string $ownerType, int $id): JsonResource
    {
        [, $resource, $relation] = self::OWNERS[$ownerType];
        $document = $this->find($ownerType, $id);

        $document->update(collect($request->validated())->except('file')->all());
        if ($request->hasFile('file')) {
            $this->documents->attachFile($document, $request->file('file'), $ownerType.'s');
        }

        return $resource::make($document->load([$relation, 'uploader']))->additional(['message' => 'Document updated.']);
    }

    public function download(string $ownerType, int $id): StreamedResponse
    {
        return $this->documents->download($this->find($ownerType, $id));
    }

    public function destroy(string $ownerType, int $id): JsonResponse
    {
        $this->documents->delete($this->find($ownerType, $id));

        return response()->json(['message' => 'Document deleted.']);
    }

    private function find(string $ownerType, int $id): Model
    {
        return self::OWNERS[$ownerType][0]::findOrFail($id);
    }
}
