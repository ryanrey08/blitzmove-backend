<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Stores fleet documents on the private disk; files are only served through the API. */
class DocumentService
{
    public const DISK = 'local';

    public function attachFile(Model $document, UploadedFile $file, string $folder): void
    {
        $previous = $document->file_path;

        $document->fill([
            'file_path' => $file->store("documents/{$folder}", self::DISK),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
        ])->save();

        if ($previous) {
            Storage::disk(self::DISK)->delete($previous);
        }
    }

    public function download(Model $document): StreamedResponse
    {
        abort_unless($document->file_path && Storage::disk(self::DISK)->exists($document->file_path), 404, 'No file has been uploaded for this document.');

        return Storage::disk(self::DISK)->download($document->file_path, $document->original_name);
    }

    public function delete(Model $document): void
    {
        $document->delete();
    }
}
