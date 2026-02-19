<?php

declare(strict_types=1);

namespace App\Services;

final class CourseCompletionService
{
    public static function allAssignmentsSubmitted(
        int $totalAssignments,
        int $skippableAssignments,
        int $submittedAssignments,
    ): bool {
        $requiredAssignments = max(0, $totalAssignments - $skippableAssignments);

        return $requiredAssignments === 0 || $submittedAssignments >= $requiredAssignments;
    }
}
