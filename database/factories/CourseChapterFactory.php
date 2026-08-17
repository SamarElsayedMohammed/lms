<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseChapter>
 */
final class CourseChapterFactory extends Factory
{
    protected $model = CourseChapter::class;

    #[\Override]
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'user_id' => \App\Models\User::factory(),
            'title' => fake()->sentence(3),
            'chapter_order' => fake()->numberBetween(1, 10),
            'is_active' => true,
        ];
    }
}
