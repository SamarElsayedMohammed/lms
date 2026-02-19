<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Course\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Course>
 */
final class CourseFactory extends Factory
{
    protected $model = Course::class;

    #[\Override]
    public function definition(): array
    {
        $title = fake()->sentence(3);

        return [
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(5),
            'short_description' => fake()->paragraph(),
            'thumbnail' => 'courses/default.jpg',
            'user_id' => User::factory(),
            'level' => fake()->randomElement(['beginner', 'intermediate', 'advanced']),
            'course_type' => 'paid',
            'status' => 'publish',
            'price' => fake()->randomFloat(2, 50, 500),
            'discount_price' => null,
            'is_active' => true,
            'approval_status' => 'approved',
        ];
    }

    /**
     * Course with a discount price
     */
    public function withDiscount(float $originalPrice, float $discountPrice): static
    {
        return $this->state(fn(array $attributes) => [
            'price' => $originalPrice,
            'discount_price' => $discountPrice,
        ]);
    }

    /**
     * Free course
     */
    public function free(): static
    {
        return $this->state(fn(array $attributes) => [
            'price' => 0,
            'discount_price' => null,
            'course_type' => 'free',
        ]);
    }

    /**
     * Draft course (not published)
     */
    public function draft(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => 'draft',
        ]);
    }

    /**
     * Inactive course
     */
    public function inactive(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_active' => false,
        ]);
    }
}
