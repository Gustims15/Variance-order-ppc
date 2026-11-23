<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
        protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),

            // Default account configuration.
            'role' => User::ROLE_VIEWER,
            'is_active' => true,

            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Administrator account.
     */
    public function administrator(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_ADMINISTRATOR,
        ]);
    }

    /**
     * Manager account.
     */
    public function manager(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_MANAGER,
        ]);
    }

    /**
     * Staff account.
     */
    public function staff(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_STAFF,
        ]);
    }

    /**
     * Viewer account.
     */
    public function viewer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_VIEWER,
        ]);
    }

    /**
     * Inactive account.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the model's email should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
