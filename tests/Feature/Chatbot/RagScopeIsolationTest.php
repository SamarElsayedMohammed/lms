<?php

namespace Tests\Feature\Chatbot;

use App\Models\ChatbotVectorChunk;
use App\Models\Course\Course;
use App\Services\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RagScopeIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_a_chunks_are_never_retrieved_for_course_b(): void
    {
        $courseA = Course::factory()->create(['title' => 'Course A']);
        $courseB = Course::factory()->create(['title' => 'Course B']);

        $secretToken = "SECRET_TOKEN_COURSE_A_98765";

        // Store vector chunk for Course A
        ChatbotVectorChunk::create([
            'bot_type' => 'course',
            'course_id' => $courseA->id,
            'source_type' => 'text',
            'title' => 'Course A Secret Lesson',
            'chunk_index' => 0,
            'chunk_text' => "This lesson contains sensitive data: {$secretToken}",
            'is_active' => true,
        ]);

        $embedder = new EmbeddingService();

        // Query similarity search specifically scoped to Course B
        $resultsForCourseB = $embedder->searchSimilarChunks("sensitive data SECRET_TOKEN", 'course', $courseB->id, 5);

        // Verify Course B retrieved ZERO chunks from Course A!
        $this->assertEmpty($resultsForCourseB);
        foreach ($resultsForCourseB as $match) {
            $this->assertStringNotContainsString($secretToken, $match['text']);
        }
    }

    public function test_visitor_bot_cannot_retrieve_protected_course_chunks(): void
    {
        $course = Course::factory()->create(['title' => 'Protected Course']);
        $protectedContent = "PROTECTED_LESSON_CONTENT_456123";

        ChatbotVectorChunk::create([
            'bot_type' => 'course',
            'course_id' => $course->id,
            'source_type' => 'text',
            'title' => 'Subscriber Only Content',
            'chunk_index' => 0,
            'chunk_text' => "Subscriber lesson material: {$protectedContent}",
            'is_active' => true,
        ]);

        $embedder = new EmbeddingService();

        // Query similarity search scoped for Visitor Bot (course_id = null, bot_type = 'visitor')
        $visitorResults = $embedder->searchSimilarChunks("Subscriber lesson material", 'visitor', null, 5);

        $this->assertEmpty($visitorResults);
    }
}
