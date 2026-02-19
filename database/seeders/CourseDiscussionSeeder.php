<?php

namespace Database\Seeders;

use App\Models\Course\Course;
use App\Models\Course\CourseDiscussion;
use App\Models\User;
use Illuminate\Database\Seeder;

class CourseDiscussionSeeder extends Seeder
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

        // Create main discussions
        $discussions = [
            [
                'course_id' => $courses->first()->id,
                'user_id' => $users->first()->id,
                'message' => 'I was initially hesitant to switch to a new LMS, but e-LMS was incredibly easy to navigate and implement. My team picked it up quickly, and we\'ve seen a significant increase in student engagement.',
                'parent_id' => null,
            ],
            [
                'course_id' => $courses->first()->id,
                'user_id' => $users->get(1)->id,
                'message' => 'The course creation tools are fantastic! I can easily upload videos and create interactive quizzes. My students love the gamification features.',
                'parent_id' => null,
            ],
            [
                'course_id' => $courses->first()->id,
                'user_id' => $users->get(2)->id,
                'message' => 'Customer support is outstanding. They responded to my query within minutes and solved the issue immediately.',
                'parent_id' => null,
            ],
            [
                'course_id' => $courses->get(1)->id ?? $courses->first()->id,
                'user_id' => $users->get(3)->id ?? $users->first()->id,
                'message' => 'The analytics dashboard is amazing. It gives us insights we never had before.',
                'parent_id' => null,
            ],
            [
                'course_id' => $courses->get(2)->id ?? $courses->first()->id,
                'user_id' => $users->get(4)->id ?? $users->first()->id,
                'message' => 'Has anyone tried the mobile app? I\'m curious about the user experience.',
                'parent_id' => null,
            ],
        ];

        // Create main discussions
        foreach ($discussions as $discussionData) {
            $discussion = CourseDiscussion::create($discussionData);

            // Create some replies for each discussion
            $replies = [
                [
                    'user_id' => $users->get(1)->id ?? $users->first()->id,
                    'message' => 'I completely agree! The interface is so intuitive. What features do you find most useful?',
                ],
                [
                    'user_id' => $users->get(2)->id ?? $users->first()->id,
                    'message' => 'The analytics dashboard is amazing. It gives us insights we never had before.',
                ],
                [
                    'user_id' => $users->get(3)->id ?? $users->first()->id,
                    'message' => 'Has anyone tried the mobile app? I\'m curious about the user experience.',
                ],
            ];

            // Create replies for this discussion
            foreach ($replies as $replyData) {
                CourseDiscussion::create([
                    'course_id' => $discussion->course_id,
                    'user_id' => $replyData['user_id'],
                    'message' => $replyData['message'],
                    'parent_id' => $discussion->id,
                ]);
            }
        }

        echo 'Created ' . count($discussions) . ' main discussions with ' . (count($discussions) * 3) . " replies.\n";
    }
}
