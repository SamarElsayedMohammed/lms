<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Course\CourseChapter\Assignment\AssignmentResource;
use App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use App\Models\Course\CourseChapter\Lecture\LectureResource;
use App\Models\Course\CourseChapter\Quiz\CourseChapterQuiz;
use App\Models\Course\CourseChapter\Quiz\QuizOption;
use App\Models\Course\CourseChapter\Quiz\QuizQuestion;
use App\Models\Course\CourseChapter\Quiz\QuizResource;
use App\Models\Course\CourseChapter\Resource\CourseChapterResource;
use App\Models\Tag;
use App\Models\User;
use Exception;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Kreait\Firebase\Factory;
use Symfony\Component\Intl\Currencies;

class HelperService
{
    public static function changeEnv($updateData = []): bool
    {
        if (count($updateData) > 0) {
            // Read .env-file
            $env = file_get_contents(base_path() . '/.env');
            // Split string on every " " and write into array
            $env = preg_split('/\r\n|\r|\n/', $env);
            $env_array = [];
            foreach ($env as $env_value) {
                if (empty($env_value)) {
                    // Add and Empty Line
                    $env_array[] = '';

                    continue;
                }

                $entry = explode('=', $env_value, 2);
                $env_array[$entry[0]] = $entry[0] . '="' . str_replace('"', '', $entry[1]) . '"';
            }

            foreach ($updateData as $key => $value) {
                $env_array[$key] = $key . '="' . str_replace('"', '', $value) . '"';
            }
            // Turn the array back to a String
            $env = implode("\n", $env_array);

            // And overwrite the .env with the new data
            file_put_contents(base_path() . '/.env', $env);

            return true;
        }

        return false;
    }

    /**
     * @description - This function will return the nested category Option tags using in memory optimization
     */
    public static function childCategoryRendering(
        &$categories,
        int $level = 0,
        null|string $parentCategoryID = '',
    ): bool {
        // Foreach loop only on the parent category objects
        foreach (collect($categories)->where('parent_category_id', $parentCategoryID) as $key => $category) {
            echo
                "<option value='$category->id'>"
                    . str_repeat('&nbsp;', $level * 4)
                    . '|-- '
                    . $category->name
                    . '</option>'
            ;
            // Once the parent category object is rendered we can remove the category from the main object so that redundant data can be removed
            $categories->forget($key);

            // Now fetch the subcategories of the main category
            $subcategories = $categories->where('parent_category_id', $category->id);
            if (!empty($subcategories)) {
                // Finally if subcategories are available then call the recursive function & see the magic
                self::childCategoryRendering($categories, $level + 1, $category->id);
            }
        }

        return false;
    }

    public static function findChildCategories($arr, $parent)
    {
        $children = [];
        foreach ($arr as $key => $value) {
            if ($value['parent_category_id'] != $parent) {
                continue;
            }

            $children[] = $value;
        }
        foreach ($children as $key => $value) {
            $children[$key]['subcategories'] = self::findChildCategories($arr, $value['id']);
        }

        return $children;
    }

    public static function findParentCategory($category, $finalCategories = [])
    {
        $category = Category::find($category);

        if (!empty($category)) {
            $finalCategories[] = $category->id;

            if (!empty($category->parent_category_id)) {
                $finalCategories[] = self::findParentCategory($category->id, $finalCategories);
            }
        }

        return $finalCategories;
    }

    /**
     * Generate slug from text, handling Unicode/Gujarati characters
     */
    public static function generateSlug(string $text): string
    {
        if (empty($text)) {
            return '';
        }

        // Use transliteration for common Gujarati words/phrases first
        $transliterations = [
            'વેબ' => 'web',
            'ડેવલપમેન્ટ' => 'development',
            'ડેવલપમેન્ટ' => 'development',
            'વેબ ડેવલપમેન્ટ' => 'web-development',
            // Add more common translations as needed
        ];

        $translatedText = $text;
        foreach ($transliterations as $gujarati => $english) {
            $translatedText = str_replace($gujarati, $english, $translatedText);
        }

        // Try Laravel's slug on translated text
        $slug = Str::slug($translatedText);

        // If slug is still empty (other Unicode characters), create a fallback
        if (empty($slug)) {
            // Try to transliterate using iconv if available
            if (function_exists('iconv')) {
                $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $translatedText);
                if ($transliterated !== false && !empty($transliterated)) {
                    $slug = Str::slug($transliterated);
                }
            }

            // If still empty, use hash with readable prefix
            if (empty($slug)) {
                $slug = 'category-' . substr(md5($text), 0, 8);
            }
        }

