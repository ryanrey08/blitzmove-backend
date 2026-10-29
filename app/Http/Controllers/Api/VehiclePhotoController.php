<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\VehiclePhotoResource;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Vehicle photos on the private disk; the first photo (lowest position) is the cover. */
class VehiclePhotoController extends Controller
{
    public const DISK = 'local';

    public const LIMIT = 5;

    public function store(Request $request, Vehicle $vehicle): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
        ], ['photo.max' => 'Photos can be up to 5 MB.', 'photo.mimes' => 'Photos must be JPG or PNG.']);

        if ($vehicle->photos()->count() >= self::LIMIT) {
            throw BusinessRuleException::withField('photo', 'A vehicle can have up to '.self::LIMIT.' photos. Remove one first.');
        }

        $file = $request->file('photo');
        $photo = $vehicle->photos()->create([
            'file_path' => $file->store("vehicle-photos/{$vehicle->id}", self::DISK),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'position' => (int) $vehicle->photos()->max('position') + 1,
            'uploaded_by' => $request->user()->id,
        ]);

        return VehiclePhotoResource::make($photo)->additional(['message' => 'Photo uploaded.'])->response()->setStatusCode(201);
    }

    public function show(Vehicle $vehicle, VehiclePhoto $photo): StreamedResponse
    {
        abort_unless(Storage::disk(self::DISK)->exists($photo->file_path), 404);

        return Storage::disk(self::DISK)->response($photo->file_path, $photo->original_name, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /** Make this photo the cover (first). */
    public function cover(Vehicle $vehicle, VehiclePhoto $photo): JsonResponse
    {
        $photo->update(['position' => (int) $vehicle->photos()->min('position') - 1]);

        return response()->json(['message' => 'Cover photo updated.']);
    }

    public function destroy(Vehicle $vehicle, VehiclePhoto $photo): JsonResponse
    {
        Storage::disk(self::DISK)->delete($photo->file_path);
        $photo->delete();

        return response()->json(['message' => 'Photo removed.']);
    }
}
