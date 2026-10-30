<?php

namespace App\Services;

use App\Models\DocumentFile;
use App\Models\DriverDocument;
use App\Models\MaintenanceRecord;
use App\Models\VehicleDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Stores fleet document (and maintenance attachment) files on the private disk; files are only served through the API. */
class DocumentService
{
    public const DISK = 'local';

    /** Files a single document can hold. */
    public const MAX_FILES = 20;

    public function addFile(VehicleDocument|DriverDocument|MaintenanceRecord $document, UploadedFile $file, string $folder, ?int $userId): DocumentFile
    {
        $path = $file->store("documents/{$folder}", self::DISK);
        abort_unless($path, 500, 'The file could not be stored. Please try again.');

        return $document->files()->create([
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by' => $userId,
        ]);
    }

    public function serve(DocumentFile $file, bool $download = false): StreamedResponse
    {
        abort_unless(Storage::disk(self::DISK)->exists($file->file_path), 404, 'This file is no longer available.');

        return $download
            ? Storage::disk(self::DISK)->download($file->file_path, $file->original_name)
            : Storage::disk(self::DISK)->response($file->file_path, $file->original_name, ['Cache-Control' => 'private, max-age=86400']);
    }

    public function removeFile(DocumentFile $file): void
    {
        Storage::disk(self::DISK)->delete($file->file_path);
        $file->delete();
    }

    /** Soft-deletes the document; its files stay so it can be restored. */
    public function delete(Model $document): void
    {
        $document->delete();
    }
}
