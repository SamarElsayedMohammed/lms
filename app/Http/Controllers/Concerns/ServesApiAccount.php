<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Category;
use App\Models\Course\Course;
use App\Models\Course\UserCourseTrack;
use App\Models\CustomFormField;
use App\Models\CustomFormFieldOption;
use App\Models\Faq;
use App\Models\Instructor;
use App\Models\InstructorOtherDetail;
use App\Models\InstructorPersonalDetail;
use App\Models\InstructorSocialMedia;
use App\Models\Language;
use App\Models\Page;
use App\Models\PaymentTransaction;
use App\Models\SeoSetting;
use App\Models\SocialLogin;
use App\Models\SocialMedia;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\UserFcmToken;
use App\Services\ApiResponseService;
use App\Services\ApiService;
use App\Services\FileService;
use App\Services\HelperService;
use App\Services\Payment\PaymentService;
use App\Support\RoleManager;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

trait ServesApiAccount
{
    public function getUserDetails(Request $request)
    {
        try {
            $authUser = $request->user();
            if (!$authUser) {
                return ApiResponseService::unauthorizedResponse();
            }

            /** @var User */
            $user = User::where(['id' => $authUser->id, 'is_active' => 1])->with([
                'instructor_details.personal_details',
                'instructor_details.social_medias.social_media',
                'instructor_details.other_details.custom_form_field',
                'instructor_details.other_details.custom_form_field_option',
            ])->first();

            if (empty($user)) {
                return ApiResponseService::unauthorizedResponse();
            }

            // Refresh instructor_details relationship to get latest status

            $activeSubscription = app(\App\Services\SubscriptionService::class)
                ->getActiveSubscription($user);
            $user->setRelation('activeSubscription', $activeSubscription);

            // Convert user to array to avoid model casting issues
            $userData = $user->toArray();

            // Add custom fields
            $userData['is_instructor'] = $user->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'));
            $userData['instructor_process_status'] = $user->instructor_details->status ?? 'pending';

            // Convert wallet_balance to float to ensure it's returned as a number, not string
            $userData['wallet_balance'] = $user->wallet_balance ?? 0;

            if ($activeSubscription) {
                $userData['active_subscription_type'] = ucfirst($activeSubscription->plan->billing_cycle ?? 'unknown');
                $userData['active_subscription_plan_name'] = $activeSubscription->plan->name ?? null;
                $userData['active_subscription_days_left'] = $activeSubscription->days_remaining;
            } else {
                $userData['active_subscription_type'] = null;
                $userData['active_subscription_plan_name'] = null;
                $userData['active_subscription_days_left'] = null;
            }

            ApiResponseService::successResponse('User details retrieved successfully', $userData);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            ApiResponseService::errorResponse(exception: $th);
        }
    }

