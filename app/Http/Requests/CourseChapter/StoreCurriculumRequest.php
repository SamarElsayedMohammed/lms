<?php

namespace App\Http\Requests\CourseChapter;

use App\Rules\ValidDocumentFile;
use App\Rules\ValidLectureFile;
use App\Rules\ValidQuizAnswer;
use App\Rules\ValidQuizOptions;
use App\Rules\ValidYoutubeUrl;
use App\Services\HelperService;
use Illuminate\Foundation\Http\FormRequest;

class StoreCurriculumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $allowedDocs = HelperService::getAllowedDocumentTypes();
        $allowedLectures = HelperService::getAllowedLectureTypes();

        $type = $this->input('type') ?? '';
        $lectureType = $this->input('lecture_type', null);
        $documentType = $this->input('document_type', null);
        $quizData = $this->input('quiz_data', []);

        // Get max video upload size from settings (in MB), default to 10MB
        // Convert MB to KB for Laravel validation (max rule uses KB)
        $maxVideoSize = HelperService::systemSettings('max_video_upload_size');
        $maxSizeMB = !empty($maxVideoSize) ? (float) $maxVideoSize : 10;
        $maxSizeKB = $maxSizeMB * 1024;

        $rules = [
            'is_active' => 'nullable|boolean',
            'type' => 'required|string|in:lecture,document,quiz,assignment',
            // Lecture
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
            // Document
            'document_title' => 'nullable|required_if:type,document|string|max:255',
            'document_description' => 'nullable',
            'document_file' => [
                'required_if:type,document',
                'file',
                new ValidDocumentFile($type, $documentType, $allowedDocs),
            ],
            // Quiz
            'qa_required' => 'nullable|boolean',
            'quiz_title' => 'nullable|required_if:type,quiz|string|max:255',
            'quiz_description' => 'nullable',
            // 'quiz_time_limit'                       => 'nullable|required_if:type,quiz|integer|min:0',
            'quiz_can_skip' => 'nullable|boolean',
            'quiz_passing_score' => 'nullable|required_if:type,quiz|integer|min:0|max:100',
        ];

        // Quiz data validation - only apply when type is quiz
        if ($type === 'quiz') {
            $qaRequired = $this->input('qa_required', 1);
            if ($qaRequired == 1) {
                $rules['quiz_data'] = 'required|array|min:1';
                $rules['quiz_data.*.question'] = 'required|string|min:1';
                $rules['quiz_data.*.option_data'] = [
                    'required',
                    'array',
                    'min:1',
                    new ValidQuizOptions($type),
                ];
                $rules['quiz_data.*.option_data.*.option'] = 'required|string|max:255|min:1';
            } else {
                $rules['quiz_data'] = 'nullable|array';
                $rules['quiz_data.*.question'] = 'nullable|string|min:1';
                $rules['quiz_data.*.option_data'] = 'nullable|array';
                $rules['quiz_data.*.option_data.*.option'] = 'nullable|string|max:255|min:1';
            }
        } else {
            // If type is not quiz, quiz_data is not required
            $rules['quiz_data'] = 'nullable|array';
            $rules['quiz_data.*.question'] = 'nullable|string|min:1';
            $rules['quiz_data.*.option_data'] = 'nullable|array';
            $rules['quiz_data.*.option_data.*.option'] = 'nullable|string|max:255|min:1';
        }

        // is_correct validation - only apply when type is quiz
        if ($type === 'quiz') {
            $rules['quiz_data.*.option_data.*.is_correct'] = [
                'nullable',
                new ValidQuizAnswer($type, $quizData),
            ];
        } else {
            $rules['quiz_data.*.option_data.*.is_correct'] = 'nullable|boolean';
        }

        // Assignment
        $rules['assignment_title'] = 'nullable|required_if:type,assignment|string|max:255';
        $rules['assignment_description'] = 'nullable';
        $rules['assignment_instructions'] = 'nullable|string';
        $rules['assignment_media'] = 'nullable|file|mimes:pdf,txt,md,doc,docx,xls,xlsx,ppt,pptx,csv,zip,rar,7z,jpg,jpeg,png,gif,bmp,tiff,tif,svg,webp,ico,psd,ai,eps,mp3,wav,ogg,m4a,m4b,m4p,aac,flac,wma,aiff,au,ra,amr,opus,mp4,mov,avi,wmv,flv,mkv,webm,m4v,3gp,3g2,asf,rm,rmvb,vob,ogv,mts,m2ts|max:10240';
        $rules['assignment_points'] = 'nullable|required_if:type,assignment|integer|min:0';
        $rules['assignment_allowed_file_types'] = 'nullable|required_if:type,assignment|array|max:5';
        $rules['assignment_can_skip'] = 'nullable|boolean';

        // Resource
        $rules['resource_status'] = 'nullable|boolean';
        $rules['resource_data'] = 'nullable|required_if:resource_status,1|array';
        $rules['resource_data.*.resource_type'] = 'nullable|string|in:url,file,document,video,audio,image';
        $rules['resource_data.*.resource_title'] = 'nullable|string|max:255';
        $rules['resource_data.*.resource_url'] = 'nullable|required_if:resource_data.*.resource_type,url|url';
        $rules['resource_data.*.resource_file'] =
            'nullable|required_if:resource_data.*.resource_type,file,document,video,audio,image|file|mimes:'
            . implode(',', array_map(strtolower(...), $allowedDocs))
            . '|max:5120';

        return $rules;
    }

    #[\Override]
    public function messages(): array
    {
        return [
            'type.required' => 'The curriculum type is required.',
            'type.in' => 'The curriculum type must be one of: lecture, document, quiz, assignment.',
            'lecture_type.required_if' => 'The lecture type is required when curriculum type is lecture.',
            'document_type.required_if' => 'The document type is required when curriculum type is document.',
        ];
    }
}
