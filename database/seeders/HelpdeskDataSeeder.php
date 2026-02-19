<?php

namespace Database\Seeders;

use App\Models\HelpdeskGroup;
use App\Models\HelpdeskGroupRequest;
use App\Models\HelpdeskQuestion;
use App\Models\HelpdeskReply;
use App\Models\User;
use Illuminate\Database\Seeder;

class HelpdeskDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get existing users
        $users = User::limit(5)->get();
        if ($users->isEmpty()) {
            $this->command->info('No users found. Please create users first.');
            return;
        }

        // Get existing groups
        $groups = HelpdeskGroup::all();
        if ($groups->isEmpty()) {
            $this->command->info('No helpdesk groups found. Please create groups first.');
            return;
        }

        // Create sample group requests
        foreach ($groups as $group) {
            foreach ($users->take(3) as $user) {
                HelpdeskGroupRequest::create([
                    'group_id' => $group->id,
                    'user_id' => $user->id,
                    'status' => ['pending', 'approved', 'rejected'][rand(0, 2)],
                ]);
            }
        }

        // Create sample questions
        foreach ($groups as $group) {
            foreach ($users->take(4) as $user) {
                HelpdeskQuestion::create([
                    'group_id' => $group->id,
                    'user_id' => $user->id,
                    'title' => 'Sample Question ' . rand(1, 100),
                    'description' => 'This is a sample question description for testing purposes. It contains detailed information about the issue or inquiry.',
                    'is_private' => rand(0, 1),
                ]);
            }
        }

        // Create sample replies
        $questions = HelpdeskQuestion::all();
        foreach ($questions as $question) {
            foreach ($users->take(2) as $user) {
                HelpdeskReply::create([
                    'question_id' => $question->id,
                    'user_id' => $user->id,
                    'reply' => 'This is a sample reply to the question. It provides helpful information and solutions.',
                ]);
            }
        }

        $this->command->info('Helpdesk sample data created successfully!');
    }
}
