<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Create (POST) and edit (PUT) a user from User Management. */
class UserRequest extends FormRequest
{
    /** Philippine mobile numbers: 09XX XXX XXXX or +63 9XX XXX XXXX (spaces/dashes allowed). */
    public const MOBILE_PATTERN = '/^(\+?63|0)9\d{2}[\s-]?\d{3}[\s-]?\d{4}$/';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'email' => is_string($this->email) ? strtolower(trim($this->email)) : null,
            'username' => is_string($this->username) ? strtolower(trim($this->username)) : null,
        ], fn ($v) => $v !== null));
    }

    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->route('user');
        $creating = $user === null;
        $required = $creating ? 'required' : 'sometimes';

        return [
            'first_name' => [$required, 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'last_name' => [$required, 'string', 'max:80'],
            'username' => [$required, 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user?->id)],
            'email' => [$required, 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => [$creating ? 'required' : 'sometimes', 'nullable', 'string', 'max:30', 'regex:'.self::MOBILE_PATTERN],
            'job_title' => ['nullable', 'string', 'max:255'],
            'password' => [$creating ? 'required' : 'prohibited', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()->symbols()],
            'role_id' => [$required, 'integer', Rule::exists('roles', 'id')->where('is_active', true)],
            'status' => [$required, Rule::enum(UserStatus::class)],
            'driver_id' => ['nullable', 'integer', Rule::exists('drivers', 'id')->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereNull('user_id')->when($user, fn ($q) => $q->orWhere('user_id', $user->id)))],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid Philippine mobile number, e.g. 0917 123 4567 or +63 917 123 4567.',
            'username.regex' => 'Usernames may contain only lowercase letters, numbers, dots, dashes and underscores.',
            'password.prohibited' => 'Use Reset Password to change a user\'s password.',
            'role_id.exists' => 'Choose an active role.',
            'driver_id.exists' => 'That driver profile is already linked to another user.',
        ];
    }

    public function attributes(): array
    {
        return ['role_id' => 'role', 'driver_id' => 'driver profile', 'phone' => 'mobile number'];
    }
}
