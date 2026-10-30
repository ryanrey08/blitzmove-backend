<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ListsResources;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentRequest;
use App\Http\Resources\DocumentFileResource;
use App\Http\Resources\DriverDocumentResource;
use App\Http\Resources\VehicleDocumentResource;
use App\Models\DocumentFile;
use App\Models\DriverDocument;
use App\Models\VehicleDocument;
use App\Services\DocumentService;
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

        $query = $model::query()->with([$relation, 'uploader', 'files']);
        $this->applySearch($query, $request, ['title', 'document_number', 'issuing_authority', 'files.original_name', "{$relation}.{$ownerLabel}"]);
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
                ...collect($request->validated())->except(['owner_type', 'owner_id', 'files'])->all(),
                "{$relation}_id" => $request->integer('owner_id'),
                'uploaded_by' => $request->user()->id,
            ]);

            foreach ($request->file('files', []) as $file) {
                $this->documents->addFile($document, $file, $ownerType.'s', $request->user()->id);
            }

            return $document;
        });

        return $resource::make($document->load([$relation, 'uploader', 'files']))
            ->additional(['message' => 'Document saved.'])
            ->response()->setStatusCode(201);
    }

    public function show(string $ownerType, int $id): JsonResource
    {
        [, $resource, $relation] = self::OWNERS[$ownerType];

        return $resource::make($this->find($ownerType, $id)->load([$relation, 'uploader', 'files']));
    }

    public function update(DocumentRequest $request, string $ownerType, int $id): JsonResource
    {
        [, $resource, $relation] = self::OWNERS[$ownerType];
        $document = $this->find($ownerType, $id);

        $files = $request->file('files', []);
        $this->ensureRoomFor($document, count($files));

        $document->update(collect($request->validated())->except('files')->all());
        foreach ($files as $file) {
            $this->documents->addFile($document, $file, $ownerType.'s', $request->user()->id);
        }

        return $resource::make($document->load([$relation, 'uploader', 'files']))->additional(['message' => 'Document updated.']);
    }

    /** Downloads the document's first file (kept for older links; see showFile for each file). */
    public function download(string $ownerType, int $id): StreamedResponse
    {
        $file = $this->find($ownerType, $id)->files()->first();
        abort_unless($file, 404, 'No file has been uploaded for this document.');

        return $this->documents->serve($file, download: true);
    }

    /** Adds one file. The upload page sends files one at a time so each one is confirmed on its own. */
    public function storeFile(Request $request, string $ownerType, int $id): JsonResponse
    {
        $request->validate(['file' => ['required', ...DocumentRequest::FILE_RULES]], collect(DocumentRequest::FILE_MESSAGES)->mapWithKeys(fn ($m, $rule) => ["file.{$rule}" => $m])->all());

        $document = $this->find($ownerType, $id);
        $this->ensureRoomFor($document, 1);
        $file = $this->documents->addFile($document, $request->file('file'), $ownerType.'s', $request->user()->id);

        return DocumentFileResource::make($file)->additional(['message' => 'File uploaded.'])->response()->setStatusCode(201);
    }

    public function showFile(Request $request, string $ownerType, int $id, int $fileId): StreamedResponse
    {
        return $this->documents->serve($this->findFile($ownerType, $id, $fileId), $request->boolean('download'));
    }

    public function destroyFile(string $ownerType, int $id, int $fileId): JsonResponse
    {
        $this->documents->removeFile($this->findFile($ownerType, $id, $fileId));

        return response()->json(['message' => 'File removed.']);
    }

    public function destroy(string $ownerType, int $id): JsonResponse
    {
        $this->documents->delete($this->find($ownerType, $id));

        return response()->json(['message' => 'Document deleted.']);
    }

    private function find(string $ownerType, int $id): VehicleDocument|DriverDocument
    {
        return self::OWNERS[$ownerType][0]::findOrFail($id);
    }

    private function findFile(string $ownerType, int $id, int $fileId): DocumentFile
    {
        return $this->find($ownerType, $id)->files()->findOrFail($fileId);
    }

    private function ensureRoomFor(VehicleDocument|DriverDocument $document, int $adding): void
    {
        if ($adding && $document->files()->count() + $adding > DocumentService::MAX_FILES) {
            throw BusinessRuleException::withField('file', 'A document can have up to '.DocumentService::MAX_FILES.' files. Remove one first.');
        }
    }
}
