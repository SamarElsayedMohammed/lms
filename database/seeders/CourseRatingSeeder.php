<?php

namespace Database\Seeders;

use App\Models\Course\Course;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Database\Seeder;

class CourseRatingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get some existing courses and users
        $courses = Course::take(3)->get();
        $users = User::take(5)->get();

        if ($courses->isEmpty() || $users->isEmpty()) {
            echo "No courses or users found. Please run other seeders first.\n";
            return;
        }

        // Sample rating data
        $ratings = [
            [
                'rating' => 5,
                'review' => 'Excellent course! The content is well-structured and easy to follow. I learned a lot from this course.',
                'course_id' => $courses->first()->id,
                'user_id' => $users->first()->id,
            ],
            [
                'rating' => 4,
                'review' => 'Very good course with practical examples. The instructor explains concepts clearly.',
                'course_id' => $courses->first()->id,
                'user_id' => $users->get(1)->id ?? $users->first()->id,
            ],
            [
                'rating' => 5,
                'review' => 'Amazing course! I highly recommend it to anyone interested in this topic.',
                'course_id' => $courses->first()->id,
                'user_id' => $users->get(2)->id ?? $users->first()->id,
            ],
            [
                'rating' => 3,
                'review' => 'Good course but could use more advanced topics. Overall satisfied with the content.',
                'course_id' => $courses->first()->id,
                'user_id' => $users->get(3)->id ?? $users->first()->id,
            ],
            [
                'rating' => 4,
                'review' => 'Well-organized course with good practical exercises. The instructor is knowledgeable.',
                'course_id' => $courses->first()->id,
                'user_id' => $users->get(4)->id ?? $users->first()->id,
            ],
            [
                'rating' => 5,
                'review' => 'Outstanding course! The quality of content and delivery is exceptional.',
                'course_id' => $courses->first()->id,
                'user_id' => $users->first()->id,
            ],
            [
                'rating' => 4,
                'review' => 'Great course for beginners. The step-by-step approach makes learning easy.',
                'course_id' => $courses->first()->id,
                'user_id' => $users->get(1)->id ?? $users->first()->id,
            ],
            [
                'rating' => 5,
                'review' => 'Fantastic course! I learned more than I expected. Highly recommended!',
                'course_id' => $courses->first()->id,
                'user_id' => $users->get(2)->id ?? $users->first()->id,
            ],
            [
                'rating' => 2,
                'review' => 'The course was okay but some topics were not explained clearly enough.',
                'course_id' => $courses->first()->id,
                'user_id' => $users->get(3)->id ?? $users->first()->id,
            ],
            [
                'rating' => 4,
                'review' => 'Good course with valuable insights. The practical examples are very helpful.',
                'course_id' => $courses->first()->id,
                'user_id' => $users->get(4)->id ?? $users->first()->id,
            ],
        ];

        foreach ($ratings as $ratingData) {
            Rating::create([
                'rateable_type' => 'App\\Models\\Course\\Course',
                'rateable_id' => $ratingData['course_id'],
                'user_id' => $ratingData['user_id'],
                'rating' => $ratingData['rating'],
                'review' => $ratingData['review'],
            ]);
        }

        echo 'Created ' . count($ratings) . " course ratings.\n";

        // Show summary
        $courseId = $courses->first()->id;
        $totalRatings = Rating::where('rateable_type', 'App\\Models\\Course\\Course')
            ->where('rateable_id', $courseId)
            ->count();
        $averageRating = Rating::where('rateable_type', 'App\\Models\\Course\\Course')->where(
            'rateable_id',
            $courseId,
        )->avg('rating');

        echo "Course ID $courseId now has:\n";
        echo "- Total Ratings: $totalRatings\n";
        echo '- Average Rating: ' . round($averageRating, 1) . "\n";
    }
}
