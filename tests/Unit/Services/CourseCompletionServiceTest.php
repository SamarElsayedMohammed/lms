<?php

namespace Tests\Unit\Services;

use App\Services\CourseCompletionService;
use Tests\TestCase;

class CourseCompletionServiceTest extends TestCase
{
    public function test_all_assignments_submitted_when_all_are_skippable(): void
    {
        $this->assertTrue(CourseCompletionService::allAssignmentsSubmitted(1, 1, 0));
        $this->assertTrue(CourseCompletionService::allAssignmentsSubmitted(5, 5, 0));
    }

    public function test_all_assignments_submitted_when_required_assignments_are_submitted(): void
    {
        $this->assertTrue(CourseCompletionService::allAssignmentsSubmitted(2, 1, 1));
        $this->assertTrue(CourseCompletionService::allAssignmentsSubmitted(4, 2, 2));
    }

    public function test_all_assignments_submitted_false_when_required_assignments_missing(): void
    {
        $this->assertFalse(CourseCompletionService::allAssignmentsSubmitted(2, 1, 0));
        $this->assertFalse(CourseCompletionService::allAssignmentsSubmitted(3, 0, 2));
    }

    public function test_all_assignments_submitted_true_when_no_assignments(): void
    {
        $this->assertTrue(CourseCompletionService::allAssignmentsSubmitted(0, 0, 0));
    }
}
