<?php

declare(strict_types=1);

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;

final class LessonHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_time' => ['required', 'numeric', 'min:0'],
            'duration'     => ['required', 'numeric', 'min:1'],
            'event'        => ['required', 'string', 'in:progress,pause,ended,seeked'],
            'session_id'   => ['nullable', 'string', 'max:255'],
            'device'       => ['nullable', 'string', 'max:100'],
            'browser'      => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        \Illuminate\Support\Facades\Log::warning('Lesson heartbeat rejected: INVALID_PAYLOAD', [
            'user_id' => $this->user()?->id,
            'lesson_id' => $this->route('lesson') instanceof \App\Models\Course\CourseChapter\Lecture\CourseChapterLecture
                ? $this->route('lesson')->id
                : $this->route('lesson') ?? $this->route('lectureId'),
            'errors' => $validator->errors()->toArray(),
            'payload' => $this->except(['token', 'heartbeat_token']),
        ]);

        parent::failedValidation($validator);
    }
}
