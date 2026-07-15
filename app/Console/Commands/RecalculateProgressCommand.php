<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\UserCourseProgress;
use App\Services\CourseProgressService;

class RecalculateProgressCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:recalculate-progress {--user= : Email or ID of the user to specifically recalculate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculates course progress for users and automatically issues missing certificates.';

    /**
     * Execute the console command.
     */
    public function handle(CourseProgressService $progressService)
    {
        $userIdentifier = $this->option('user');

        $query = UserCourseProgress::query();

        if ($userIdentifier) {
            $user = User::where('email', $userIdentifier)->orWhere('id', $userIdentifier)->first();
            if (!$user) {
                $this->error("User not found: {$userIdentifier}");
                return 1;
            }
            $query->where('user_id', $user->id);
            $this->info("Recalculating for user: {$user->email}");
        } else {
            $this->info("Recalculating progress for ALL users. This may take a while...");
        }

        $records = $query->get();
        $this->info("Found {$records->count()} progress records to process.");

        $bar = $this->output->createProgressBar($records->count());
        $bar->start();

        $updatedCount = 0;
        $certificatesIssued = 0;

        foreach ($records as $record) {
            try {
                $oldPercentage = $record->progress_percentage;
                
                // Recalculate and update
                $newProgress = $progressService->calculateAndUpdateProgress($record->user_id, $record->course_id);
                
                if ($oldPercentage != $newProgress->progress_percentage) {
                    $updatedCount++;
                }

                if ($newProgress->progress_percentage >= 100 && $oldPercentage < 100) {
                    $certificatesIssued++;
                }

            } catch (\Exception $e) {
                $this->error("\nError processing user {$record->user_id} for course {$record->course_id}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        
        $this->info("\n");
        $this->info("Recalculation complete!");
        $this->line("Progress records updated: <info>{$updatedCount}</info>");
        $this->line("New certificates issued: <info>{$certificatesIssued}</info>");

        return 0;
    }
}