    /**
     * Update user profile (merged with instructor details)
     * If user is instructor, updates both user profile + instructor details
     * If user is regular user, updates only user profile
     */
    public function updateProfile(Request $request)
    {
        try {
            // Check if user is authenticated
            $authUser = $request->user();
            if (!$authUser) {
                return ApiResponseService::unauthorizedResponse();
            }

            $user = User::where(['id' => $authUser->id, 'is_active' => 1])->first();
            if (empty($user)) {
                return ApiResponseService::unauthorizedResponse();
            }

            // Check if user is instructor
            $isInstructor = $user->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'));

            // If user is instructor, check if account is suspended and get existing instructor data
            $existingInstructor = null;
            $existingInstructorPersonalDetail = null;
            $hasExistingTeamLogo = false;

            if ($isInstructor) {
                $existingInstructor = Instructor::where('user_id', $user->id)->first();
                if ($existingInstructor && $existingInstructor->status === 'suspended') {
                    return ApiResponseService::errorResponse(
                        'Your instructor account has been suspended. You cannot update your details.',
                    );
                }

                // Check if team_logo already exists
                if ($existingInstructor) {
                    $existingInstructorPersonalDetail = InstructorPersonalDetail::where(
                        'instructor_id',
                        $existingInstructor->id,
                    )->first();
                    if ($existingInstructorPersonalDetail && !empty($existingInstructorPersonalDetail->team_logo)) {
                        $hasExistingTeamLogo = true;
                    }
                }
            }

            // Get max video upload size from settings (in MB), default to 10MB
            // Convert MB to KB for Laravel validation (max rule uses KB)
            $maxVideoSize = HelperService::systemSettings('max_video_upload_size');
            $maxSizeMB = !empty($maxVideoSize) ? (float) $maxVideoSize : 10;
            $maxSizeKB = $maxSizeMB * 1024;

            // Alias avatar -> profile if sent by client
            if ($request->hasFile('avatar') && !$request->hasFile('profile')) {
                $request->files->set('profile', $request->file('avatar'));
            }

            // Build validation rules based on user type
            $validationRules = [
                'name' => 'sometimes|required|string|min:2|max:255',
                'email' => 'nullable|email|unique:users,email,' . Auth::id(),
                'mobile' => 'nullable|string|max:20|unique:users,mobile,' . Auth::id(),
                'country_calling_code' => ['nullable', 'string', 'regex:/^\+?[0-9]{1,4}$/'],
                'country_code' => 'nullable|string|size:2',
                'profile' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            ];

            // Add instructor-specific validation rules if user is instructor
            if ($isInstructor) {
                // Determine if team_logo should be required
                // Only require if instructor_type is "team" in the request AND there's no existing team_logo
                $instructorType = $request->input('instructor_type');
                $teamLogoRule = 'nullable|file|mimes:jpeg,png,jpg,gif,svg|max:2048';

                // Only require team_logo if:
                // 1. instructor_type is explicitly set to "team" in the request, AND
                // 2. there's no existing team_logo
                if ($instructorType === 'team' && !$hasExistingTeamLogo) {
                    $teamLogoRule = 'required|file|mimes:jpeg,png,jpg,gif,svg|max:2048';
                }

                $validationRules = array_merge($validationRules, [
                    'instructor_type' => 'nullable|in:individual,team',
                    'qualification' => 'nullable|string',
                    'years_of_experience' => 'nullable|numeric|min:0|max:100',
                    'skills' => 'nullable|string',
                    'bank_account_number' => 'nullable|string',
                    'bank_name' => 'nullable|string',
                    'bank_account_holder_name' => 'nullable|string',
                    'bank_ifsc_code' => 'nullable|string',
                    'team_name' => 'nullable|required_if:instructor_type,team|string',
                    'team_logo' => $teamLogoRule,
                    'about_me' => 'nullable|string',
                    'id_proof' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx|max:5120',
                    'preview_video' => 'nullable|file|mimes:mp4,mov,avi,wmv,flv,mpeg,mpg,m4v,webm|max:' . $maxSizeKB,
                    'social_medias' => 'nullable|array',
                    'social_medias.*.title' => 'nullable|string|max:255',
                    'social_medias.*.url' => 'nullable|url',
                    'other_details' => 'nullable|array',
                    'other_details.*.id' => 'nullable|exists:custom_form_fields,id',
                    'other_details.*.option_id' => 'nullable|exists:custom_form_field_options,id',
                    'other_details.*.value' => 'nullable|string',
                    'other_details.*.file' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,pdf,doc,docx,mp4,mov,avi,wmv,flv,mpeg,mpg,m4v,webm|max:5120',
                ]);
            }

            $allowedProfileFormats = 'JPEG, PNG, JPG, GIF, SVG, WEBP';
            $allowedTeamLogoFormats = 'JPEG, PNG, JPG, GIF, SVG';

            $customMessages = [
                'profile.file' => "The profile field must be an image. Allowed formats: {$allowedProfileFormats}.",
                'profile.mimes' => "The profile field must be an image. Allowed formats: {$allowedProfileFormats}.",
                'team_logo.mimes' => "The team logo field must be an image. Allowed formats: {$allowedTeamLogoFormats}.",
            ];

            $validator = Validator::make($request->all(), $validationRules, $customMessages);

            if ($validator->fails()) {
                $errors = $validator->errors();

                // Check if profile field has mimes or file validation error
                if ($errors->has('profile.mimes') || $errors->has('profile.file')) {
                    return ApiResponseService::validationError(
                        "The profile field must be an image. Allowed formats: {$allowedProfileFormats}.",
                    );
                }

                // Check if team_logo field has mimes validation error
                if ($errors->has('team_logo.mimes')) {
                    return ApiResponseService::validationError(
                        "The team logo field must be an image. Allowed formats: {$allowedTeamLogoFormats}.",
                    );
                }

                // Fallback: check if profile field has any error (for cases where error key format differs)
                if ($errors->has('profile')) {
                    $profileError = $errors->first('profile');
                    if (
                        str_contains($profileError, 'mimes')
                        || str_contains($profileError, 'file')
                        || str_contains($profileError, 'image')
                    ) {
                        return ApiResponseService::validationError(
                            "The profile field must be an image. Allowed formats: {$allowedProfileFormats}.",
                        );
                    }
                }

                return ApiResponseService::validationError($errors->first());
            }

            // Validate required custom form fields for instructor
            // Only validate if platform is 'web'
            // Mobile app (platform 'app') does not support custom fields yet
            $isWeb = strtolower($request->input('platform', 'app')) === 'web';

            if ($isInstructor && $isWeb) {
                // First, get all required custom form fields
                $requiredFields = CustomFormField::where('is_required', 1)->get();

                // Get submitted field IDs from other_details
                $submittedFieldIds = [];
                if ($request->has('other_details') && is_array($request->other_details)) {
                    foreach ($request->other_details as $otherDetail) {
                        if (!isset($otherDetail['id'])) {
                            continue;
                        }

                        $submittedFieldIds[] = $otherDetail['id'];
                    }
                }

                // Check if all required fields are present in the request
                foreach ($requiredFields as $requiredField) {
                    if (in_array($requiredField->id, $submittedFieldIds)) {
                        continue;
                    }

                    return ApiResponseService::validationError("The field '{$requiredField->name}' is required.");
                }

                // Validate that submitted required fields have values
                if ($request->has('other_details') && is_array($request->other_details)) {
                    foreach ($request->other_details as $index => $otherDetail) {
                        if (!isset($otherDetail['id'])) {
                            continue;
                        }

                        $customFormField = CustomFormField::find($otherDetail['id']);

                        if ($customFormField && $customFormField->is_required == 1) {
                            $fieldName = $customFormField->name;
                            $hasValue = false;

                            // Check if field has value based on its type
                            switch ($customFormField->type) {
                                case 'dropdown':
                                case 'radio':
                                case 'checkbox':
                                    // For dropdown, radio, checkbox - check if option_id is provided
                                    if (isset($otherDetail['option_id']) && !empty($otherDetail['option_id'])) {
                                        $hasValue = true;
                                    }
                                    break;

                                case 'file':
                                    // For file - check if file is uploaded
                                    if (
                                        isset($otherDetail['file'])
                                        && $request->hasFile("other_details.{$index}.file")
                                    ) {
                                        $hasValue = true;
                                    }
                                    break;

                                default:
                                    // For text, textarea, number, email - check if value is provided
                                    if (isset($otherDetail['value']) && !empty(trim((string) $otherDetail['value']))) {
                                        $hasValue = true;
                                    }
                                    break;
                            }

                            if (!$hasValue) {
                                return ApiResponseService::validationError("The field '{$fieldName}' is required.");
                            }
                        }
                    }
                }
            }

            // ============ UPDATE USER PROFILE ============
            $userData = [];
            foreach (['name', 'mobile', 'country_calling_code', 'country_code'] as $field) {
                if ($request->has($field)) {
                    $userData[$field] = $request->input($field);
                }
            }

            // Handle email update safely
            if ($request->has('email') && !empty($request->email)) {
                $normalizedEmail = strtolower(trim((string) $request->email));
                if ($normalizedEmail !== strtolower((string) $user->email)) {
                    $userData['email'] = $normalizedEmail;
                    $userData['email_verified_at'] = null;
                }
            }

            // Handle profile image upload safely (store first, delete old after)
            if ($request->hasFile('profile')) {
                $profileImage = $request->file('profile');
                $extension = $profileImage->getClientOriginalExtension() ?: 'jpg';
                $profileImageName = 'user_profile_' . $user->id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                $profileImagePath = $profileImage->storeAs('user_profile', $profileImageName, 'public');
                $userData['profile'] = $profileImagePath;

                // Delete old profile image AFTER new one is stored safely
                $oldProfile = $user->getRawOriginal('profile');
                if ($oldProfile && !filter_var($oldProfile, FILTER_VALIDATE_URL) && Storage::disk('public')->exists($oldProfile)) {
                    Storage::disk('public')->delete($oldProfile);
                }
            }

            $user->update($userData);

            // ============ UPDATE INSTRUCTOR DETAILS (if instructor) ============
            if ($isInstructor) {
                // Check if any instructor-related fields are sent
                $hasInstructorData =
                    $request->has('instructor_type')
                    || $request->has('qualification')
                    || $request->has('years_of_experience')
                    || $request->has('skills')
                    || $request->has('bank_account_number')
                    || $request->has('bank_name')
                    || $request->has('bank_account_holder_name')
                    || $request->has('bank_ifsc_code')
                    || $request->has('about_me')
                    || $request->has('team_name')
                    || $request->hasFile('team_logo')
                    || $request->hasFile('id_proof')
                    || $request->hasFile('preview_video')
                    || $request->has('social_medias')
                    || $request->has('other_details');

                if ($hasInstructorData) {
                    // Update or Create Instructor Data (only if instructor_type is provided)
                    if ($request->has('instructor_type')) {
                        // Get existing instructor to check current status
                        $existingInstructor = Instructor::where('user_id', $user->id)->first();

                        $instructorData = [
                            'type' => $request->instructor_type,
                        ];

                        // Handle status: preserve approved/suspended status, only change rejected to pending
                        // If status is already approved or suspended, don't change it
                        // Only set status to pending if current status is rejected or if it's a new record
                        if (!$existingInstructor) {
                            // New instructor record - set to pending
                            $instructorData['status'] = 'pending';
                        } elseif ($existingInstructor->status === 'rejected') {
                            // If rejected, change to pending for re-review
                            $instructorData['status'] = 'pending';
                        }
                        // If status is 'approved' or 'suspended', don't include status in $instructorData
                        // so updateOrCreate preserves the existing status

                        $instructor = Instructor::updateOrCreate(['user_id' => $user->id], $instructorData);
                    } else {
                        // Get existing instructor record
                        $instructor = Instructor::where('user_id', $user->id)->first();
                        if (!$instructor) {
                            // If no instructor record exists and no instructor_type provided, skip instructor details update
                            $instructor = null;
                        }
                    }

                    if ($instructor) {
                        // Update Personal Details
                        $instructorPersonalDetail = InstructorPersonalDetail::where(
                            'instructor_id',
                            $instructor->id,
                        )->first();
                        $personalDetailsData = [];
                        foreach ([
                            'qualification',
                            'years_of_experience',
                            'skills',
                            'bank_account_number',
                            'bank_name',
                            'bank_account_holder_name',
                            'bank_ifsc_code',
                            'team_name',
                            'about_me',
                        ] as $field) {
                            if ($request->has($field)) {
                                $personalDetailsData[$field] = $request->input($field);
                            }
                        }

                        // Handle file uploads
                        $instructorPersonalDetailFolder = 'instructor/personal_details';
                        if ($request->hasFile('team_logo')) {
                            $existingFile = !empty($instructorPersonalDetail)
                                ? $instructorPersonalDetail->getRawOriginal('team_logo')
                                : null;
                            $personalDetailsData['team_logo'] = FileService::compressAndReplace(
                                $request->team_logo,
                                $instructorPersonalDetailFolder,
                                $existingFile,
                            );
                        }
                        if ($request->hasFile('id_proof')) {
                            $existingFile = !empty($instructorPersonalDetail)
                                ? $instructorPersonalDetail->getRawOriginal('id_proof')
                                : null;
                            $personalDetailsData['id_proof'] = FileService::compressAndReplace(
                                $request->id_proof,
                                $instructorPersonalDetailFolder,
                                $existingFile,
                            );
                        }
                        if ($request->hasFile('preview_video')) {
                            $existingFile = !empty($instructorPersonalDetail)
                                ? $instructorPersonalDetail->getRawOriginal('preview_video')
                                : null;
                            $personalDetailsData['preview_video'] = FileService::compressAndReplace(
                                $request->preview_video,
                                $instructorPersonalDetailFolder,
                                $existingFile,
                            );
                        }

                        if ($personalDetailsData !== []) {
                            InstructorPersonalDetail::updateOrCreate([
                                'instructor_id' => $instructor->id,
                            ], $personalDetailsData);
                        }

                        // Update Social Media
                        if ($request->has('social_medias')) {
                            $socialMediaData = [];
                            foreach ((array) $request->social_medias as $socialMedia) {
                                if (!(!empty($socialMedia['title']) && !empty($socialMedia['url']))) {
                                    continue;
                                }

                                $socialMediaData[] = [
                                    'instructor_id' => $instructor->id,
                                    'title' => $socialMedia['title'],
                                    'url' => $socialMedia['url'],
                                ];
                            }
                            InstructorSocialMedia::where('instructor_id', $instructor->id)->delete();
                            if (!empty($socialMediaData)) {
                                InstructorSocialMedia::upsert($socialMediaData, ['instructor_id', 'title'], ['url']);
                            }
                        }

                        // Update Other Details
                        if ($request->has('other_details')) {
                            $otherDetailsData = [];
                            $instructorOtherDetailsOptionsFolder = 'instructor/other_details_options';

                            foreach ($request->other_details as $index => $otherDetail) {
                                $customFormField = CustomFormField::find($otherDetail['id']);
                                if (!$customFormField) {
                                    continue;
                                }

                                $baseData = [
                                    'instructor_id' => $instructor->id,
                                    'custom_form_field_id' => $customFormField->id,
                                    'custom_form_field_option_id' => null,
                                    'value' => null,
                                    'extension' => null,
                                ];

                                switch ($customFormField->type) {
                                    case 'dropdown':
                                    case 'checkbox':
                                    case 'radio':
                                        $option = CustomFormFieldOption::where([
                                            'id' => $otherDetail['option_id'] ?? null,
                                            'custom_form_field_id' => $customFormField->id,
                                        ])->first();
                                        if ($option) {
                                            $baseData['custom_form_field_option_id'] = $option->id;
                                            $baseData['value'] = $option->option; // Store option value in value field
                                        }
                                        break;

                                    case 'file':
                                        $fileData = InstructorOtherDetail::where([
                                            'instructor_id' => $instructor->id,
                                            'custom_form_field_id' => $customFormField->id,
                                        ])->first();

                                        $existingFile = null;
                                        if (!empty($fileData)) {
                                            $existingFile = $fileData->getRawOriginal('value');
                                        }

                                        if ($request->hasFile("other_details.{$index}.file")) {
                                            $uploadedFile = $request->file("other_details.{$index}.file");
                                            $baseData['value'] = FileService::compressAndReplace(
                                                $uploadedFile,
                                                $instructorOtherDetailsOptionsFolder,
                                                $existingFile,
                                            );
                                            $baseData['extension'] = $uploadedFile->getClientOriginalExtension();
                                        } elseif ($fileData !== null) {
                                            $baseData['value'] = $existingFile;
                                            $baseData['extension'] = $fileData->extension;
                                        }
                                        break;

                                    default:
                                        $baseData['value'] = $otherDetail['value'] ?? null;
                                        break;
                                }

                                $otherDetailsData[] = $baseData;
                            }

                            if (!empty($otherDetailsData)) {
                                InstructorOtherDetail::upsert(
                                    $otherDetailsData,
                                    ['instructor_id', 'custom_form_field_id'],
                                    ['value', 'custom_form_field_option_id', 'extension'],
                                );
                            }
                        }
                    }
                }
            }

            // Refresh user data to get updated profile URL
            $user->refresh();

            // Load same relationships as getUserDetails API
            $user = User::where(['id' => $user->id, 'is_active' => 1])->with([
                'instructor_details.personal_details',
                'instructor_details.social_medias.social_media',
                'instructor_details.other_details.custom_form_field',
                'instructor_details.other_details.custom_form_field_option',
            ])->first();

            // Add same fields as getUserDetails API
            $user['is_instructor'] = $user->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'));
            $user['instructor_process_status'] = $user->instructor_process_status;

            $responseMessage = $isInstructor
                ? 'Profile and instructor details updated successfully'
                : 'Profile updated successfully';

            return ApiResponseService::successResponse($responseMessage, $user);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse(exception: $th);
        }
    }

