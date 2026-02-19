<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course\CourseChapter\Quiz\CourseChapterQuiz;
use App\Models\Course\CourseChapter\Quiz\QuizOption;
use App\Models\Course\CourseChapter\Quiz\UserQuizAnswer;
use App\Models\Course\CourseChapter\Quiz\UserQuizAttempt;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Throwable;

class QuizTrackingApiController extends Controller
{
    public function startAttempt(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'course_chapter_quiz_id' => 'required|integer|exists:course_chapter_quizzes,id',
            'total_time' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors());
        }

        $attempt = UserQuizAttempt::create([
            'user_id' => Auth::id(),
            'course_chapter_quiz_id' => $request->course_chapter_quiz_id,
            'total_time' => $request->total_time ?? 0,
            'time_taken' => 0,
            'score' => 0,
        ]);

        return ApiResponseService::successResponse(__('Quiz attempt started.'), $attempt);
    }

    /**
     * Store an answer for a quiz question
     */
    public function storeAnswer(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'quiz_question_id' => 'required|integer|exists:quiz_questions,id',
            'quiz_option_id' => [
                'required',
                'integer',
                'exists:quiz_options,id',
                static function ($attribute, $value, $fail) use ($request): void {
                    $exists = QuizOption::where('id', $value)
                        ->where('quiz_question_id', $request->quiz_question_id)
                        ->exists();
                    if (!$exists) {
                        $fail(__('The selected option does not belong to the given question.'));
                    }
                },
            ],
            'attempt_id' => 'required|integer|exists:user_quiz_attempts,id',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors());
        }

        // Update or create answer
        $answer = UserQuizAnswer::updateOrCreate([
            'user_id' => Auth::id(),
            'quiz_question_id' => $request->quiz_question_id,
            'user_quiz_attempt_id' => $request->attempt_id,
        ], [
            'quiz_option_id' => $request->quiz_option_id,
        ]);

        return ApiResponseService::successResponse(__('Answer saved successfully.'), $answer);
    }

    /**
     * Finish quiz and calculate score
     */
    public function finishAttempt(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'attempt_id' => 'required|integer|exists:user_quiz_attempts,id',
            'time_taken' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors());
        }

        $attempt = UserQuizAttempt::with('answers.option')->find($request->attempt_id);

        $correctCount = 0;

        foreach ($attempt?->answers as $answer) {
            if (!($answer->option && $answer->option->is_correct)) {
                continue;
            }

            $correctCount++;
        }

        // Calculate score (percentage)
        $totalQuestions = $attempt->answers->count();
        $score = $totalQuestions > 0 ? ($correctCount / $totalQuestions) * 100 : 0;

        $attempt->update([
            'score' => $score,
            'time_taken' => $request->time_taken ?? 0,
        ]);

        return ApiResponseService::successResponse(__('Quiz finished.'), [
            'score' => round($score, 2),
            'total_questions' => $totalQuestions,
            'correct_answers' => $correctCount,
        ]);
    }

    public function getQuizDetails(Request $request)
    {
        try {
            $validator = Validator::make(['quiz_id' => $request->quiz_id], [
                'quiz_id' => 'required|integer|exists:course_chapter_quizzes,id',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors());
            }

            $quiz = CourseChapterQuiz::with([
                'questions.options' => static function ($q): void {
                    $q->select('id', 'quiz_question_id', 'option_text');
                },
            ])->find($request->quiz_id);

            return ApiResponseService::successResponse(__('Quiz details fetched.'), $quiz);
        } catch (Throwable $e) {
            return ApiResponseService::errorResponse(__('Failed to fetch quiz details.'), $e->getMessage());
        }
    }

    /**
     * Get all attempts by the logged-in user for this quiz
     */
    public function getUserAttempts(Request $request)
    {
        try {
            $validator = Validator::make(['quiz_id' => $request->quiz_id], [
                'quiz_id' => 'required|integer|exists:course_chapter_quizzes,id',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors());
            }

            $attempts = UserQuizAttempt::where('user_id', Auth::id())
                ->where('course_chapter_quiz_id', $request->quiz_id)
                ->orderBy('created_at', 'desc')
                ->get(['id', 'score', 'time_taken', 'created_at']);

            return ApiResponseService::successResponse(__('User attempts fetched.'), $attempts);
        } catch (Throwable $e) {
            return ApiResponseService::errorResponse(__('Failed to fetch attempts.'), $e->getMessage());
        }
    }

    /**
     * Get attempt details with answers and correct status
     */
    public function getAttemptDetails(Request $request)
    {
        try {
            $validator = Validator::make(['attempt_id' => $request->attempt_id], [
                'attempt_id' => 'required|integer|exists:user_quiz_attempts,id',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors());
            }

            $attempt = UserQuizAttempt::with([
                'answers.question' => static function ($q): void {
                    $q->select('id', 'question_text');
                },
                'answers.option' => static function ($q): void {
                    $q->select('id', 'quiz_question_id', 'option_text', 'is_correct');
                },
            ])->find($request->attempt_id);

            if (!$attempt || $attempt->user_id !== Auth::id()) {
                return ApiResponseService::errorResponse(__('Attempt not found.'));
            }

            $details = $attempt->answers->map(static fn($answer) => [
                'question' => $answer->question->question_text ?? '',
                'selected_option' => $answer->option->option_text ?? '',
                'is_correct' => $answer->option->is_correct ?? false,
            ]);

            return ApiResponseService::successResponse(__('Attempt details fetched.'), [
                'score' => $attempt->score,
                'time_taken' => $attempt->time_taken,
                'answers' => $details,
            ]);
        } catch (Throwable $e) {
            return ApiResponseService::errorResponse(__('Failed to fetch attempt details.'), $e->getMessage());
        }
    }

    /**
     * Get Quiz Summary with user's latest attempt
     */
    public function getQuizSummary(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'course_chapter_quiz_id' => 'required|integer|exists:course_chapter_quizzes,id',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors());
            }

            $quizId = $request->course_chapter_quiz_id;
            $userId = Auth::id();

            // Get quiz details with questions and all options
            $quiz = CourseChapterQuiz::with([
                'questions' => static function ($q): void {
                    $q->orderBy('order');
                },
                'questions.options',
            ])->find($quizId);

            if (!$quiz) {
                return ApiResponseService::errorResponse(__('Quiz not found.'));
            }

            // Get user's latest attempt for this quiz
            $latestAttempt = UserQuizAttempt::where('user_id', $userId)
                ->where('course_chapter_quiz_id', $quizId)
                ->with(['answers.option'])
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$latestAttempt) {
                return ApiResponseService::errorResponse(__('No attempt found for this quiz.'));
            }

            // Build questions summary
            $questionsSummary = [];
            $correctCount = 0;
            $totalQuestions = $quiz->questions->count();

            foreach ($quiz->questions as $index => $question) {
                // Find user's answer for this question
                $userAnswer = $latestAttempt->answers->firstWhere('quiz_question_id', $question->id);

                // Find correct option
                $correctOption = $question->options->firstWhere('is_correct', 1);

                $isCorrect = false;
                if ($userAnswer && $correctOption) {
                    $isCorrect = $userAnswer->quiz_option_id == $correctOption->id;
                    if ($isCorrect) {
                        $correctCount++;
                    }
                }

                $questionsSummary[] = [
                    'question_number' => 'Q.' . ($index + 1),
                    'question_id' => $question->id,
                    'question' => $question->question,
                    'your_answer' => $userAnswer ? $userAnswer->option->option : null,
                    'correct_answer' => $correctOption ? $correctOption->option : null,
                    'is_correct' => $isCorrect,
                    'points' => $question->points ?? 0,
                ];
            }

            // Calculate total points
            $totalPoints = $quiz->questions->sum('points') ?? $totalQuestions;
            $earnedPoints = 0;
            foreach ($questionsSummary as $q) {
                if (!$q['is_correct']) {
                    continue;
                }

                $earnedPoints += $q['points'];
            }

            $summary = [
                'quiz_id' => $quiz->id,
                'quiz_title' => $quiz->title,
                'attempt_id' => $latestAttempt->id,
                'total_points' => $totalPoints,
                'earned_points' => $earnedPoints,
                'score' => round($latestAttempt->score, 2),
                'total_questions' => $totalQuestions,
                'correct_answers' => $correctCount,
                'wrong_answers' => $totalQuestions - $correctCount,
                'time_taken' => $latestAttempt->time_taken,
                'attempted_at' => $latestAttempt->created_at,
                'questions' => $questionsSummary,
            ];

            return ApiResponseService::successResponse(__('Quiz summary fetched successfully.'), $summary);
        } catch (Throwable $e) {
            return ApiResponseService::errorResponse(__('Failed to fetch quiz summary.'), $e->getMessage());
        }
    }
}
