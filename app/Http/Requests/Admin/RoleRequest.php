<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $slug = $this->input('slug') ?: ($this->route('role') ? null : $this->input('name'));
        if (is_string($slug)) {
            $this->merge(['slug' => Str::slug($slug)]);
        }
    }

    public function rules(): array
    {
        /** @var Role|null $role */
        $role = $this->route('role');
        $required = $role ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'min:3', 'max:80', Rule::unique('roles', 'name')->ignore($role?->id)],
            'slug' => [$required, 'string', 'max:80', 'alpha_dash', Rule::unique('roles', 'slug')->ignore($role?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'slug')],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A role with this name already exists.',
            'slug.unique' => 'A role with this identifier already exists.',
        ];
    }
}
