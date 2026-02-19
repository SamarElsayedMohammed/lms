<?php

namespace App\Http\Requests\CourseChapter;

use App\Rules\ValidLectureFile;
use App\Rules\ValidYoutubeUrl;
use App\Services\HelperService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLectureCurriculumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $allowedDocs = HelperService::getAllowedDocumentTypes();
        $allowedLectures = HelperService::getAllowedLectureTypes();

        $type = 'lecture';
        $lectureType = $this->input('lecture_type', null);

        // Get max video upload size from settings (in MB), default to 10MB
        // Convert MB to KB for Laravel validation (max rule uses KB)
        $maxVideoSize = HelperService::systemSettings('max_video_upload_size');
        $maxSizeMB = !empty($maxVideoSize) ? (float) $maxVideoSize : 10;
        $maxSizeKB = $maxSizeMB * 1024;

        return [
            'lecture_type_id' => 'required|exists:course_chapter_lectures,id',
            'is_active' => 'nullable|in:0,1,true,false,on,off',
            'lecture_type' => 'nullable|required_if:type,lecture|string|in:file,youtube_url',
            'lecture_title' => 'nullable|required_if:type,lecture|string|max:255',
            'lecture_description' => 'nullable',
            'lecture_hours' => 'nullable|required_if:type,lecture|integer|min:0',
            'lecture_minutes' => 'nullable|required_if:type,lecture|integer|min:0|max:59',
            'lecture_seconds' => 'nullable|required_if:type,lecture|integer|min:0|max:59',
            'lecture_free_preview' => 'nullable|boolean',
            'lecture_file' => [
                'nullable',
                'file',
                'max:' . $maxSizeKB,
                new ValidLectureFile($type, $lectureType, $allowedLectures),
            ],
            'lecture_youtube_url' => [
                'nullable',
                'required_if:lecture_type,youtube_url',
                new ValidYoutubeUrl($type, $lectureType),
            ],
            // Resource
            'resource_status' => 'nullable|boolean',
            'resource_data' => 'nullable|required_if:resource_status,1|array',
            'resource_data.*.resource_type' => [
                function ($attribute, $value, $fail): void {
                    $resourceStatus = $this->input('resource_status');
                    // Only validate resource_type if resource_status is 1 (enabled)
                    if (
                        $resourceStatus == 1
                        || $resourceStatus === '1'
                        || $resourceStatus === true
                        || $resourceStatus === 'true'
                        || $resourceStatus === 'on'
                    ) {
                        if (empty($value)) {
                            $fail('The ' . $attribute . ' field is required when resource is enabled.');
                        }
                        if (!in_array($value, ['file', 'url', 'youtube'])) {
                            $fail('The ' . $attribute . ' must be one of: file, url, youtube.');
                        }
                    }
                },
            ],
            'resource_data.*.resource_title' => 'nullable|string|max:255',
            'resource_data.*.resource_url' => 'nullable|string',
            // File should be required **only if new file is uploaded**
            'resource_data.*.resource_file' => [
                function ($attribute, $value, $fail): void {
                    $type = data_get($this->all(), str_replace('resource_file', 'resource_type', $attribute));

                    $id = data_get($this->all(), str_replace('resource_file', 'id', $attribute));

                    // If type=file and NO id (means new resource), file is required
                    if ($type === 'file' && !$id && !$value) {
                        $fail('The ' . $attribute . ' field is required when resource_type is file.');
                    }
                },
            ],
        ];
    }

    #[\Override]
    public function messages(): array
    {
        return [
            'lecture_type.required_if' => 'The lecture type is required when curriculum type is lecture.',
        ];
    }
}