    /**
     * Change user password
     */
    public function changePassword(Request $request)
    {
        try {
            // Support parameter aliases: current_password -> old_password, password_confirmation -> new_password_confirmation
            if ($request->filled('current_password') && !$request->filled('old_password')) {
                $request->merge(['old_password' => $request->input('current_password')]);
            }
            if ($request->filled('password_confirmation') && !$request->filled('new_password_confirmation')) {
                $request->merge(['new_password_confirmation' => $request->input('password_confirmation')]);
            }

            $validator = Validator::make($request->all(), [
                'old_password' => 'required|string',
                'new_password' => 'required|string|min:8|confirmed',
                'new_password_confirmation' => 'required|string|min:8',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = User::where(['id' => Auth::id(), 'is_active' => 1])->first();
            if (empty($user)) {
                return ApiResponseService::validationError('المستخدم غير موجود');
            }

            // Refresh user to ensure we have latest password from database
            $user->refresh();

            // Check if user has a password set
            if (empty($user->password)) {
                return ApiResponseService::validationError(
                    'لا يمكنك تغيير كلمة المرور مباشرة. يرجى استخدام استعادة كلمة المرور لإنشاء كلمة مرور جديدة.',
                );
            }

            // Verify old password (trim to handle whitespace issues)
            $oldPassword = trim($request->old_password);
            if (empty($oldPassword) || !Hash::check($oldPassword, $user->password)) {
                return ApiResponseService::validationError('كلمة المرور الحالية غير صحيحة');
            }

            // Check if new password is different from old password
            if (Hash::check($request->new_password, $user->password)) {
                return ApiResponseService::validationError('يجب أن تكون كلمة المرور الجديدة مختلفة عن كلمة المرور الحالية');
            }

            // Update password in database
            $user->update([
                'password' => Hash::make($request->new_password),
            ]);

            // Update password in Firebase if user has Firebase account
            $socialLogin = SocialLogin::where('user_id', $user->id)->where('type', 'email')->first();
            if ($socialLogin && !empty($socialLogin->firebase_id)) {
                try {
                    HelperService::updateFirebasePassword($socialLogin->firebase_id, $request->new_password);
                } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    Log::error('Failed to update Firebase password: ' . $e->getMessage());
                }
            }

            // Revoke all tokens & devices to logout user from all remote sessions
            $user->tokens()->delete();
            \App\Models\UserDevice::where('user_id', $user->id)->delete();

            return ApiResponseService::successResponse(
                'تم تغيير كلمة المرور بنجاح. يرجى تسجيل الدخول مجدداً بكلمة المرور الجديدة.',
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse(exception: $th);
        }
    }

    /**
     * Get user notifications
     */
    public function getUserNotifications(Request $request)
    {
        try {
            $authenticatedUser = Auth::user();

            if (!$authenticatedUser) {
                return ApiResponseService::validationError('User not found');
            }

            // Check if user_team_slug is provided for team notifications
            if ($request->filled('user_team_slug')) {
                // Get the team user by slug
                /** @var User */
                $teamUser = User::where('slug', $request->user_team_slug)->first();
                if (!$teamUser) {
                    return ApiResponseService::validationError('Team user not found');
                }

                // Check if authenticated user is in the same team as the team user
                $authenticatedUserInstructorId = $authenticatedUser->instructor_details->id ?? null;
                $teamUserInstructorId = $teamUser->instructor_details->id ?? null;

                if (!$authenticatedUserInstructorId || !$teamUserInstructorId) {
                    return ApiResponseService::validationError('User or team user is not an instructor');
                }

                // Check if both users are in the same team (either as instructor or team member)
                $isInSameTeam = false;

                // Check if authenticated user is the team user's instructor
                if ($authenticatedUserInstructorId == $teamUserInstructorId) {
                    $isInSameTeam = true;
                } else {
                    // Check if both users are team members of the same instructor
                    $authenticatedUserTeam = \App\Models\TeamMember::where('user_id', $authenticatedUser->id)
                        ->where('status', 'approved')
                        ->first();
                    $teamUserTeam = \App\Models\TeamMember::where('user_id', $teamUser->id)
                        ->where('status', 'approved')
                        ->first();

                    if (
                        $authenticatedUserTeam
                        && $teamUserTeam
                        && $authenticatedUserTeam->instructor_id == $teamUserTeam->instructor_id
                    ) {
                        $isInSameTeam = true;
                    }
                }

                if (!$isInSameTeam) {
                    return ApiResponseService::unauthorizedResponse(
                        'You are not authorized to view this team\'s notifications',
                    );
                }

                // Use team user for notifications
                $user = $teamUser;
            } else {
                // Use authenticated user for notifications
                $user = User::where(['id' => $authenticatedUser->id, 'is_active' => 1])->first();
                if (empty($user)) {
                    return ApiResponseService::validationError('User not found');
                }
            }

            // Get pagination parameters
            $validator = Validator::make($request->query(), [
                'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
                'page' => ['nullable', 'integer', 'min:1'],
                'type' => ['nullable', Rule::in(['all', 'global', 'personal'])],
                'status' => ['nullable', Rule::in(['all', 'read', 'unread'])],
            ]);
            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $validated = $validator->validated();
            $perPage = (int) ($validated['per_page'] ?? 10);
            $page = (int) ($validated['page'] ?? 1);
            $type = $validated['type'] ?? 'all';
            $status = $validated['status'] ?? 'all';

            $notifications = collect();
            $globalTotal = 0;
            $personalTotal = 0;
            $sourceFetchLimit = max(1, (int) $page) * (int) $perPage;
            Carbon::setLocale('ar');

            $pickArabic = static function (array $data, string $enKey, string $arKey, string $fallback): string {
                $ar = $data[$arKey] ?? null;
                if (is_string($ar) && trim($ar) !== '') {
                    return $ar;
                }
                $en = $data[$enKey] ?? null;
                if (is_string($en) && trim($en) !== '' && $en !== 'Notification') {
                    return $en;
                }
                return $fallback;
            };

            $getNotificationIcon = static function ($type): string {
                $type = is_string($type) ? $type : 'default';
                return match ($type) {
                    // Courses & learning content
                    'course', 'new_course'                                               => 'fa-book',
                    'certificate', 'certificate_issued'                                  => 'fa-certificate',
                    'exam', 'quiz'                                                       => 'fa-clipboard-list',
                    'assignment', 'homework'                                             => 'fa-file-alt',
                    // Instructors
                    'instructor', 'new_instructor',
                    'instructor_submission', 'instructor_status_update'                  => 'fa-chalkboard-teacher',
                    // Team
                    'team_invitation', 'team_member', 'team_invitation_response'         => 'fa-users',
                    // Wallet & payments
                    'wallet'                                                              => 'fa-wallet',
                    'withdrawal'                                                          => 'fa-money-bill-wave',
                    'commission_paid'                                                     => 'fa-coins',
                    'purchase', 'payment', 'order',
                    'manual_deposit', 'admin_new_manual_deposit'                         => 'fa-money-check-alt',
                    // Subscriptions
                    'subscription', 'manual_subscription_status',
                    'admin_new_subscription_request'                                     => 'fa-id-card',
                    'subscription_expiry'                                                 => 'fa-clock',
                    // Messages
                    'message', 'chat', 'reply'                                           => 'fa-envelope',
                    // Webinars & live sessions
                    'live_class', 'webinar', 'zoom',
                    'webinar_registration', 'webinar_reminder'                           => 'fa-video',
                    // Announcements & system
                    'announcement', 'system', 'global', 'admin_manual'                  => 'fa-bullhorn',
                    // Welcome
                    'welcome'                                                             => 'fa-door-open',
                    // Promotions
                    'promotion', 'offer', 'campaign'                                     => 'fa-gift',
                    // Support
                    'support', 'ticket'                                                  => 'fa-headset',
                    // Reviews
                    'review', 'rating'                                                   => 'fa-star',
                    // Fallback
                    default                                                               => 'fa-bell',
                };
            };

            // Get global notifications (legacy_notifications table)
            if ($type === 'all' || $type === 'global') {
                // Get all read notification IDs for this user
                $readNotificationRows = \App\Models\UserNotificationRead::where('user_id', $user->id)
                    ->get(['notification_id', 'read_at', 'hidden_at'])
                    ->keyBy('notification_id');
                $hiddenNotificationIds = $readNotificationRows
                    ->filter(static fn ($row): bool => $row->hidden_at !== null)
                    ->keys()
                    ->all();
                $visibleReadNotificationRows = $readNotificationRows
                    ->filter(static fn ($row): bool => $row->hidden_at === null);
                $readNotificationIds = $visibleReadNotificationRows->keys()->all();

                // Only show notifications sent after user registration date
                $userRegistrationDate = $user->created_at ?? now();

                $globalNotificationsQuery = \App\Models\Notification::where('date_sent', '>=', $userRegistrationDate)
                    ->whereNotIn('id', $hiddenNotificationIds);

                if ($status === 'read') {
                    $globalNotificationsQuery->whereIn('id', $readNotificationIds);
                } elseif ($status === 'unread') {
                    $globalNotificationsQuery->whereNotIn('id', $readNotificationIds);
                }

                $globalTotal = (clone $globalNotificationsQuery)->count();
                $globalNotifications = $globalNotificationsQuery
                    ->orderBy('date_sent', 'desc')
                    ->limit($sourceFetchLimit)
                    ->get()
                    ->map(static function ($notification) use ($visibleReadNotificationRows, $getNotificationIcon) {
                        $slug = null;

                        // Get slug for course or instructor notification types
                        if ($notification->type === 'course' && $notification->type_id) {
                            $course = Course::find($notification->type_id);
                            $slug = $course->slug ?? null;
                        } elseif ($notification->type === 'instructor' && $notification->type_id) {
                            $instructor = Instructor::with('user')->find($notification->type_id);
                            $slug = $instructor->user->slug ?? null;
                        }

                        // Check if this notification is read
                        $readRecord = $visibleReadNotificationRows->get($notification->id);
                        $isRead = $readRecord !== null;

                        return [
                            'id'                => $notification->id,
                            'type'              => 'global',
                            'title'             => $notification->title,
                            'message'           => $notification->message,
                            'notification_type' => $notification->type,
                            'type_id'           => $notification->type_id,
                            'type_link'         => $notification->type_link,
                            'slug'              => $slug,
                            'image'             => $notification->image,
                            'icon'              => $getNotificationIcon($notification->type),
                            'icon_id'           => null,
                            'icon_color'        => null,
                            'date_sent'         => $notification->date_sent,
                            'date_sent_formatted' => $notification->date_sent->format('Y-m-d H:i:s'),
                            'time_ago'          => $notification->date_sent->diffForHumans(),
                            'is_read'           => $isRead,
                            'read_at'           => $readRecord ? $readRecord->read_at->format('Y-m-d H:i:s') : null,
                        ];
                    });

                $notifications = $notifications->merge($globalNotifications);
            }

            // Get personal notifications (Laravel notifications table)
            if ($type === 'all' || $type === 'personal') {
                // Get personal notifications via the Eloquent relationship
                $personalNotificationsQuery = $user->notifications();

                // Apply status filter
                if ($status === 'read') {
                    $personalNotificationsQuery->whereNotNull('read_at');
                } elseif ($status === 'unread') {
                    $personalNotificationsQuery->whereNull('read_at');
                }

                $personalTotal = (clone $personalNotificationsQuery)->count();
                $personalNotificationsRaw = $personalNotificationsQuery
                    ->orderByDesc('created_at')
                    ->limit($sourceFetchLimit)
                    ->get();

                $personalNotifications = $personalNotificationsRaw->map(static function ($notification) use ($getNotificationIcon, $pickArabic) {
                    // Decode data if it's a string (JSON)
                    $data = is_string($notification->data)
                        ? json_decode($notification->data, true)
                        : $notification->data;
                    $data = is_array($data) ? $data : [];
                    $rawType = $data['type'] ?? 'default';
                    $notificationType = is_string($rawType) ? $rawType : 'default';
                    $typeId = $data['type_id'] ?? null;
                    $slug = null;
                    $instructorDetails = null;
                    $teamMembers = [];

                    // Get slug for course or instructor notification types
                    if ($notificationType === 'course' && $typeId) {
                        $course = Course::find($typeId);
                        $slug = $course->slug ?? null;
                    } elseif ($notificationType === 'instructor' && $typeId) {
                        $instructor = Instructor::with('user')->find($typeId);
                        $slug = $instructor->user->slug ?? null;
                    } elseif ($notificationType === 'team_invitation' && $typeId) {
                        // Get team member from type_id
                        $teamMember = \App\Models\TeamMember::with([
                            'instructor.user',
                            'instructor.personal_details',
                            'instructor.social_medias',
                        ])->find($typeId);

                        if ($teamMember && $teamMember->instructor) {
                            $instructor = $teamMember->instructor;

                            // Get instructor details - simplified structure
                            $instructorDetails = [
                                'id' => $instructor->id,
                                'user_id' => $instructor->user_id,
                                'name' => $instructor->user->name ?? '',
                                'slug' => $instructor->user->slug ?? '',
                                'profile' => $instructor->user->profile ?? '',
                            ];

                            // Get only the specific team member (single object, not array)
                            $teamMembers = [
                                'id' => $teamMember->id,
                                'instructor_id' => $teamMember->instructor_id,
                                'user_id' => $teamMember->user_id,
                                'status' => $teamMember->status,
                                'created_at' => $teamMember->created_at
                                    ? $teamMember->created_at->format('Y-m-d H:i:s')
                                    : null,
                                'updated_at' => $teamMember->updated_at
                                    ? $teamMember->updated_at->format('Y-m-d H:i:s')
                                    : null,
                            ];
                        }
                    }

                    // Parse created_at and read_at timestamps
                    $createdAt = $notification->created_at
                        ? (
                            is_string($notification->created_at)
                                ? \Carbon\Carbon::parse($notification->created_at)
                                : $notification->created_at
                        )
                        : now();
                    $readAt = $notification->read_at
                        ? (
                            is_string($notification->read_at)
                                ? \Carbon\Carbon::parse($notification->read_at)
                                : $notification->read_at
                        )
                        : null;

                    $response = [
                        'id'                => $notification->id,
                        'type'              => 'personal',
                        'title'             => $pickArabic($data, 'title', 'title_ar', 'إشعار'),
                        'message'           => $pickArabic($data, 'message', 'message_ar', (string) ($data['body'] ?? '')),
                        'notification_type' => $notificationType,
                        'type_id'           => $typeId,
                        'type_link'         => $data['type_link'] ?? $data['link'] ?? $data['action_url'] ?? null,
                        'slug'              => $slug,
                        'image'             => $data['image'] ?? null,
                        'icon'              => $getNotificationIcon($notificationType),
                        'icon_id'           => $data['icon'] ?? null,
                        'icon_color'        => $data['icon_color'] ?? null,
                        'date_sent'         => $createdAt,
                        'date_sent_formatted' => $createdAt->format('Y-m-d H:i:s'),
                        'time_ago'          => $createdAt->diffForHumans(),
                        'is_read'           => !is_null($readAt),
                        'read_at'           => $readAt ? $readAt->format('Y-m-d H:i:s') : null,
                    ];

                    // Add instructor details and team members for team_invitation
                    if ($notificationType === 'team_invitation') {
                        $response['invitation_status'] = $teamMembers['status'] ?? 'pending';
                        $response['instructor_details'] = $instructorDetails;
                        $response['team_members'] = $teamMembers;
                    }

                    return $response;
                });

                $notifications = $notifications->merge($personalNotifications);
            }

            // Sort all notifications by date
            $notifications = $notifications->sortByDesc('date_sent');

            // Apply pagination
            $total = $globalTotal + $personalTotal;
            $notifications = $notifications->forPage($page, $perPage)->values()->toArray();

            // The dashboard, header and notifications page share one unread
            // definition instead of counting only their preferred table.
            $unreadCount = app(\App\Services\UserNotificationService::class)->unreadCount($user);

            // Create pagination links
            $lastPage = max(1, (int) ceil($total / max(1, (int) $perPage)));
            $baseUrl = request()->url();
            $path = str_replace(request()->root(), '', $baseUrl);

            // Build query parameters for URLs
            $queryParams = request()->query();
            unset($queryParams['page']); // Remove page from query params

            $firstPageUrl = $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => 1]));
            $lastPageUrl = $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => $lastPage]));
            $nextPageUrl = $page < $lastPage
                ? $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => $page + 1]))
                : null;
            $prevPageUrl = $page > 1
                ? $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => $page - 1]))
                : null;

            // Create pagination links array
            $links = [];

            // Previous link
            $links[] = [
                'url' => $prevPageUrl,
                'label' => '&laquo; Previous',
                'active' => false,
            ];

            // Page number links
            for ($i = 1; $i <= $lastPage; $i++) {
                $pageUrl = $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => $i]));
                $links[] = [
                    'url' => $pageUrl,
                    'label' => (string) $i,
                    'active' => $i == $page,
                ];
            }

            // Next link
            $links[] = [
                'url' => $nextPageUrl,
                'label' => 'Next &raquo;',
                'active' => false,
            ];

            $responseData = [
                'current_page' => (int) $page,
                'data' => $notifications,
                'first_page_url' => $firstPageUrl,
                'from' => $total > 0 ? (($page - 1) * $perPage) + 1 : 0,
                'last_page' => $lastPage,
                'last_page_url' => $lastPageUrl,
                'links' => $links,
                'next_page_url' => $nextPageUrl,
                'path' => $path,
                'per_page' => (int) $perPage,
                'prev_page_url' => $prevPageUrl,
                'to' => min($page * $perPage, $total),
                'total' => $total,
                'unread_count' => $unreadCount,
            ];

            return ApiResponseService::successResponse('تم تحميل الإشعارات بنجاح', $responseData, [
                'unread_count' => $unreadCount,
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse(exception: $th);
        }
    }

    /**
     * Mark notification as read
     */
    public function markNotificationAsRead(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'notification_id' => 'required',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $authenticatedUser = Auth::user();
            if (!$authenticatedUser) {
                return ApiResponseService::validationError('User not found');
            }

            // Check if user_team_slug is provided for team notifications
            if ($request->filled('user_team_slug')) {
                // Get the team user by slug
                $teamUser = User::where('slug', $request->user_team_slug)->first();
                if (!$teamUser) {
                    return ApiResponseService::validationError('Team user not found');
                }

                // Check if authenticated user is in the same team as the team user
                $authenticatedUserInstructorId = $authenticatedUser->instructor_details->id ?? null;
                $teamUserInstructorId = $teamUser->instructor_details->id ?? null;

                if (!$authenticatedUserInstructorId || !$teamUserInstructorId) {
                    return ApiResponseService::validationError('User or team user is not an instructor');
                }

                // Check if both users are in the same team (either as instructor or team member)
                $isInSameTeam = false;

                // Check if authenticated user is the team user's instructor
                if ($authenticatedUserInstructorId == $teamUserInstructorId) {
                    $isInSameTeam = true;
                } else {
                    // Check if both users are team members of the same instructor
                    $authenticatedUserTeam = \App\Models\TeamMember::where('user_id', $authenticatedUser->id)
                        ->where('status', 'approved')
                        ->first();
                    $teamUserTeam = \App\Models\TeamMember::where('user_id', $teamUser->id)
                        ->where('status', 'approved')
                        ->first();

                    if (
                        $authenticatedUserTeam
                        && $teamUserTeam
                        && $authenticatedUserTeam->instructor_id == $teamUserTeam->instructor_id
                    ) {
                        $isInSameTeam = true;
                    }
                }

                if (!$isInSameTeam) {
                    return ApiResponseService::unauthorizedResponse(
                        'You are not authorized to mark this team\'s notifications as read',
                    );
                }

                // Use team user for notifications
                $user = $teamUser;
            } else {
                // Use authenticated user for notifications
                $user = User::where(['id' => $authenticatedUser->id, 'is_active' => 1])->first();
                if (empty($user)) {
                    return ApiResponseService::validationError('User not found');
                }
            }

            $notificationIds = is_array($request->notification_id) ? $request->notification_id : [$request->notification_id];
            $markedCount = 0;
            $globalCount = 0;
            $errors = [];

            // Process each notification ID
            foreach ($notificationIds as $notificationId) {
                // Convert to string for consistent checking
                $notificationIdStr = (string) $notificationId;

                // Check if it's a personal notification (UUID) or global notification (integer)
                // Personal notifications use UUIDs, global notifications use integer IDs
                if (is_numeric($notificationIdStr) && ctype_digit($notificationIdStr)) {
                    // Global notification (integer ID)
                    $notificationIdInt = (int) $notificationIdStr;

                    // Check if notification exists
                    $globalNotification = \App\Models\Notification::find($notificationIdInt);
                    if ($globalNotification) {
                        // Check if already marked as read
                        $existingRead = \App\Models\UserNotificationRead::where('user_id', $user->id)
                            ->where('notification_id', $notificationIdInt)
                            ->first();

                        if (!$existingRead) {
                            // Mark as read by creating a record in user_notification_reads table
                            try {
                                \App\Models\UserNotificationRead::create([
                                    'user_id' => $user->id,
                                    'notification_id' => $notificationIdInt,
                                    'read_at' => now(),
                                ]);
                                $globalCount++;
                                $markedCount++; // Also increment marked_count for global notifications
                            } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                                throw $e;
                            } catch (\Exception $e) {
                                // If duplicate key error, notification is already read
                                if (
                                    str_contains($e->getMessage(), 'Duplicate entry')
                                    || str_contains($e->getMessage(), 'UNIQUE constraint')
                                ) {
                                    $globalCount++;
                                    $markedCount++; // Count as marked even if already read
                                } else {
                                    // Log other errors
                                    \Illuminate\Support\Facades\Log::error('Error marking global notification as read', [
                                        'user_id' => $user->id,
                                        'notification_id' => $notificationIdInt,
                                        'error' => $e->getMessage(),
                                        'trace' => $e->getTraceAsString(),
                                    ]);
                                    $errors[] =
                                        "Failed to mark notification {$notificationIdInt} as read: " . $e->getMessage();
                                }
                            }
                        } else {
                            $globalCount++; // Already read, but count it
                            $markedCount++; // Also count in marked_count
                        }
                    } else {
                        // Notification doesn't exist
                        $errors[] = "الإشعار رقم {$notificationIdInt} غير موجود";
                        \Illuminate\Support\Facades\Log::warning('Global notification not found', [
                            'notification_id' => $notificationIdInt,
                            'user_id' => $user->id,
                        ]);
                    }
                } else {
                    // Personal notification (UUID)
                    $notification = $user->notifications()->find($notificationIdStr);
                    if ($notification) {
                        try {
                            $notification->markAsRead();
                            $markedCount++;
                        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                            throw $e;
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::error('Error marking personal notification as read', [
                                'user_id' => $user->id,
                                'notification_id' => $notificationIdStr,
                                'error' => $e->getMessage(),
                            ]);
                            $errors[] = 'Failed to mark personal notification as read: ' . $e->getMessage();
                        }
                    } else {
                        $errors[] = "Personal notification ID {$notificationIdStr} not found";
                    }
                }
            }

            $message = 'تم تحديد الإشعارات كمقروءة';
            if ($markedCount > 0) {
                $message = "تم تحديد {$markedCount} إشعار كمقروء";
            } elseif (count($errors) > 0) {
                $message = 'لم يتم تحديد أي إشعارات كمقروءة. ' . implode(' ', $errors);
            }

            $responseData = [
                'marked_count' => $markedCount,
                'global_count' => $globalCount,
                'total_count' => $markedCount, // Total successfully marked notifications
            ];

            if (count($errors) > 0) {
                $responseData['errors'] = $errors;
            }

            return ApiResponseService::successResponse($message, $responseData);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse(exception: $th);
        }
    }

    /**
     * Mark all notifications as read
     */
    public function markAllNotificationsAsRead(Request $request)
    {
        try {
            $authenticatedUser = Auth::user();
            if (!$authenticatedUser) {
                return ApiResponseService::validationError('User not found');
            }

            // Check if user_team_slug is provided for team notifications
            if ($request->filled('user_team_slug')) {
                // Get the team user by slug
                $teamUser = User::where('slug', $request->user_team_slug)->first();
                if (!$teamUser) {
                    return ApiResponseService::validationError('Team user not found');
                }

                // Check if authenticated user is in the same team as the team user
                $authenticatedUserInstructorId = $authenticatedUser->instructor_details->id ?? null;
                $teamUserInstructorId = $teamUser->instructor_details->id ?? null;

                if (!$authenticatedUserInstructorId || !$teamUserInstructorId) {
                    return ApiResponseService::validationError('User or team user is not an instructor');
                }

                // Check if both users are in the same team (either as instructor or team member)
                $isInSameTeam = false;

                // Check if authenticated user is the team user's instructor
                if ($authenticatedUserInstructorId == $teamUserInstructorId) {
                    $isInSameTeam = true;
                } else {
                    // Check if both users are team members of the same instructor
                    $authenticatedUserTeam = \App\Models\TeamMember::where('user_id', $authenticatedUser->id)
                        ->where('status', 'approved')
                        ->first();
                    $teamUserTeam = \App\Models\TeamMember::where('user_id', $teamUser->id)
                        ->where('status', 'approved')
                        ->first();

                    if (
                        $authenticatedUserTeam
                        && $teamUserTeam
                        && $authenticatedUserTeam->instructor_id == $teamUserTeam->instructor_id
                    ) {
                        $isInSameTeam = true;
                    }
                }

                if (!$isInSameTeam) {
                    return ApiResponseService::unauthorizedResponse(
                        'You are not authorized to mark this team\'s notifications as read',
                    );
                }

                // Use team user for notifications
                $user = $teamUser;
            } else {
                // Use authenticated user for notifications
                $user = User::where(['id' => $authenticatedUser->id, 'is_active' => 1])->first();
                if (empty($user)) {
                    return ApiResponseService::validationError('User not found');
                }
            }

            // Mark all personal notifications as read
            $personalMarkedCount = $user->unreadNotifications()->count();
            $user->unreadNotifications()->update(['read_at' => now()]);

            // Mark all global notifications as read
            $allGlobalNotifications = \App\Models\Notification::where(
                'date_sent',
                '>=',
                $user->created_at ?? now(),
            )->pluck('id')->toArray();
            $alreadyReadGlobalIds = \App\Models\UserNotificationRead::where('user_id', $user->id)
                ->pluck('notification_id')
                ->toArray();

            $unreadGlobalIds = array_diff($allGlobalNotifications, $alreadyReadGlobalIds);
            $globalMarkedCount = 0;

            if (!empty($unreadGlobalIds)) {
                $now = now();
                $rows = [];
                foreach ($unreadGlobalIds as $notificationId) {
                    $rows[] = [
                        'user_id' => $user->id,
                        'notification_id' => $notificationId,
                        'read_at' => $now,
                    ];
                }
                foreach (array_chunk($rows, 100) as $chunk) {
                    \App\Models\UserNotificationRead::upsert(
                        $chunk,
                        ['user_id', 'notification_id'],
                        ['read_at']
                    );
                }
                $globalMarkedCount = count($unreadGlobalIds);
            }

            $totalMarked = $personalMarkedCount + $globalMarkedCount;
            $message = 'تم تحديد جميع الإشعارات كمقروءة';
            if ($totalMarked > 0) {
                $message = "تم تحديد {$totalMarked} إشعار كمقروء";
            }

            return ApiResponseService::successResponse($message, [
                'marked_count' => $totalMarked,
                'personal_count' => $personalMarkedCount,
                'global_count' => $globalMarkedCount,
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse(exception: $th);
        }
    }

    /**
     * Register or refresh the signed-in user's FCM device token.
     */
    public function registerFcmToken(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'fcm_token' => 'required|string|min:20|max:4096',
                'platform_type' => 'nullable|string|in:web,android,ios',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = Auth::user();
            if (!$user) {
                return ApiResponseService::unauthorizedResponse('يجب تسجيل الدخول.');
            }

            UserFcmToken::updateOrCreate(
                ['fcm_token' => $request->input('fcm_token')],
                [
                    'user_id' => $user->id,
                    'platform_type' => $request->input('platform_type', 'web'),
                ]
            );

            return ApiResponseService::successResponse('تم تسجيل جهاز الإشعارات.');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse('تعذر تسجيل رمز الإشعارات.', null, 500, $th);
        }
    }

    /**
     * Delete a personal in-app notification, or hide a global one for this user.
     */
    public function deleteUserNotification(Request $request, $id)
    {
        try {
            $user = User::where(['id' => Auth::id(), 'is_active' => 1])->first();
            if (empty($user)) {
                return ApiResponseService::validationError('المستخدم غير موجود');
            }

            $notificationIdStr = (string) $id;

            if (is_numeric($notificationIdStr) && ctype_digit($notificationIdStr)) {
                $notificationIdInt = (int) $notificationIdStr;
                $global = \App\Models\Notification::find($notificationIdInt);
                if (!$global) {
                    return ApiResponseService::errorResponse('الإشعار غير موجود', null, 404);
                }

                \App\Models\UserNotificationRead::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'notification_id' => $notificationIdInt,
                    ],
                    ['read_at' => now(), 'hidden_at' => now()]
                );

                return ApiResponseService::successResponse('تم إخفاء الإشعار');
            }

            $notification = $user->notifications()->find($notificationIdStr);
            if (!$notification) {
                return ApiResponseService::errorResponse('الإشعار غير موجود', null, 404);
            }

            $notification->delete();

            return ApiResponseService::successResponse('تم حذف الإشعار');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse('تعذر حذف الإشعار.', null, 500, $th);
        }
    }

    /**
     * Delete user account (soft delete)
     */
    public function deleteAccount(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'password' => 'nullable|string',
                'firebase_token' => 'nullable|string',
                'confirm_deletion' => 'required|in:true,false,1,0,"1","0",yes,no',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $user = User::where(['id' => Auth::id(), 'is_active' => 1])->first();
            if (empty($user)) {
                return ApiResponseService::validationError('User not found');
            }

            // Refresh user to ensure we have latest data from database
            $user->refresh();

            // Check if user has money in wallet
            if (abs((float) $user->wallet_balance) >= 0.01) {
                return ApiResponseService::validationError(
                    'You cannot delete your account because you have a remaining balance in your wallet. Please withdraw or spend your funds before deleting your account.',
                );
            }

            // Check if user has pending financial operations
            if (\App\Models\WithdrawalRequest::where('user_id', $user->id)
                ->whereIn('status', ['pending', 'processing'])
                ->exists()) {
                return ApiResponseService::validationError(
                    'You cannot delete your account while you have a pending withdrawal request. Please wait for it to be processed or cancel it before deleting your account.',
                );
            }

            if (\App\Models\RefundRequest::where('user_id', $user->id)
                ->whereIn('status', ['pending', 'processing'])
                ->exists()) {
                return ApiResponseService::validationError(
                    'You cannot delete your account while you have a pending refund request. Please wait for it to be processed before deleting your account.',
                );
            }

            // Check if user has confirmed deletion - handle different formats
            $confirmDeletion = $request->confirm_deletion;
            if (is_string($confirmDeletion)) {
                $confirmDeletion = in_array(strtolower($confirmDeletion), ['true', '1', 'yes', '"1"', '"true"']);
            }
            if (!in_array($confirmDeletion, [true, 1, '1', 'true', 'yes'], true)) {
                return ApiResponseService::validationError(
                    'You must confirm that you agree to permanently delete your account and all associated data.',
                );
            }

            // Require fresh proof through either the local password or the
            // currently authenticated Firebase identity. This supports linked
            // and passwordless email/mobile/social accounts consistently.
            $identityVerified = false;
            $password = trim((string) $request->input('password', ''));
            if ($password !== '' && !empty($user->password)) {
                $identityVerified = Hash::check($password, $user->password);
            }

            if (!$identityVerified && $request->filled('firebase_token')) {
                try {
                    $verifiedToken = ApiService::verifyFirebaseToken($request->firebase_token);
                    $firebaseId = $verifiedToken->claims()->get('sub');
                    $identityVerified = \App\Models\SocialLogin::where('user_id', $user->id)
                        ->where('firebase_id', $firebaseId)
                        ->exists();
                } catch (\Throwable) {
                    $identityVerified = false;
                }
            }

            if (!$identityVerified) {
                return ApiResponseService::validationError(
                    'Re-authentication is required. Enter your current password or sign in again with your linked provider.',
                );
            }

            $hasPendingGatewayPayment = \App\Models\SubscriptionPayment::where('user_id', $user->id)
                ->where('status', \App\Models\SubscriptionPayment::STATUS_PENDING)
                ->where('created_at', '>', now()->subHours(4))
                ->exists()
                || \App\Models\Order::where('user_id', $user->id)
                    ->where('status', 'pending')
                    ->where('created_at', '>', now()->subHours(4))
                    ->exists()
                || \App\Models\WebinarRegistration::where('user_id', $user->id)
                    ->where('payment_status', 'pending')
                    ->where(function ($query) {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->exists()
                || \App\Models\WalletTopUpAttempt::where('user_id', $user->id)
                    ->where('status', 'pending')
                    ->where(function ($query) {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->exists();

            if ($hasPendingGatewayPayment) {
                return ApiResponseService::validationError(
                    'You cannot delete your account while an online payment is still pending. Complete it or wait for the checkout session to expire.',
                );
            }

            if (\App\Models\ManualDeposit::where('user_id', $user->id)
                ->where('status', 'pending')
                ->exists()) {
                return ApiResponseService::validationError(
                    'You cannot delete your account while a wallet deposit is awaiting review.',
                );
            }

            // Remove every linked Firebase identity before deleting its local links.
            if (\App\Models\SocialLogin::where('user_id', $user->id)->whereNotNull('firebase_id')->exists()) {
                try {
                    $socialLogins = \App\Models\SocialLogin::where('user_id', $user->id)
                        ->whereNotNull('firebase_id')
                        ->get();

                    foreach ($socialLogins as $socialLogin) {
                        if (empty($socialLogin->firebase_id)) {
                            continue;
                        }

                        try {
                            // Delete user from Firebase
                            \App\Services\HelperService::removeUserFromFirebase($socialLogin->firebase_id);
                        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                            throw $e;
                        } catch (\Throwable $firebaseError) {
                            // Log Firebase deletion error but continue with database deletion
                            Log::warning('Failed to delete Firebase user during account deletion', [
                                'user_id' => $user->id,
                                'firebase_id' => $socialLogin->firebase_id,
                                'error' => $firebaseError->getMessage(),
                            ]);
                        }
                    }
                } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    // Log error but continue with database deletion
                    Log::warning('Error during Firebase account deletion', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Start database transaction
            DB::beginTransaction();

            try {
                // Soft delete user account (set deleted_at and is_active)
                // Use forceFill to bypass mass assignment protection for deleted_at
                $user->forceFill([
                    'deleted_at' => now(),
                    'is_active' => 0,
                ])->save();

                // Delete user's personal notifications
                $user->notifications()->delete();

                // Delete user's course enrollments and progress
                \App\Models\Course\UserCourseTrack::where('user_id', $user->id)->delete();
                \App\Models\UserCurriculumTracking::where('user_id', $user->id)->delete();

                // Delete user's quiz attempts
                \App\Models\Course\CourseChapter\Quiz\UserQuizAttempt::where('user_id', $user->id)->delete();

                // Delete user's assignment submissions
                \App\Models\Course\CourseChapter\Assignment\UserAssignmentSubmission::where(
                    'user_id',
                    $user->id,
                )->delete();

                // Delete user's wishlist items
                \App\Models\Wishlist::where('user_id', $user->id)->delete();

                // Delete user's cart items
                \App\Models\Cart::where('user_id', $user->id)->delete();

                // Note: Financial and transactional audit records (Order, WalletHistory, WithdrawalRequest, RefundRequest)
                // are preserved for legal, tax, and accounting reconciliation tied to the soft-deleted user.

                // Delete user's ratings
                \App\Models\Rating::where('user_id', $user->id)->delete();

                // Delete user's search history
                \App\Models\SearchHistory::where('user_id', $user->id)->delete();

                // Delete user's FCM tokens
                \App\Models\UserFcmToken::where('user_id', $user->id)->delete();

                // Delete user's social login records
                \App\Models\SocialLogin::where('user_id', $user->id)->delete();

                // Stop renewals and queued activations before the user becomes inactive.
                // Payment/audit rows remain intact for reconciliation.
                \App\Models\Subscription::where('user_id', $user->id)
                    ->whereNotIn('status', [
                        \App\Models\Subscription::STATUS_EXPIRED,
                        \App\Models\Subscription::STATUS_CANCELLED,
                    ])
                    ->update([
                        'status' => \App\Models\Subscription::STATUS_CANCELLED,
                        'auto_renew' => false,
                        'cancelled_at' => now(),
                        'cancellation_reason' => 'Account deleted by user',
                    ]);

                // Delete user's team memberships
                \App\Models\TeamMember::where('user_id', $user->id)->delete();

                // Delete user's course discussions and replies
                \App\Models\Course\CourseDiscussion::where('user_id', $user->id)->delete();

                // If user is an instructor, handle instructor-specific data
                if ($user->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))) {
                    // Get instructor record
                    $instructor = \App\Models\Instructor::where('user_id', $user->id)->first();

                    if ($instructor) {
                        // Soft delete instructor's courses (courses are linked via user_id, not instructor_id)
                        \App\Models\Course\Course::where('user_id', $user->id)->update(['deleted_at' => now()]);

                        // Also remove instructor from course_instructors pivot table
                        \App\Models\CourseInstructor::where('user_id', $user->id)->delete();

                        // Delete instructor's personal details
                        \App\Models\InstructorPersonalDetail::where('instructor_id', $instructor->id)->delete();
                        \App\Models\InstructorOtherDetail::where('instructor_id', $instructor->id)->delete();
                        \App\Models\InstructorSocialMedia::where('instructor_id', $instructor->id)->delete();

                        // Soft delete instructor record
                        $instructor->update(['deleted_at' => now()]);
                    }
                }

                // Commit transaction
                DB::commit();

                // Revoke all tokens & devices for this user
                $user->tokens()->delete();
                \App\Models\UserDevice::where('user_id', $user->id)->delete();

                return ApiResponseService::successResponse(
                    'Your account has been deactivated and personal access has been removed. Financial records are retained where legally required.',
                );
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                throw $e;
            } catch (\Exception $e) {
                // Rollback transaction on error
                DB::rollback();
                throw $e;
            }
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            // Log the actual error for debugging
            Log::error('Delete account error: ' . $th->getMessage(), [
                'user_id' => Auth::id(),
                'trace' => $th->getTraceAsString(),
            ]);
            return ApiResponseService::errorResponse(
                'Failed to delete account. Please try again later.',
                exception: $th,
            );
        }
    }

    /**
     * Get user's support center conversations
     */
    public function getMyContactMessages(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::errorResponse('Unauthenticated', null, 401);
        }

        $validator = Validator::make($request->all(), [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);
        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $perPage = (int) $request->input('per_page', 15);
        
        $messages = \App\Models\ContactMessage::where('user_id', $user->id)
            ->withCount(['replies as unread_count' => function ($query) {
                $query->where('sender_type', 'admin')->where('is_read', false);
            }])
            ->withMax('replies as last_reply_at', 'created_at')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Format for the frontend requirement
        $formatted = $messages->map(function ($msg) {
            return [
                'id' => $msg->id,
                'subject' => $msg->subject ?: \Illuminate\Support\Str::limit($msg->message, 50),
                'status' => $msg->status,
                'unread_count' => $msg->unread_count,
                'created_at' => $msg->created_at,
                'last_reply_at' => $msg->last_reply_at ?: $msg->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted,
            'pagination' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'total' => $messages->total(),
            ]
        ]);
    }

    /**
     * Get a single conversation thread
     */
    public function getContactMessageThread(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::errorResponse('Unauthenticated', null, 401);
        }

        $message = \App\Models\ContactMessage::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$message) {
            return ApiResponseService::errorResponse('Conversation not found', code: 404);
        }

        // Mark unread admin replies as read
        \App\Models\ContactMessageReply::where('contact_message_id', $message->id)
            ->where('sender_type', 'admin')
            ->where('is_read', false)
            ->update(['is_read' => true]);

        // Get replies
        $replies = \App\Models\ContactMessageReply::where('contact_message_id', $message->id)
            ->orderBy('created_at', 'asc')
            ->get();

        $conversation = [];
        
        // Add the initial message as the first item in the conversation
        $conversation[] = [
            'id' => 0, // Using 0 or a virtual ID for the parent message
            'sender' => 'user',
            'message' => $message->message,
            'created_at' => $message->created_at,
        ];

        // If there's a legacy reply_message on the parent, add it
        if ($message->reply_message) {
            $conversation[] = [
                'id' => -1,
                'sender' => 'admin',
                'message' => $message->reply_message,
                'created_at' => $message->updated_at,
            ];
        }

        foreach ($replies as $reply) {
            $conversation[] = [
                'id' => $reply->id,
                'sender' => $reply->sender_type,
                'message' => $reply->message,
                'created_at' => $reply->created_at,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $message->id,
                'subject' => $message->subject ?: \Illuminate\Support\Str::limit($message->message, 50),
                'status' => $message->status,
                'created_at' => $message->created_at,
                'conversation' => $conversation,
            ]
        ]);
    }

    /**
     * Reply to a conversation
     */
    public function replyContactMessage(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseService::errorResponse('Unauthenticated', null, 401);
        }

        $message = \App\Models\ContactMessage::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$message) {
            return ApiResponseService::errorResponse('Conversation not found', code: 404);
        }

        if (in_array($message->status, ['closed', 'completed'])) {
            return ApiResponseService::errorResponse('Cannot reply to a closed conversation', code: 403);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|min:2|max:5000',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        $cleanReply = trim(strip_tags((string) $request->message));
        if (mb_strlen($cleanReply) < 2) {
            return ApiResponseService::validationError('Reply must contain at least two visible characters.');
        }

        \App\Models\ContactMessageReply::create([
            'contact_message_id' => $message->id,
            'user_id' => $user->id,
            'sender_type' => 'user',
            'message' => $cleanReply,
            'is_read' => false,
        ]);

        $message->update(['status' => 'waiting_admin']);

        // Optional: Notify admin here

        return response()->json(['success' => true]);
    }

    /**
     * Submit contact us form
     */
    public function submitContactForm(Request $request)
    {
        try {
            // Authenticated support-center submissions already have a trusted
            // identity. Guests still need to provide both contact fields.
            $authUser = Auth::guard('sanctum')->user();
            $validator = Validator::make($request->all(), [
                'first_name' => ($authUser ? 'nullable' : 'required') . '|string|max:255',
                'email'      => ($authUser ? 'nullable' : 'required') . '|email|max:255',
                'subject'    => 'nullable|string|max:255',
                'message'    => 'required|string|max:2000',
                'phone'      => 'nullable|string|max:30',
                'message_type' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $cleanMessage = trim(strip_tags((string) $request->message));
            if ($cleanMessage === '') {
                return ApiResponseService::validationError('Message must contain visible text.');
            }

            $allowedCustomKeys = CustomFormField::query()
                ->pluck('name')
                ->filter()
                ->map(static fn ($name) => (string) $name)
                ->all();
            $metadataKeys = array_values(array_unique([
                'phone',
                'message_type',
                ...$allowedCustomKeys,
            ]));
            $metadataKeys = array_values(array_diff($metadataKeys, [
                'first_name', 'name', 'email', 'subject', 'message',
            ]));
            $metadata = [];
            foreach (array_slice($metadataKeys, 0, 30) as $key) {
                $value = $request->input($key);
                if (!is_scalar($value)) {
                    continue;
                }

                $rawValue = trim(strip_tags((string) $value));
                if (mb_strlen($rawValue) > 2000) {
                    return ApiResponseService::validationError("{$key} may not be greater than 2000 characters.");
                }
                $cleanValue = $rawValue;
                if ($cleanValue !== '') {
                    $metadata[$key] = $cleanValue;
                }
            }

            // Save to database
            $contactMessage = \App\Models\ContactMessage::create([
                'user_id'    => $authUser?->id,
                'first_name' => $authUser?->name ?: trim(strip_tags((string) $request->first_name)),
                'email'      => $authUser?->email ?: strtolower(trim((string) $request->email)),
                'subject'    => trim(strip_tags((string) $request->input('subject', ''))) ?: null,
                'message'    => $cleanMessage,
                'metadata'   => $metadata ?: null,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'status'     => 'new',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($authUser) {
                \App\Models\UserNotification::create([
                    'user_id' => $authUser->id,
                    'contact_message_id' => $contactMessage->id,
                    'type' => 'support_message',
                    'title' => 'تم إرسال رسالتك',
                    'message' => 'تم استلام رسالتك وسيتم الرد عليك قريبًا',
                    'url' => '/messages?ticket=' . $contactMessage->id,
                ]);
            }

            $appName = \App\Services\HelperService::systemSettings('app_name') ?? 'LMS';

            // 🔔 Notify all admins (in-app + FCM push)
            try {
                $admins = \App\Models\User::role(config('constants.SYSTEM_ROLES.SUPER_ADMIN', 'Super Admin'))
                    ->where('is_active', 1)
                    ->get();

                foreach ($admins as $admin) {
                    $admin->notify(new \App\Notifications\AdminNewContactMessageNotification(
                        $contactMessage,
                        $appName,
                    ));
                }
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                throw $e;
            } catch (\Exception $e) {
                Log::error('submitContactForm: Failed to notify admins', [
                    'contact_message_id' => $contactMessage->id,
                    'error'              => $e->getMessage(),
                ]);
            }

            // Send email notification to admin
            try {
                $adminEmail = \App\Services\HelperService::systemSettings('admin_email');
                if (empty($adminEmail)) {
                    $adminEmail = 'admin@example.com';
                }

                Mail::queue(
                    'emails.contact-form',
                    [
                        'contactMessage' => $contactMessage,
                        'appName'        => $appName,
                    ],
                    static function ($message) use ($adminEmail, $appName, $contactMessage): void {
                        $message->to($adminEmail)->subject('New Contact Form Submission - ' . $appName)->replyTo(
                            $contactMessage->email,
                            $contactMessage->first_name,
                        );
                    },
                );
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
                throw $e;
            } catch (\Exception $e) {
                Log::error('Failed to send contact form email: ' . $e->getMessage());
                // Don't fail the request if email fails
            }

            // Log the contact message
            Log::info('Contact form submission saved:', [
                'id'           => $contactMessage->id,
                'user_id'      => $contactMessage->user_id,
                'first_name'   => $contactMessage->first_name,
                'email'        => $contactMessage->email,
                'ip_address'   => $contactMessage->ip_address,
                'submitted_at' => $contactMessage->created_at,
            ]);

            return ApiResponseService::successResponse(
                'Your message has been sent successfully! We will get back to you soon.',
                ['id' => $contactMessage->id],
            );
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse('Failed to send message. Please try again later.', exception: $th);
        }
    }

    // this method get all categories
    public function getCategories(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'id' => 'nullable|exists:categories,id',
                'slug' => 'nullable|exists:categories,slug',
                'get_subcategory' => 'nullable|boolean',
                'get_parent_category' => 'nullable|boolean',
                'is_featured' => 'nullable|boolean',
                'per_page' => 'nullable|integer|min:1',
                'page' => 'nullable|integer|min:1',
            ]);

            if ($validator->fails()) {
                ApiResponseService::validationError($validator->errors()->first());
            }

            $categoryQuery = Category::select(
                'id',
                'name',
                'image',
                'parent_category_id',
                'description',
                'status',
                'slug',
                'sequence',
                'is_featured',
            )
                ->withCount(['subcategories' => static function ($q): void {
                    $q->where('status', 1);
                }])
                ->withCount(['parent_category' => static function ($q): void {
                    $q->where('status', 1);
                }])
                ->selectRaw('(SELECT COUNT(DISTINCT courses.id) FROM courses
                        WHERE courses.category_id IN (
                            SELECT cat.id FROM categories cat
                            WHERE (cat.id = categories.id
                            OR cat.parent_category_id = categories.id
                            OR cat.parent_category_id IN (
                                SELECT subcat.id FROM categories subcat
                                WHERE subcat.parent_category_id = categories.id
                            ))
                            AND cat.status = 1
                            AND cat.deleted_at IS NULL
                        )
                        AND courses.is_active = 1
                        AND courses.status = "publish"
                        AND courses.approval_status = "approved"
                        AND courses.deleted_at IS NULL
                        AND EXISTS (
                            SELECT 1 FROM users
                            WHERE users.id = courses.user_id
                            AND users.is_active = 1
                            AND users.deleted_at IS NULL
                        )) as courses_count')
                ->where('status', 1)
                ->when(static function ($query) use ($request): void {
                    if ($request->has('id')) {
                        $query->where('id', $request->id);
                        if ($request->has('get_subcategory') && $request->get_subcategory == 1) {
                            $query->with(['subcategories' => static function ($subQuery): void {
                                $subQuery->orderByRaw('CASE WHEN sequence IS NULL THEN 1 ELSE 0 END')->orderBy(
                                    'sequence',
                                    'ASC',
                                );
                            }]);
                        } else if ($request->has('get_parent_category') && $request->get_parent_category == 1) {
                            $query->with('parent_category');
                        }
                    } else if ($request->has('slug')) {
                        $query->where('slug', $request->slug);
                        if ($request->has('get_subcategory') && $request->get_subcategory == 1) {
                            $query->with(['subcategories' => static function ($subQuery): void {
                                $subQuery->orderByRaw('CASE WHEN sequence IS NULL THEN 1 ELSE 0 END')->orderBy(
                                    'sequence',
                                    'ASC',
                                );
                            }]);
                        } else if ($request->has('get_parent_category') && $request->get_parent_category == 1) {
                            $query->with('parent_category');
                        }
                    } else if (!$request->has('is_featured')) {
                        $query->whereNull('parent_category_id');
                    }

                    if ($request->has('is_featured')) {
                        $query->where('is_featured', $request->boolean('is_featured'));
                    }
                })
                ->orderByRaw('CASE WHEN sequence IS NULL THEN 1 ELSE 0 END')
                ->orderBy('sequence', 'ASC');

            // Get paginated results
            $perPage = $request->per_page ?? 15;
            $categories = $categoryQuery->paginate($perPage);

            // Load first 2 courses for each category (recursive)
            $categories->getCollection()->transform(function ($category) {
                // Get IDs of this category and its subcategories (up to 2 levels deep)
                $categoryIds = \App\Models\Category::where('id', $category->id)
                    ->orWhere('parent_category_id', $category->id)
                    ->orWhereIn('parent_category_id', function ($query) use ($category) {
                        $query->select('id')->from('categories')->where('parent_category_id', $category->id);
                    })
                    ->pluck('id');

                $category->courses = \App\Models\Course\Course::whereIn('category_id', $categoryIds)
                    ->where('is_active', 1)
                    ->where('status', 'publish')
                    ->where('approval_status', 'approved')
                    ->select('id', 'title', 'thumbnail', 'short_description', 'category_id', 'intro_video', 'intro_video_type')
                    ->take(2)
                    ->get()
                    ->map(function ($course) use ($category) {
                        return [
                            'id' => $course->id,
                            'title' => $course->title,
                            'image' => $course->thumbnail, // Accessor handles URL
                            'description' => $course->short_description,
                            'intro_video' => $course->intro_video, // Accessor handles URL
                            'category' => $category->name,
                        ];
                    });
                return $category;
            });

            if ($request->has('is_featured')) {
                if ($categories->isEmpty()) {
                    return response()->json(['data' => []]);
                }
                
                return response()->json([
                    'data' => $categories->getCollection()->values()->toArray(),
                    'meta' => [
                        'current_page' => $categories->currentPage(),
                        'last_page' => $categories->lastPage(),
                        'per_page' => $categories->perPage(),
                        'total' => $categories->total(),
                    ],
                ]);
            }

            if ($categories->isEmpty()) {
                return response()->json(['data' => []]);
            }

            return ApiResponseService::successResponse('Categories retrieved successfully', $categories);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            return ApiResponseService::errorResponse(exception: $th);
        }
    }

    public function getSubCategories(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'nullable|exists:categories,id',
            'slug' => 'nullable|exists:categories,slug',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            // get categories with subcategory
            $categoryQuery = Category::select(
                'id',
                'name',
                'image',
                'parent_category_id',
                'description',
                'status',
                'slug',
            )
                ->withCount(['subcategories' => static function ($q): void {
                    $q->where('status', 1); // sub with active
                }])
                ->where(['status' => 1])
                ->orderBy('sequence', 'ASC')
                ->when(static function ($query) use ($request): void {
                    if ($request->has('category_id')) {
                        $query->where('parent_category_id', $request->category_id);
                    }
                    if ($request->has('slug')) {
                        $query->where('slug', $request->slug);
                    }
                }, static function ($query): void {
                    $query->whereNull('parent_category_id');
                })
                ->with([
                    // subcategories (level 1)
                    'subcategories' => static function ($query): void {
                        $query
                            ->select(
                                'id',
                                'sequence',
                                'name',
                                'image',
                                'parent_category_id',
                                'description',
                                'status',
                                'slug',
                            )
                            ->where('status', 1)
                            ->orderBy('sequence', 'ASC')
                            ->withCount(['subcategories' => static function ($q): void {
                                $q->where('status', 1);
                            }]);
                    },
                    //subcategories (level 2) - subcategories of subcategories
                    'subcategories.subcategories' => static function ($query): void {
                        $query
                            ->select(
                                'id',
                                'sequence',
                                'name',
                                'image',
                                'parent_category_id',
                                'description',
                                'status',
                                'slug',
                            )
                            ->where('status', 1)
                            ->orderBy('sequence', 'ASC')
                            ->withCount(['subcategories' => static function ($q): void {
                                $q->where('status', 1);
                            }]);
                    },
                    //subcategories (level 3) - subcategories of subcategories of subcategories
                    'subcategories.subcategories.subcategories' => static function ($query): void {
                        $query
                            ->select(
                                'id',
                                'sequence',
                                'name',
                                'image',
                                'parent_category_id',
                                'description',
                                'status',
                                'slug',
                            )
                            ->where('status', 1)
                            ->orderBy('sequence', 'ASC')
                            ->withCount(['subcategories' => static function ($q): void {
                                $q->where('status', 1);
                            }]);
                    },
                ]);

            // Get paginated results
            $perPage = $request->per_page ?? 15;
            $subcategories = $categoryQuery->paginate($perPage);
            ApiResponseService::successResponse(null, $subcategories);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            ApiResponseService::errorResponse(exception: $th);
        }
    }

    public function getCustomFormFields(Request $request)
    {
        try {
            $customFormFields = CustomFormField::select('id', 'name', 'type', 'is_required', 'sort_order')
                ->whereNull('deleted_at') // Only get active (non-deleted) fields
                ->with(['options' => static function ($query): void {
                    $query->select('id', 'custom_form_field_id', 'option')->whereNull('deleted_at'); // Only get active (non-deleted) options
                }])
                ->orderBy('sort_order')
                ->get();
            ApiResponseService::successResponse('Data Fetched Successfully', $customFormFields);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller ->getCustomFormFields');
            ApiResponseService::errorResponse();
        }
    }

    public function removeUser(Request $request)
    {
        if (!app()->environment(['local', 'testing'])) {
            ApiResponseService::errorResponse('هذا الإجراء غير متاح.', null, 403);
        }

        $actor = Auth::user();
        if ($actor === null || !$actor->hasRole('Super Admin')) {
            ApiResponseService::errorResponse('غير مصرح.', null, 403);
        }

        ApiService::validateRequest($request, [
            'firebase_token' => 'nullable',
        ]);
        try {
            $firebaseId = null;
            if ($request->has('firebase_token')) {
                $firebaseId = ApiService::removeUserFromFirebase($request->firebase_token);
            }
            $userID = SocialLogin::where('firebase_id', $firebaseId)->first();
            if (!empty($userID)) {
                $user = User::find($userID->user_id);
                if (!empty($user)) {
                    $user->forceDelete();
                }
            }
            ApiResponseService::successResponse('User removed successfully');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            ApiResponseService::errorResponse(exception: $e);
        }
    }
}