        return $slug;
    }

    /**
     * Generate Slug for any model
     *
     * @param  $model  - Instance of Model
     */
    public static function generateUniqueSlug($model, string $slug, null|int $excludeID = null, int $count = 0): string
    {
        /* NOTE : This can be improved by directly calling in the UI on type of title via AJAX */
        // Use improved slug generation for Unicode support
        $slug = self::generateSlug($slug);
        $newSlug = $count ? $slug . '-' . $count : $slug;

        $data = $model::where('slug', $newSlug);
        if ($excludeID !== null) {
            $data->where('id', '!=', $excludeID);
        }

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $data->withTrashed();
        }
        while ($data->exists()) {
            return self::generateUniqueSlug($model, $slug, $excludeID, $count + 1);
        }

        return $newSlug;
    }

    public static function findAllCategoryIds($model): array
    {
        $ids = [];

        foreach ($model as $item) {
            $ids[] = $item['id'];

            if (!empty($item['children'])) {
                $ids = array_merge($ids, self::findAllCategoryIds($item['children']));
            }
        }

        return $ids;
    }

    public static function generateRandomSlug($length = 10)
    {
        // Generate a random string of lowercase letters and numbers
        $characters = 'abcdefghijklmnopqrstuvwxyz-';
        $slug = '';

        for ($i = 0; $i < $length; $i++) {
            $index = random_int(0, strlen($characters) - 1);
            $slug .= $characters[$index];
        }

        return $slug;
    }

    /**
     * Verify Firebase token
     *
     * @param  string  $token
     * @return array
     *
     * @throws FailedToVerifyToken
     */
    public static function verifyToken(#[\SensitiveParameter] $token)
    {
        try {
            $file = FileService::getFilePath(config('firebase.projects.app.credentials'));
            if (!empty($file) && file_exists($file)) {
                $firebase = (new Factory())
                    ->withServiceAccount($file)
                    ->createAuth();
                $verifiedToken = $firebase->verifyIdToken($token);

                return $verifiedToken;
            } else {
                ApiResponseService::errorResponse('Firebase Configuration Error');
            }
        } catch (FailedToVerifyToken $e) {
            throw $e;
        }
    }

    public static function removeUserFromFirebase($firebaseId)
    {
        $file = FileService::getFilePath(config('firebase.projects.app.credentials'));
        if (!empty($file) && file_exists($file)) {
            $firebase = (new Factory())
                ->withServiceAccount($file)
                ->createAuth();
            $firebase->deleteUser($firebaseId);
        }
    }

    /**
     * Update Firebase user password
     */
    public static function updateFirebasePassword($firebaseId, #[\SensitiveParameter] $newPassword)
    {
        try {
            $file = FileService::getFilePath(config('firebase.projects.app.credentials'));
            if (!empty($file) && file_exists($file)) {
                $firebase = (new Factory())
                    ->withServiceAccount($file)
                    ->createAuth();
                $firebase->updateUser($firebaseId, ['password' => $newPassword]);

                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Firebase password update error: ' . $e->getMessage());

            return false;
        }
    }

    public static function systemSettings($settings)
    {
        $settings = CachingService::getSystemSettings($settings);

        return $settings;
    }

    public static function getCopyright(): string
    {
        $copyright = self::systemSettings('website_copyright');

        if (empty($copyright)) {
            $copyright = 'Copyright &copy; {year} eLMS. All rights reserved.';
        }

        return str_replace('{year}', date('Y'), $copyright);
    }

    public static function getActiveCategories()
    {
        return Category::where('status', 1)
            ->where(static function ($query): void {
                $query
                    ->whereHas('parent_category', static function ($query): void {
                        $query->where('status', 1);
                    })
                    ->orWhereNull('parent_category_id');
            })
            ->with(['subcategories' => static function ($query): void {
                $query->where('status', 1);
            }])
            ->get();
    }

    public static function getOrStoreTagId($requestTag)
    {
        $existingTagIds = Tag::pluck('id')->toArray();
        $tagIds = [];

        foreach ($requestTag as $tag) {
            if (str_starts_with((string) $tag, 'new__')) {
                $tagName = substr((string) $tag, 5);
                $newTag = Tag::firstOrCreate(['tag' => $tagName], ['is_active' => 1]);
                $tagIds[] = $newTag->id;
            } elseif (is_numeric($tag) && in_array((int) $tag, $existingTagIds)) {
                $tagIds[] = (int) $tag;
            } else {
                // fallback: search by name
                $existingTag = Tag::where('tag', $tag)->first();
                if ($existingTag) {
                    $tagIds[] = $existingTag->id;
                } else {
                    $newTag = Tag::create(['tag' => $tag]);
                    $tagIds[] = $newTag->id;
                }
            }
        }

        return $tagIds;
    }

    /**
     * Get Allowed File Types
     *
     * @return array
     */
    public static function getAllowedFileTypes()
    {
        return [
            // Document files
            'pdf',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'ppt',
            'pptx',
            'txt',
            'csv',
            'rtf',
            'odt',
            'ods',
            'odp',
            // Image files
            'jpg',
            'jpeg',
            'png',
            'gif',
            'bmp',
            'tiff',
            'tif',
            'svg',
            'webp',
            'ico',
            'psd',
            'ai',
            'eps',
            // Video files
            'mp4',
            'mov',
            'avi',
            'wmv',
            'flv',
            'mkv',
            'webm',
            'm4v',
            '3gp',
            '3g2',
            'asf',
            'rm',
            'rmvb',
            'vob',
            'ogv',
            'mts',
            'm2ts',
            // Audio files
            'mp3',
            'wav',
            'ogg',
            'm4a',
            'm4b',
            'm4p',
            'aac',
            'flac',
            'wma',
            'aiff',
            'au',
            'ra',
            'amr',
            'opus',
            // Archive files
            'zip',
            'rar',
            '7z',
            'tar',
            'gz',
            'bz2',
            'xz',
            'tar.gz',
            'tar.bz2',
        ];
    }

    public static function getAllowedLectureTypes()
    {
        return [
            // Video files
            'mp4',
            'mov',
            'avi',
            'wmv',
            'flv',
            'mkv',
            'webm',
            'm4v',
            '3gp',
            '3g2',
            'asf',
            'rm',
            'rmvb',
            'vob',
            'ogv',
            'mts',
            'm2ts',
            // Audio files
            'mp3',
            'wav',
            'ogg',
            'm4a',
            'm4b',
            'm4p',
            'aac',
            'flac',
            'wma',
            'aiff',
            'au',
            'ra',
            'amr',
            'opus',
            // Document files
            'pdf',
            'txt',
            'md',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'ppt',
            'pptx',
            'rtf',
            'odt',
            'ods',
            'odp',
            'csv',
            // Image files
            'jpg',
            'jpeg',
            'png',
            'gif',
            'bmp',
            'tiff',
            'tif',
            'svg',
            'webp',
            'ico',
        ];
    }

    public static function getAllowedDocumentTypes()
    {
        return [
            // Document files
            'pdf',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'ppt',
            'pptx',
            'txt',
            'csv',
            'rtf',
            'odt',
            'ods',
            'odp',
            'md',
            // Image files
            'jpg',
            'jpeg',
            'png',
            'gif',
            'bmp',
            'tiff',
            'tif',
            'svg',
            'webp',
            'ico',
            'psd',
            'ai',
            'eps',
            // Video files
            'mp4',
            'mov',
            'avi',
            'wmv',
            'flv',
            'mkv',
            'webm',
            'm4v',
            '3gp',
            '3g2',
            'asf',
            'rm',
            'rmvb',
            'vob',
            'ogv',
            'mts',
            'm2ts',
            // Audio files
            'mp3',
            'wav',
            'ogg',
            'm4a',
            'm4b',
            'm4p',
            'aac',
            'flac',
            'wma',
            'aiff',
            'au',
            'ra',
            'amr',
            'opus',
            // Archive files
            'zip',
            'rar',
            '7z',
            'tar',
            'gz',
            'bz2',
            'xz',
            'tar.gz',
            'tar.bz2',
        ];
    }

    /**
     * Get Allowed File Type Categories for UI
     *
     * @return array
     */
    public static function getAllowedFileTypeCategories()
    {
        return [
            'audio' => 'Audio',
            'video' => 'Video',
            'document' => 'Document',
            'image' => 'Image',
        ];
    }

    /**
     * Convert file type categories to specific file extensions
     *
     * @param  array  $categories
     * @return array
     */
    public static function convertCategoriesToFileTypes($categories)
    {
        $fileTypeMap = [
            'audio' => [
                'mp3',
                'wav',
                'ogg',
                'm4a',
                'm4b',
                'm4p',
                'aac',
                'flac',
                'wma',
                'aiff',
                'au',
                'ra',
                'amr',
                'opus',
            ],
            'video' => [
                'mp4',
                'mov',
                'avi',
                'wmv',
                'flv',
                'mkv',
                'webm',
                'm4v',
                '3gp',
                '3g2',
                'asf',
                'rm',
                'rmvb',
                'vob',
                'ogv',
                'mts',
                'm2ts',
            ],
            'document' => [
                'pdf',
                'doc',
                'docx',
                'xls',
                'xlsx',
                'ppt',
                'pptx',
                'txt',
                'csv',
                'rtf',
                'odt',
                'ods',
                'odp',
                'md',
                'zip',
                'rar',
                '7z',
                'tar',
                'gz',
                'bz2',
                'xz',
            ],
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif', 'svg', 'webp', 'ico', 'psd', 'ai', 'eps'],
        ];

        $fileTypes = [];
        foreach ($categories as $category) {
            if (!isset($fileTypeMap[$category])) {
                continue;
            }

            $fileTypes = array_merge($fileTypes, $fileTypeMap[$category]);
        }

        return array_unique($fileTypes);
    }

    /*
     * Get Instructors with Course Related Permissions
     * @param array $instructorIds
     * @param bool $withActiveUsers
     * @param bool $checkRoleAndPermission - if false, only check if user exists in the provided IDs
     * @return collection
     */
    public static function getInstructorsWithCourseRelatedPermissions($instructorIds = null, $withActiveUsers = false)
    {
        $instructorQuery = User::query();

        // If instructorIds provided, filter by those IDs first
        if ($instructorIds) {
            $instructorQuery->whereIn('id', $instructorIds);
        }

        if ($withActiveUsers) {
            $instructorQuery->where('is_active', 1);
        }

        return $instructorQuery->get();
    }

    /*
     * Get Team Members
     * @param array $teamMemberIds
     * @return collection
     */
    public static function getTeamMembers($teamMemberIds = null, $withActiveUsers = false)
    {
        $teamRole = config('constants.SYSTEM_ROLES.TEAM');
        $teamQuery = User::role($teamRole);
        if ($teamMemberIds) {
            $teamQuery->whereIn('id', $teamMemberIds);
        }
        if ($withActiveUsers) {
            $teamQuery->where('is_active', 1);
        }

        return $teamQuery->get();
    }

    /*
     * Get Default Logo
     * @return string
     */
    public static function getDefaultLogo($type = 'vertical_logo')
    {
        return match ($type) {
            'vertical_logo' => self::systemSettings('vertical_logo'),
            'horizontal_logo' => self::systemSettings('horizontal_logo'),
            default => self::systemSettings('vertical_logo'),
        };
    }

    public static function currencyCode()
    {
        $currencies = Currencies::getNames();
        $currenciesArray = [];
        foreach ($currencies as $key => $value) {
            $currenciesArray[] = [
                'currency_code' => $key,
                'currency_name' => $value,
            ];
        }

        return $currenciesArray;
    }

    public static function getCurrencyData($code)
    {
        $name = Currencies::getName($code);
        $currencySymbol = Currencies::getSymbol($code);

        return ['code' => $code, 'name' => $name, 'symbol' => $currencySymbol];
    }

    public static function getActivePaymentGateway()
    {
        try {
            $paymentMethodTypes = [
                'razorpay_status',
            ];
            $settingsData = self::systemSettings($paymentMethodTypes);
            foreach ($settingsData as $key => $value) {
                if ($value != 1) {
                    continue;
                }

                return $key;
            }

            return 'none';
        } catch (Exception $e) {
            Log::info('Issue in Get Active Payment Gateway function of Helper Service :- ' . $e->getMessage());

            return false;
        }
    }

    public static function getActivePaymentDetails()
    {
        try {
            $getActivePaymentName = self::getActivePaymentGateway();
            switch ($getActivePaymentName) {
                case 'razorpay_status':
                    $data = ['payment_method' => 'razorpay'];

                    return array_merge($data, self::systemSettings(['razorpay_api_key', 'razorpay_secret_key']));
                    break;

                default:
                    return false;
                    break;
            }
        } catch (Exception $e) {
            Log::error('Issue in Get Payment Details function of Helper Service :- ' . $e->getMessage());

            return false;
        }
    }

    /* ------------------------------------------------------------------------------------------------------------------------------
     * Functions
     * ------------------------------------------------------------------------------------------------------------------------------ */

    /**
     * Get Video Data
     *
     * @param  Request  $request
     * @return array
     */
    private static $lectureTypeFolder = 'course-chapters/lectures';

    private static $lectureResourceFolder = 'course-chapters/lectures/resources';

    private static $documentTypeFolder = 'course-chapters/documents';

    private static $quizResourceFolder = 'course-chapters/quizzes/resources';

    private static $assignmentResourceFolder = 'course-chapters/assignments/resources';

    public static function updateAndGetLectureData($request, $chapterId)
    {
        $lectureData = CourseChapterLecture::find($request->lecture_type_id);

        $lectureDataArray = [];
        if ($request->lecture_type == 'youtube_url') {
            if ($lectureData && isset($lectureData->lecture_type) && $lectureData->lecture_type == 'file') {
                FileService::delete($lectureData->getRawOriginal('file'));
            }
            $lectureDataArray = [
                'type' => 'youtube_url',
                'youtube_url' => $request->lecture_youtube_url,
                'file' => null,
                'file_extension' => null,
                'user_id' => Auth::user()?->id,
            ];
        } elseif ($request->lecture_type == 'file') {
            // Always clear youtube_url when lecture_type is file
            $lectureDataArray = [
                'type' => 'file',
                'youtube_url' => null,
            ];

            if ($request->has('lecture_file')) {
                // Check if this is an existing lecture with a file to replace
                $existingFile = null;
                if ($lectureData && $lectureData->lecture_type == 'file') {
                    $existingFile = $lectureData->getRawOriginal('file');
                }

                $lectureDataArray['file'] = FileService::replaceAndUpload(
                    $request->lecture_file,
                    self::$lectureTypeFolder,
                    $existingFile,
                );
                $lectureDataArray['file_extension'] = $request->lecture_file->getClientOriginalExtension();

                // Check if uploaded file is a video and set HLS status to pending
                $extension = $request->lecture_file->getClientOriginalExtension();
                $videoExtensions = ['mp4', 'avi', 'mov', 'webm', 'mkv', 'flv', 'wmv'];

                if (in_array(strtolower($extension), $videoExtensions, true)) {
                    // Check HLS settings before queuing encoding
                    $hlsSettings = CachingService::getSystemSettings(['hls_auto_encode', 'hls_max_file_size_mb']);
                    $autoEncode = ($hlsSettings['hls_auto_encode'] ?? '1') === '1';
                    $maxFileSizeMb = (int) ($hlsSettings['hls_max_file_size_mb'] ?? 500);
                    $fileSizeMb = $request->lecture_file->getSize() / (1024 * 1024);

                    if ($autoEncode && $fileSizeMb <= $maxFileSizeMb) {
                        $lectureDataArray['hls_status'] = 'pending';
                        $lectureDataArray['hls_manifest_path'] = null;
                        $lectureDataArray['hls_error_message'] = null;

                        Log::info('Video file uploaded, will queue HLS encoding', [
                            'extension' => $extension,
                            'lecture_title' => $request->lecture_title,
                            'file_size_mb' => round($fileSizeMb, 2),
                        ]);
                    } else {
                        Log::info('Video file uploaded, HLS encoding skipped', [
                            'extension' => $extension,
                            'lecture_title' => $request->lecture_title,
                            'auto_encode_enabled' => $autoEncode,
                            'file_size_mb' => round($fileSizeMb, 2),
                            'max_file_size_mb' => $maxFileSizeMb,
                        ]);
                    }
                }
            }
        }
        $lectureDataArray = array_merge($lectureDataArray, [
            'id' => $request->lecture_type_id ?? null,
            'title' => $request->lecture_title,
            'slug' => HelperService::generateUniqueSlug(CourseChapterLecture::class, $request->lecture_title),
            'description' => $request->lecture_description,
            'hours' => $request->lecture_hours,
            'minutes' => $request->lecture_minutes,
            'seconds' => $request->lecture_seconds,
            'user_id' => Auth::user()?->id,
            'is_active' => $request->is_active ?? 1,
            'free_preview' => $request->has('lecture_free_preview') ? ($request->lecture_free_preview ? 1 : 0) : 0,
            'is_free' => $request->has('lecture_is_free') ? ($request->lecture_is_free ? 1 : 0) : ($lectureData?->is_free ?? 0),
            'course_chapter_id' => $chapterId,
        ]);

        // Update or Create Lecture
        $lecture = CourseChapterLecture::updateOrCreate(['id' => $request->lecture_type_id], $lectureDataArray);

        // Dispatch HLS encoding job if video file was uploaded
        if ($lecture->hls_status === 'pending') {
            \App\Jobs\EncodeVideoToHLS::dispatch($lecture);
            Log::info('Dispatched EncodeVideoToHLS job', ['lecture_id' => $lecture->id]);
        }

        return $lecture;
    }

    /**
     * Update or Create Document Data
     */
    public static function updateAndGetDocumentData(Request $request, int $chapterId): CourseChapterResource
    {
        $documentData = [
            'id' => $request->document_type_id ?? null,
            'title' => $request->document_title,
            'slug' => HelperService::generateUniqueSlug(CourseChapterResource::class, $request->document_title),
            'description' => $request->document_description,
            'user_id' => Auth::user()?->id,
            'type' => 'file',
            'file' => $request->hasFile('document_file')
                ? FileService::upload($request->document_file, self::$documentTypeFolder)
                : $request->old_document_file ?? null,
            'file_extension' => $request->hasFile('document_file')
                ? $request->document_file->getClientOriginalExtension()
                : (pathinfo($request->old_document_file ?? '', PATHINFO_EXTENSION) ?: null),
            // 'is_active'         => $request->is_active,
            'course_chapter_id' => $chapterId,
            'duration' => $request->duration ?? $request->document_duration ?? null,
            'is_active' => $request->is_active ?? 1,
        ];
        $document = CourseChapterResource::updateOrCreate(['id' => $request->document_type_id], $documentData);

        return $document;
    }

    /**
     * Update or Create Quiz Data
     *
     * @param  \Request  $request
     * @param  int  $chapterId
     * @return array
     */
    public static function updateAndGetQuizData($request, $chapterId, $qaRequired = 1)
    {
        $quizData = [
            'id' => $request->quiz_type_id ?? null,
            'title' => $request->quiz_title,
            'slug' => HelperService::generateUniqueSlug(CourseChapterQuiz::class, $request->quiz_title),
            'description' => $request->quiz_description,
            'user_id' => Auth::user()?->id,
            'course_chapter_id' => $chapterId,
            'total_points' => $request->quiz_total_points,
            'passing_score' => $request->quiz_passing_score,
            'time_limit' => $request->quiz_time_limit,
            'is_active' => $request->is_active ?? 1,
            'can_skip' => $request->quiz_can_skip ?? 0,
        ];

        $mainQuizData = CourseChapterQuiz::updateOrCreate(['id' => $request->quiz_type_id], $quizData);

        // Handle Q&A only if qaRequired is true

        if ($qaRequired) {
            if (!$request->has('quiz_data') || empty($request->quiz_data)) {
                throw new Exception('Quiz questions and answers are required.');
            }

            $questionCount = count($request->quiz_data);
            if ($questionCount == 0) {
                throw new Exception('Quiz must contain at least one question.');
            }

            $perQuestionPoints = $request->quiz_total_points / $questionCount;

            foreach ($request->quiz_data as $quiz) {
                $questionData = [
                    'id' => $quiz['question_id'] ?? null,
                    'user_id' => Auth::user()?->id,
                    'course_chapter_quiz_id' => $mainQuizData->id,
                    'question' => $quiz['question'],
                    'points' => $perQuestionPoints,
                    'is_active' => 1,
                ];

                $question = QuizQuestion::updateOrCreate(['id' => $questionData['id']], $questionData);

                $optionData = [];
                foreach ($quiz['option_data'] as $option) {
                    $optionData[] = [
                        'id' => $option['option_id'] ?? null,
                        'user_id' => Auth::user()?->id,
                        'quiz_question_id' => $question->id,
                        'option' => $option['option'],
                        'is_correct' => $option['is_correct'] ?? 0,
                        'is_active' => 1,
                    ];
                }

                QuizOption::upsert($optionData, ['id']);
            }
        }

        return $mainQuizData;
    }

    /**
     * Update or Create Assignment Data
     *
     * @param  Request  $request
     * @param  int  $chapterId
     * @return array
     */
    public static function updateAndGetAssignmentData($request, $chapterId)
    {
        $assignmentData = [
            'id' => $request->assignment_type_id ?? null,
            'user_id' => Auth::user()?->id,
            'course_chapter_id' => $chapterId,
            'title' => $request->assignment_title,
            'slug' => HelperService::generateUniqueSlug(CourseChapterAssignment::class, $request->assignment_title),
            'description' => $request->assignment_description,
            'instructions' => $request->assignment_instructions,
            'media' => $request->hasFile('assignment_media')
                ? FileService::upload($request->assignment_media, 'course-chapters/assignments/media')
                : null,
            'media_extension' => $request->hasFile('assignment_media')
                ? $request->assignment_media->getClientOriginalExtension()
                : null,
            'points' => $request->assignment_points,
            'allowed_file_types' => $request->assignment_allowed_file_types
                ? implode(',', $request->assignment_allowed_file_types)
                : null,
            'is_active' => $request->is_active ?? 1,
            'can_skip' => $request->assignment_can_skip ?? 0,
        ];
        $assignment = CourseChapterAssignment::updateOrCreate([
            'id' => $request->assignment_type_id ?? null,
        ], $assignmentData);

        return $assignment;
    }

    /***
     * Store Type Resource Data
     * @param Request $request
     * @return array
     */
    public static function getTypeResourceData(
        $type,
        $request,
        $lectureData = null,
        $quizData = null,
        $assignmentData = null,
    ) {
        // Check if resource_data exists and is not empty
        if (empty($request->resource_data)) {
            return;
        }

        switch ($type) {
            case 'lecture':
                $resourceData = [];
                foreach ($request->resource_data as $resource) {
                    // Determine resource type - if not set, determine from file/URL presence
                    $resourceType = $resource['resource_type'] ?? null;

                    // If resource_type is not set, determine it from file or URL
                    if (empty($resourceType)) {
                        if (
                            isset($resource['resource_file'])
                            && $resource['resource_file'] instanceof \Illuminate\Http\UploadedFile
                        ) {
                            $resourceType = 'file';
                        } elseif (!empty($resource['resource_url'])) {
                            $resourceType = 'url';
                        } elseif (!empty($resource['id'])) {
                            // For existing resources, get type from database
                            $existingResource = LectureResource::find($resource['id']);
                            $resourceType = $existingResource->type ?? 'file'; // default to 'file' if not found
                        } else {
                            // Skip this resource if no type can be determined
                            continue;
                        }
                    }

                    // Ensure resource_type is valid (only 'file' or 'url' allowed for lecture_resources)
                    if (!in_array($resourceType, ['file', 'url'])) {
                        // Map other types to valid types
                        if (in_array($resourceType, ['document', 'video', 'audio', 'image'])) {
                            $resourceType = 'file';
                        } else {
                            $resourceType = 'file'; // default to 'file'
                        }
                    }

                    if (!empty($resource['id'])) {
                        $lectureResourcesDBData = LectureResource::find($resource['id']);
                        $order = $lectureResourcesDBData->order ?? 1;
                    } else {
                        $lectureResourcesDBData = null;
                        $order = (LectureResource::where('lecture_id', $lectureData->id)->max('order') + 1) ?? 1;
                    }

                    // Decide file and extension
                    if (
                        isset($resource['resource_file'])
                        && $resource['resource_file'] instanceof \Illuminate\Http\UploadedFile
                    ) {
                        // New file uploaded → replace old one
                        $filePath = FileService::upload($resource['resource_file'], self::$lectureResourceFolder);
                        $fileExtension = $resource['resource_file']->getClientOriginalExtension();

                        // delete old if exists
                        if ($lectureResourcesDBData && $lectureResourcesDBData->getRawOriginal('file')) {
                            FileService::delete($lectureResourcesDBData->getRawOriginal('file'));
                        }
                    } else {
                        // No new file uploaded → keep old file
                        $filePath = $lectureResourcesDBData?->getRawOriginal('file');
                        $fileExtension = $lectureResourcesDBData?->file_extension;
                    }

                    // Handle URL for URL resource types
                    $resourceUrl = null;
                    if ($resourceType === 'url') {
                        $resourceUrl = $resource['resource_url'] ?? $lectureResourcesDBData?->url ?? null;
                    }

                    $resourceDataItem = [
                        'user_id' => Auth::id(),
                        'lecture_id' => $lectureData->id,
                        'title' => $resource['resource_title'] ?? null,
                        'type' => $resourceType, // Use determined type
                        'url' => $resourceUrl,
                        'file' => $filePath,
                        'file_extension' => $fileExtension,
                        'is_active' => 1,
                        'order' => $order,
                    ];

                    // Only include id if it's a valid non-empty value
                    if (!empty($resource['id']) && is_numeric($resource['id'])) {
                        $resourceDataItem['id'] = (int) $resource['id'];
                    }

                    $resourceData[] = $resourceDataItem;
                }

                if (!empty($resourceData)) {
                    LectureResource::upsert($resourceData, ['id']);
                }
                break;

            case 'quiz':
                foreach ($request->resource_data as $resource) {
                    $order = (QuizResource::where('quiz_id', $quizData->id)->max('order') + 1) ?? 1;

                    // Handle file upload for file-based resource types
                    $filePath = null;
                    $fileExtension = null;
                    if (
                        isset($resource['resource_file'])
                        && $resource['resource_file'] instanceof \Illuminate\Http\UploadedFile
                    ) {
                        $filePath = FileService::upload($resource['resource_file'], self::$quizResourceFolder);
                        $fileExtension = $resource['resource_file']->getClientOriginalExtension();
                    }

                    // Handle URL for URL-based resource types
                    $resourceUrl = null;
                    if (in_array($resource['resource_type'], ['url', 'document', 'video', 'audio', 'image'])) {
                        $resourceUrl = $resource['resource_url'] ?? null;
                    }

                    $resourceDataItem = [
                        'user_id' => Auth::user()?->id,
                        'quiz_id' => $quizData->id,
                        'title' => $resource['resource_title'] ?? null,
                        'type' => $resource['resource_type'],
                        'url' => $resourceUrl,
                        'file' => $filePath,
                        'file_extension' => $fileExtension,
                        'is_active' => 1,
                        'order' => $order,
                    ];

                    // Only include id if it's a valid non-empty value
                    if (!empty($resource['id']) && is_numeric($resource['id'])) {
                        $resourceDataItem['id'] = (int) $resource['id'];
                    }

                    $resourceData[] = $resourceDataItem;
                }
                if (!empty($resourceData)) {
                    QuizResource::upsert($resourceData, ['id']);
                }
                break;
            case 'assignment':
                foreach ($request->resource_data as $resource) {
                    // Check Resource Type and if URL is changed from file then remove old file

                    if (!empty($resource['id']) && is_numeric($resource['id'])) {
                        $lectureResourcesDBData = AssignmentResource::where('id', $resource['id'])->first();
                        if (in_array($resource['resource_type'], ['url', 'document', 'video', 'audio', 'image'])) {
                            if (
                                $lectureResourcesDBData
                                && $lectureResourcesDBData->type == 'file'
                                && $lectureResourcesDBData->getRawOriginal('file')
                            ) {
                                FileService::delete($lectureResourcesDBData->getRawOriginal('file'));
                            }
                        }
                        $order = $lectureResourcesDBData?->order;
                    } else {
                        $order =
                            (AssignmentResource::where('assignment_id', $assignmentData->id)->max('order') + 1) ?? 1; // Get Max Order Number
                    }

                    // Handle file upload for file-based resource types
                    $filePath = null;
                    $fileExtension = null;
                    if (
                        isset($resource['resource_file'])
                        && $resource['resource_file'] instanceof \Illuminate\Http\UploadedFile
                    ) {
                        $filePath = FileService::upload($resource['resource_file'], self::$assignmentResourceFolder);
                        $fileExtension = $resource['resource_file']->getClientOriginalExtension();
                    }

                    // Handle URL for URL-based resource types
                    $resourceUrl = null;
                    if (in_array($resource['resource_type'], ['url', 'document', 'video', 'audio', 'image'])) {
                        $resourceUrl = $resource['resource_url'] ?? null;
                    }

                    $resourceDataItem = [
                        'user_id' => Auth::user()?->id,
                        'assignment_id' => $assignmentData->id,
                        'title' => $resource['resource_title'] ?? null,
                        'type' => $resource['resource_type'],
                        'url' => $resourceUrl,
                        'file' => $filePath,
                        'file_extension' => $fileExtension,
                        'is_active' => 1,
                        'order' => $order,
                    ];

                    // Only include id if it's a valid non-empty value
                    if (!empty($resource['id']) && is_numeric($resource['id'])) {
                        $resourceDataItem['id'] = (int) $resource['id'];
                    }

                    $resourceData[] = $resourceDataItem;
                }
                if (!empty($resourceData)) {
                    AssignmentResource::upsert($resourceData, ['id']);
                }
                break;
            case 'resource':
                // Handle document resources - documents don't have sub-resources like other curriculum types
                // This case is here for consistency and future extensibility
                break;
        }
    }

    public static function getFormattedDuration($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $remainingSeconds = $seconds % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = "$hours hour" . ($hours > 1 ? 's' : '');
        }
        if ($minutes > 0) {
            $parts[] = "$minutes minute" . ($minutes > 1 ? 's' : '');
        }
        if ($remainingSeconds > 0 || empty($parts)) {
            $parts[] = "$remainingSeconds second" . ($remainingSeconds > 1 ? 's' : '');
        }

        return implode(' ', $parts);
    }

    public static function getCurriculumData($type, $id)
    {
        $curriculum = null;

        switch ($type) {
            case 'lecture':
                $curriculum = CourseChapterLecture::where('id', $id)->with('resources')->first();

                if ($curriculum) {
                    $curriculum->formatted_duration = HelperService::getFormattedDuration($curriculum->duration ?? 0);
                    $curriculum->free_preview = $curriculum->free_preview ? true : false;
                    $curriculum->curriculum_type = 'lecture';

                    // Format resources with proper fields
                    $curriculum->resources = $curriculum->resources->map(static fn($resource) => [
                        'id' => $resource->id,
                        'user_id' => $resource->user_id,
                        'lecture_id' => $resource->lecture_id,
                        'title' => $resource->title ?? 'Resource', // Provide default title if null
                        'type' => $resource->type,
                        'file' => $resource->file,
                        'file_extension' => $resource->file_extension,
                        'url' => $resource->url,
                        'file_url' => $resource->file_url, // This will use the accessor from the model
                        'order' => $resource->order,
                        'is_active' => $resource->is_active,
                        'created_at' => $resource->created_at,
                        'updated_at' => $resource->updated_at,
                        'deleted_at' => $resource->deleted_at,
                    ]);
                }
                break;
            case 'quiz':
                $curriculum = CourseChapterQuiz::where('id', $id)
                    ->with([
                        'questions' => static function ($query): void {
                            $query->orderBy('order', 'ASC');
                        },
                        'questions.options',
                        'resources',
                    ])
                    ->first();
                if ($curriculum) {
                    // Store original time_limit in seconds for edit form
                    $curriculum->time_limit_seconds = $curriculum->time_limit ?? 0;
                    $curriculum->time_limit = HelperService::getFormattedDuration($curriculum->time_limit ?? 0);
                    $curriculum->curriculum_type = 'quiz';
                }
                break;
            case 'assignment':
                $curriculum = CourseChapterAssignment::where('id', $id)->with('resources')->first();
                if ($curriculum) {
                    $curriculum->curriculum_type = 'assignment';

                    // Format media to return full URL
                    if ($curriculum->media) {
                        $curriculum->media_url = \App\Services\FileService::getFileUrl($curriculum->media);
                    } else {
                        $curriculum->media_url = null;
                    }
                }
                break;
            case 'document':
                $curriculum = CourseChapterResource::where('id', $id)->first();
                if ($curriculum) {
                    $curriculum->formatted_duration = HelperService::getFormattedDuration($curriculum->duration ?? 0);
                    $curriculum->curriculum_type = 'document';
                }
                break;
            case 'resource':
                $curriculum = CourseChapterResource::where('id', $id)->first();
                if ($curriculum) {
                    $curriculum->formatted_duration = HelperService::getFormattedDuration($curriculum->duration ?? 0);
                    $curriculum->curriculum_type = 'resource';
                }
                break;
        }

        return $curriculum;
    }
}
