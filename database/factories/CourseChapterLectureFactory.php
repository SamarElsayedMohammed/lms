<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CourseChapterLecture>
 */
final class CourseChapterLectureFactory extends Factory
{
    protected $model = CourseChapterLecture::class;

    #[\Override]
    public function definition(): array
    {
        $title = fake()->sentence(3);

        return [
            'user_id' => User::factory(),
            'course_chapter_id' => CourseChapter::factory(),
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(5),
            'type' => 'file',
            'file' => null,
            'file_extension' => 'mp4',
            'hours' => 0,
            'minutes' => fake()->numberBetween(1, 30),
            'seconds' => fake()->numberBetween(0, 59),
            'description' => fake()->paragraph(),
            'chapter_order' => fake()->numberBetween(1, 10),
            'is_active' => true,
            'free_preview' => false,
        ];
    }

    /**
     * Video lecture with HLS encoding completed
     */
    public function withHls(): static
    {
        return $this->state(fn(array $attributes) => [
            'hls_status' => 'completed',
            'hls_manifest_path' => 'hls/lectures/test/master.m3u8',
        ]);
    }

    /**
     * Free preview lecture
     */
    public function freePreview(): static
    {
        return $this->state(fn(array $attributes) => [
            'free_preview' => true,
        ]);
    }

    /**
     * YouTube lecture
     */
    public function youtube(): static
    {
        return $this->state(fn(array $attributes) => [
            'type' => 'youtube_url',
            'youtube_url' => 'https://www.youtube.com/watch?v=' . Str::random(11),
            'file' => null,
            'file_extension' => null,
        ]);
    }
}
