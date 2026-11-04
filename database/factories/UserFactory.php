<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => $first = fake()->firstName(),
            'last_name' => $last = fake()->lastName(),
            'name' => "{$first} {$last}",
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role_id' => fn () => Role::where('slug', 'dispatcher')->value('id'),
            'job_title' => 'Dispatcher',
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    /** A user with one of the seeded roles, by slug (super-admin, administrator, dispatcher, ...). */
    public function role(string $slug): static
    {
        return $this->state(function () use ($slug) {
            $role = Role::where('slug', $slug)->firstOrFail();

            return ['role_id' => $role->id, 'job_title' => $role->name];
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
