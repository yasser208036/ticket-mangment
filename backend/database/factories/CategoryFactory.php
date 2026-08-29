<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        // name and slug are both UNIQUE and must derive from the same words,
        // or a retry produces a mismatched pair.
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'description' => fake()->optional(0.8)->sentence(),
            // color is char(7); hexColor() returns exactly #rrggbb.
            'color' => fake()->hexColor(),
            // Hardcoded true, not fake()->boolean(): scopeActive() and the
            // ticket index filter on this column, so a randomly deactivating
            // factory would make other stories' tests flaky.
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function ordered(int $sortOrder): static
    {
        return $this->state(fn () => ['sort_order' => $sortOrder]);
    }
}
