<?php

namespace Database\Factories;

use App\Models\Requester;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Requester> */
class RequesterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional(0.6)->e164PhoneNumber(),
            'company' => fake()->optional(0.7)->company(),
        ];
    }

    public function withoutContactDetails(): static
    {
        return $this->state(fn (array $attributes): array => ['phone' => null, 'company' => null]);
    }
}
