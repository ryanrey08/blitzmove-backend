<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The signed-in user with the role and permission slugs the frontend authorizes with. */
/** @mixin User */
class CurrentUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'job_title' => $this->job_title,
            'phone' => $this->phone,
            'status' => $this->status?->value,
            'is_active' => $this->canAuthenticate(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'role' => $this->role ? RoleSummaryResource::make($this->role) : null,
            'roles' => $this->role ? [$this->role->slug] : [],
            'permissions' => $this->permissionSlugs(),
        ];
    }
}
