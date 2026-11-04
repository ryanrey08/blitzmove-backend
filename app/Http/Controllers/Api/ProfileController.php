<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\CurrentUserResource;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request): CurrentUserResource
    {
        $data = $request->validated();
        // Keep the name parts used by User Management in step with the edited full name.
        $parts = preg_split('/\s+/', trim($data['name'])) ?: [$data['name']];
        $request->user()->update([...$data, 'first_name' => array_shift($parts), 'middle_name' => null, 'last_name' => $parts ? implode(' ', $parts) : null]);

        return CurrentUserResource::make($request->user()->load('role'));
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['password' => $request->string('password')]);
        $user->tokens()->whereKeyNot($user->currentAccessToken()->getKey())->delete();

        return response()->json(['message' => 'Password updated. Other sessions have been signed out.']);
    }
}
