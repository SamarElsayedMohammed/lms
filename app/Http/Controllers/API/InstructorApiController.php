<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Commission;
use App\Models\Course\Course;
use App\Models\Course\CourseChapter\Assignment\UserAssignmentSubmission;
use App\Models\CustomFormField;
use App\Models\CustomFormFieldOption;
use App\Models\Instructor;
use App\Models\InstructorOtherDetail;
use App\Models\InstructorPersonalDetail;
use App\Models\InstructorSocialMedia;
use App\Models\PromoCode;
use App\Models\PromoCodeCourse;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WalletHistory;
use App\Notifications\TeamInvitationNotification;
use App\Notifications\TeamInvitationResponseNotification;
use App\Services\ApiResponseService;
use App\Services\CommissionService;
use App\Services\FileService;
use App\Services\HelperService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class InstructorApiController extends Controller
{
    private $instructorPersonalDetailFolder = 'instructor/personal_details';
    private $instructorOtherDetailsFolder = 'instructor/other_details';
    private $instructorOtherDetailsOptionsFolder = 'instructor/other_details_options';

    public function updateDetails(Request $request)
    {
        // This method is already defined here as updateDetails(Request $request)
        // To add this method to api.php (routes), you would add a route like:
        // Route::post('instructor/update-details', [InstructorApiController::class, 'updateDetails']);
        // But in this controller, nothing needs to be added at this point.
        try {
            // Check if instructor is suspended - if so, prevent updates
            $instructor = Instructor::where('user_id', Auth::user()?->id)->first();
            if ($instructor && $instructor->status === 'suspended') {
                return ApiResponseService::errorResponse(
                    'Your instructor account has been suspended. You cannot update your details.',
                );
            }

            // Get max video upload size from settings (in MB), default to 10MB
            // Convert MB to KB for Laravel validation (max rule uses KB)
            $maxVideoSize = HelperService::systemSettings('max_video_upload_size');
            $maxSizeMB = !empty($maxVideoSize) ? (float) $maxVideoSize : 10;
            $maxSizeKB = $maxSizeMB * 1024;

            // Validate Request
            $validator = Validator::make($request->all(), [
                'instructor_type' => 'required|in:individual,team',
                'qualification' => 'nullable|string',
                'years_of_experience' => 'nullable|numeric|min:0|max:100',
                'skills' => 'nullable|string',
                'bank_account_number' => 'nullable|string',
                'bank_name' => 'nullable|string',
                'bank_account_holder_name' => 'nullable|string',
                'bank_ifsc_code' => 'nullable|string',
                'team_name' => 'nullable|required_if:instructor_type,team|string',
                'team_logo' => 'nullable|required_if:instructor_type,team|file|mimes:jpeg,png,jpg,gif,svg|max:2048',
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
            if ($validator->fails()) {
                ApiResponseService::validationError($validator->errors()->first());
            }

            // Validate required custom form fields
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

                ApiResponseService::validationError("The field '{$requiredField->name}' is required.");
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
                                if (isset($otherDetail['file']) && $request->hasFile("other_details.{$index}.file")) {
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
                            ApiResponseService::validationError("The field '{$fieldName}' is required.");
                        }
                    }
                }
            }
            /***************************************************************************************************** */
            // Add Instructor Data
            $instructorData = [
                'type' => $request->instructor_type,
            ];

            // Handle status: if rejected, change to pending; if approved, keep as is (don't change)
            // Only set status in instructorData if it needs to be changed (rejected -> pending)
            // If status is approved or any other status, don't include it so updateOrCreate preserves existing status
            if ($instructor && $instructor->status === 'rejected') {
                $instructorData['status'] = 'pending';
            }

            // Check if this is first entry (instructor didn't exist before)
            $isFirstEntry = !$instructor;

            // Update or Create Instructor Data
            $instructor = Instructor::updateOrCreate(['user_id' => Auth::user()?->id], $instructorData);

            // If this is first entry, notify all admins
            if ($isFirstEntry) {
                $user = Auth::user();
                $admins = \App\Models\User::role(config('constants.SYSTEM_ROLES.ADMIN'))->get();
                foreach ($admins as $admin) {
                    $admin->notify(new \App\Notifications\InstructorSubmissionNotification($instructor, $user));
                }
            }
            /***************************************************************************************************** */

            $instructorPersonalDetail = InstructorPersonalDetail::where('instructor_id', $instructor->id)->first();

            // Add Personal Details Data
            $personalDetailsData = [
                'qualification' => $request->qualification,
                'years_of_experience' => $request->years_of_experience,
                'skills' => $request->skills,
                'bank_account_number' => $request->bank_account_number,
                'bank_name' => $request->bank_name,
                'bank_account_holder_name' => $request->bank_account_holder_name,
                'bank_ifsc_code' => $request->bank_ifsc_code,
                'team_name' => $request->team_name,
                'about_me' => $request->about_me,
            ];

            // Check Files and Upload
            if ($request->hasFile('team_logo')) {
                $existingFile = !empty($instructorPersonalDetail)
                    ? $instructorPersonalDetail->getRawOriginal('team_logo')
                    : null;
                $personalDetailsData['team_logo'] = FileService::compressAndReplace(
                    $request->team_logo,
                    $this->instructorPersonalDetailFolder,
                    $existingFile,
                );
            }
            if ($request->hasFile('id_proof')) {
                $existingFile = !empty($instructorPersonalDetail)
                    ? $instructorPersonalDetail->getRawOriginal('id_proof')
                    : null;
                $personalDetailsData['id_proof'] = FileService::compressAndReplace(
                    $request->id_proof,
                    $this->instructorPersonalDetailFolder,
                    $existingFile,
                );
            }
            if ($request->hasFile('preview_video')) {
                $existingFile = !empty($instructorPersonalDetail)
                    ? $instructorPersonalDetail->getRawOriginal('preview_video')
                    : null;
                $personalDetailsData['preview_video'] = FileService::compressAndReplace(
                    $request->preview_video,
                    $this->instructorPersonalDetailFolder,
                    $existingFile,
                );
            }

            // Update or Create Personal Details
            InstructorPersonalDetail::updateOrCreate(['instructor_id' => $instructor->id], $personalDetailsData);
            /***************************************************************************************************** */

            // Add Social Media Data
            if ($request->has('social_medias') && !empty($request->social_medias)) {
                $socialMediaData = [];
                foreach ($request->social_medias as $socialMedia) {
                    if (!(!empty($socialMedia['title']) && !empty($socialMedia['url']))) {
                        continue;
                    }

                    $socialMediaData[] = [
                        'instructor_id' => $instructor->id,
                        'title' => $socialMedia['title'],
                        'url' => $socialMedia['url'],
                    ];
                }
                if (!empty($socialMediaData)) {
                    // Update Instructor Social Media Data
                    InstructorSocialMedia::upsert($socialMediaData, ['instructor_id', 'title'], ['url']);
                }
            }
            /***************************************************************************************************** */

            // Add Other Details Data
            if ($request->has('other_details')) {
                $otherDetailsData = [];

                foreach ($request->other_details as $otherDetail) {
                    $customFormField = CustomFormField::find($otherDetail['id']);

                    if (!$customFormField) {
                        ApiResponseService::validationError(
                            'Custom form field ID :- ' . $otherDetail['id'] . ' not found',
                        );
                    }

                    // Base Data Array
                    $baseData = [
                        'instructor_id' => $instructor->id,
                        'custom_form_field_id' => $customFormField->id,
                        'custom_form_field_option_id' => null,
                        'value' => null,
                        'extension' => null,
                    ];

                    // Check type and create base data array
                    switch ($customFormField->type) {
                        case 'dropdown':
                        case 'checkbox':
                        case 'radio':
                            $option = CustomFormFieldOption::where([
                                'id' => $otherDetail['option_id'] ?? null,
                                'custom_form_field_id' => $customFormField->id,
                            ])->first();

                            if (!$option) {
                                ApiResponseService::validationError('Custom form field option not found');
                            }

                            $baseData['custom_form_field_option_id'] = $option->id;
                            $baseData['value'] = $option->option; // Store option value in value field
                            break;

                        case 'file':
                            $fileData = InstructorOtherDetail::where([
                                'instructor_id' => $instructor->id,
                                'custom_form_field_id' => $customFormField->id,
                            ])->with('custom_form_field')->first();

                            $existingFile = null;
                            if (!empty($fileData)) {
                                $existingFile = $fileData->getRawOriginal('value');
                            }

                            $baseData['value'] = FileService::compressAndReplace(
                                $otherDetail['file'],
                                $this->instructorOtherDetailsOptionsFolder,
                                $existingFile,
                            );
                            $baseData['extension'] = $otherDetail['file']->getClientOriginalExtension();
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

            /***************************************************************************************************** */

            ApiResponseService::successResponse('Instructor details updated successfully');
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to update instructor details');
            ApiResponseService::errorResponse('Failed to update instructor details');
        }
    }

    /**
     * Add Team Members
     */
    public function addTeamMember(Request $request)
    {
        // In single instructor mode, return error
        if (\App\Services\InstructorModeService::isSingleInstructorMode()) {
            return ApiResponseService::validationError('Team management is disabled in Single Instructor mode.');
        }

        $validator = Validator::make($request->all(), [
            'member_email' => 'required|email',
        ]);
        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        try {
            $loggedInUser = Auth::user();

            // Check if user is instructor
            if (!$loggedInUser->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))) {
                return ApiResponseService::validationError('You are not authorized to add team members');
            }

            // Get instructor record
            $instructor = Instructor::where('user_id', $loggedInUser?->id)->first();

            if (!$instructor) {
                return ApiResponseService::validationError('Instructor profile not found');
            }

            // ❌ Restrict individual instructors
            if ($instructor->type === 'individual') {
                return ApiResponseService::validationError('Individual instructors cannot add team members');
            }

            // Check if user exists
            $user = User::where('email', $request->member_email)->first();
            if (!$user) {
                return ApiResponseService::validationError('User not found');
            }

            // Prevent instructor from adding himself
            if ($loggedInUser->id === $user->id) {
                return ApiResponseService::validationError('You cannot add yourself as a team member');
            }

            // Check if there's any existing team member record for this user/instructor combination
            $existingTeamMember = TeamMember::where([
                'user_id' => $user->id,
                'instructor_id' => $instructor->id,
            ])->first();

            if ($existingTeamMember) {
                // Already approved - cannot re-add
                if ($existingTeamMember->status === 'approved') {
                    return ApiResponseService::validationError('User already exists in your team');
                }

                // Pending or rejected - update to pending and resend invitation
                $token = \Illuminate\Support\Str::random(64);
                $existingTeamMember->update([
                    'status' => 'pending',
                    'invitation_token' => $token,
                    'updated_at' => now(),
                ]);
                $existingTeamMember->refresh();

                // Send invitation email
                try {
                    $appName = \App\Services\HelperService::systemSettings('app_name') ?? 'LMS';
                    $acceptUrl = url('/api/accept-team-invitation/' . $token);
                    $rejectUrl = url('/api/accept-team-invitation/' . $token);

                    \Illuminate\Support\Facades\Mail::send(
                        'emails.team-invitation',
                        [
                            'user' => $user,
                            'instructor' => $loggedInUser,
                            'acceptUrl' => $acceptUrl,
                            'rejectUrl' => $rejectUrl,
                            'appName' => $appName,
                        ],
                        static function ($message) use ($user, $appName): void {
                            $message->to($user->email, $user->name)->subject('Team Invitation - ' . $appName);
                        },
                    );
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Failed to send team invitation email: ' . $e->getMessage());
                }

                // Send notification
                try {
                    $notification = new TeamInvitationNotification($loggedInUser, $existingTeamMember);
                    $user->notify($notification);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Failed to send team invitation notification: '
                    . $e->getMessage(), [
                        'trace' => $e->getTraceAsString(),
                        'user_id' => $user->id ?? null,
                    ]);
                }

                return ApiResponseService::successResponse('Invitation sent successfully.');
            }

            // Create pending invitation
            $teamMember = TeamMember::create([
                'instructor_id' => $instructor->id,
                'user_id' => $user->id,
                'status' => 'pending',
            ]);

            // Generate invitation token
            $token = \Illuminate\Support\Str::random(64);
            $teamMember->update(['invitation_token' => $token]);
            $teamMember->refresh(); // Refresh to get updated token

            // Send invitation email
            try {
                $appName = \App\Services\HelperService::systemSettings('app_name') ?? 'LMS';
                $acceptUrl = url('/api/accept-team-invitation/' . $token);
                $rejectUrl = url('/api/accept-team-invitation/' . $token);

                \Illuminate\Support\Facades\Mail::send(
                    'emails.team-invitation',
                    [
                        'user' => $user,
                        'instructor' => $loggedInUser,
                        'acceptUrl' => $acceptUrl,
                        'rejectUrl' => $rejectUrl,
                        'appName' => $appName,
                    ],
                    static function ($message) use ($user, $appName): void {
                        $message->to($user->email, $user->name)->subject('Team Invitation - ' . $appName);
                    },
                );
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send team invitation email: ' . $e->getMessage());

                // Don't fail the request if email fails, but log it
            }

            // Send notification to user about team invitation (send immediately, not queued)
            try {
                $notification = new TeamInvitationNotification($loggedInUser, $teamMember);

                // Use notifyNow to ensure immediate save (bypasses queue)
                $user->notifyNow($notification);

                // Alternative: Direct database insert if notifyNow fails
                $notificationType = TeamInvitationNotification::class;
                $notificationData = $notification->toArray($user);

                // Check if notification was created
                $existingNotification = DB::table('notifications')
                    ->where('notifiable_type', User::class)
                    ->where('notifiable_id', $user->id)
                    ->where('type', $notificationType)
                    ->where('created_at', '>=', now()->subSeconds(5))
                    ->first();

                if (!$existingNotification) {
                    // Direct insert as fallback
                    try {
                        $maxId = DB::table('notifications')->max('id') ?? 0;

                        DB::table('notifications')->insert([
                            'id' => $maxId + 1,
                            'type' => $notificationType,
                            'notifiable_type' => User::class,
                            'notifiable_id' => $user->id,
                            'data' => json_encode($notificationData, JSON_UNESCAPED_UNICODE),
                            'read_at' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        \Illuminate\Support\Facades\Log::info('Team invitation notification created via direct insert', [
                            'user_id' => $user->id,
                            'notification_id' => $maxId + 1,
                        ]);
                    } catch (\Exception $insertError) {
                        \Illuminate\Support\Facades\Log::error('Failed to insert notification directly: '
                        . $insertError->getMessage(), [
                            'user_id' => $user->id,
                            'error' => $insertError->getMessage(),
                            'trace' => $insertError->getTraceAsString(),
                        ]);
                    }
                } else {
                    \Illuminate\Support\Facades\Log::info('Team invitation notification created successfully via notifyNow', [
                        'user_id' => $user->id,
                        'notification_id' => $existingNotification->id,
                    ]);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send team invitation notification: '
                . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'user_id' => $user->id ?? null,
                    'instructor_id' => $loggedInUser->id ?? null,
                ]);

                // Don't fail the request if notification fails, but log it
            }

            return ApiResponseService::successResponse(
                'Invitation sent successfully. User will be added to team after accepting the invitation.',
            );
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to add team member');
            return ApiResponseService::errorResponse('Failed to add team member');
        }
    }

    /**
     * Accept or Reject Team Invitation
     * Supports both GET (for email links with token in URL) and POST (for API calls with invitation_token in body)
     */
    public function acceptTeamInvitation(Request $request, #[\SensitiveParameter] $token = null)
    {
        try {
            // Get invitation token - from URL for GET, from POST body for POST
            if ($request->isMethod('post')) {
                // POST request - get invitation_token from request body
                $validator = Validator::make($request->all(), [
                    'invitation_token' => 'required|string',
                    'action' => 'required|in:accept,reject',
                ]);

                if ($validator->fails()) {
                    return ApiResponseService::validationError($validator->errors()->first());
                }

                $invitationToken = $request->input('invitation_token');
                $action = $request->input('action');
            } else {
                // GET request - token comes from URL parameter
                if (!$token) {
                    return ApiResponseService::validationError('Invitation token is required');
                }

                $invitationToken = $token;
                // GET request - check query parameter or default
                $action = $request->query('action', 'accept'); // Default to accept for email links

                if (!in_array($action, ['accept', 'reject'])) {
                    $action = 'accept'; // Fallback to accept if invalid
                }
            }

            // Find the invitation by token
            $teamMember = TeamMember::where('invitation_token', $invitationToken)->where('status', 'pending')->first();

            if (!$teamMember) {
                return ApiResponseService::validationError('Invalid or expired invitation token');
            }

            // Get the user
            $user = User::find($teamMember->user_id);
            if (!$user) {
                return ApiResponseService::validationError('User not found');
            }

            // Verify the logged-in user is the one who received the invitation
            $loggedInUser = Auth::user();
            if (!$loggedInUser || $loggedInUser->id !== $user->id) {
                return ApiResponseService::validationError('You are not authorized to perform this action');
            }

            // Get the instructor who sent the invitation
            $instructor = Instructor::find($teamMember->instructor_id);
            $instructorUser = $instructor ? User::find($instructor->user_id) : null;

            if ($action === 'accept') {
                // Check if user has instructor role
                if (!$user->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))) {
                    return ApiResponseService::validationError(
                        'You must have an Instructor role to accept this invitation. Please contact the administrator to get the Instructor role first.',
                    );
                }

                // Update status to approved
                $teamMember->update([
                    'status' => 'approved',
                    'invitation_token' => null, // Clear token after acceptance
                ]);

                // Create instructor record for the team member if it doesn't exist
                Instructor::updateOrCreate(['user_id' => $user->id], [
                    'status' => 'approved',
                ]);

                // Send notification to instructor about acceptance
                if ($instructorUser) {
                    try {
                        $instructorUser->notify(new TeamInvitationResponseNotification($user, 'accepted', $teamMember));
                    } catch (Exception $e) {
                        Log::error('Failed to send team invitation acceptance notification: ' . $e->getMessage());
                    }
                }

                return ApiResponseService::successResponse(
                    'Team invitation accepted successfully. You have been added to the team.',
                );
            } else {
                // Reject the invitation
                $teamMember->update([
                    'status' => 'rejected',
                    'invitation_token' => null, // Clear token after rejection
                ]);

                // Send notification to instructor about rejection
                if ($instructorUser) {
                    try {
                        $instructorUser->notify(new TeamInvitationResponseNotification($user, 'rejected', $teamMember));
                    } catch (Exception $e) {
                        Log::error('Failed to send team invitation rejection notification: ' . $e->getMessage());
                    }
                }

                return ApiResponseService::successResponse('Team invitation rejected successfully.');
            }
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to process team invitation');
            return ApiResponseService::errorResponse('Failed to process team invitation');
        }
    }

    /**
     * Get Pending Team Invitations for Logged-in User
     * This API allows users to see their pending team invitations with tokens
     */
    public function getPendingInvitations(Request $request)
    {
        try {
            $loggedInUser = Auth::user();

            if (!$loggedInUser) {
                return ApiResponseService::validationError('User not authenticated');
            }
            $user_id = $loggedInUser->id;
            // Get all pending invitations for this user
            $pendingInvitations = TeamMember::where('user_id', $loggedInUser->id)
                ->where('status', 'pending')
                ->with([
                    'instructor' => static function ($query): void {
                        $query->with(['user' => static function ($userQuery): void {
                            $userQuery->select('id', 'name', 'email', 'slug', 'profile');
                        }]);
                    },
                ])
                ->orderBy('created_at', 'desc')
                ->get();

            if ($pendingInvitations->isEmpty()) {
                return ApiResponseService::successResponse('No pending invitations found', []);
            }

            // Transform data to include invitation details
            $invitations = $pendingInvitations->map(static function ($invitation) {
                $instructor = $invitation->instructor;
                $instructorUser = $instructor ? $instructor->user : null;

                return [
                    'id' => $invitation->id,
                    'invitation_token' => $invitation->invitation_token,
                    'status' => $invitation->status,
                    'created_at' => $invitation->created_at,
                    'accept_url' => $invitation->invitation_token
                        ? url('/api/accept-team-invitation/' . $invitation->invitation_token)
                        : null,
                    'reject_url' => $invitation->invitation_token
                        ? url('/api/accept-team-invitation/' . $invitation->invitation_token)
                        : null,
                    'instructor' => $instructorUser
                        ? [
                            'id' => $instructorUser->id,
                            'name' => $instructorUser->name,
                            'email' => $instructorUser->email,
                            'slug' => $instructorUser->slug,
                            'profile' => $instructorUser->profile,
                        ] : null,
                    'instructor_details' => $instructor
                        ? [
                            'id' => $instructor->id,
                            'type' => $instructor->type,
                            'status' => $instructor->status,
                        ] : null,
                ];
            });

            return ApiResponseService::successResponse('Pending invitations fetched successfully', [
                'invitations' => $invitations,
                'total' => $invitations->count(),
            ]);
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to get pending invitations');
            return ApiResponseService::errorResponse('Failed to get pending invitations');
        }
    }

    /**
     * Remove Team Member
     */
    public function removeTeamMember(Request $request)
    {
        // In single instructor mode, return error
        if (\App\Services\InstructorModeService::isSingleInstructorMode()) {
            return ApiResponseService::validationError('Team management is disabled in Single Instructor mode.');
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
        ]);
        if ($validator->fails()) {
            ApiResponseService::validationError($validator->errors()->first());
        }
        try {
            // Check if user is instructor
            $loggedInUserData = Auth::user();
            $isInstructor = $loggedInUserData->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'));
            if (!$isInstructor) {
                ApiResponseService::validationError('You are not authorized to remove team members');
            }
            $instructorId = Instructor::where('user_id', $loggedInUserData?->id)->pluck('id')->first();

            if (!$instructorId) {
                ApiResponseService::validationError('Instructor record not found');
            }

            // Check if team member exists (user in auth user's team)
            $teamMember = TeamMember::where('instructor_id', $instructorId)
                ->where('user_id', $request->user_id)
                ->first();

            $isInvitor = false;

            // If not found, check if it's an invitor (auth user is in their team)
            if (!$teamMember) {
                $targetUserInstructor = Instructor::where('user_id', $request->user_id)->first();

                if ($targetUserInstructor) {
                    // Check if auth user is a team member of the target user
                    $teamMember = TeamMember::where('instructor_id', $targetUserInstructor->id)
                        ->where('user_id', $loggedInUserData->id)
                        ->first();

                    if ($teamMember) {
                        $isInvitor = true;
                    }
                }

                // If still not found, return error
                if (!$teamMember) {
                    return ApiResponseService::validationError('Team member or invitor not found');
                }
            }

            // Delete the team member record
            $teamMember->delete();

            // If it's an invitor case, just return success
            if ($isInvitor) {
                return ApiResponseService::successResponse('Team invitation removed successfully');
            }

            // This is a team member case - removing user from auth user's team
            // Continue with role cleanup logic
            // ✅ Check if user is part of any other team
            $user = User::find($request->user_id);
            $isInOtherTeams = TeamMember::where('user_id', $request->user_id)
                ->where('instructor_id', '!=', $instructorId)
                ->exists();

            // ✅ If user is not in any other team and is not a main instructor, remove instructor role
            $isMainInstructor = Instructor::where('user_id', $request->user_id)
                ->whereNotIn('status', ['rejected'])
                ->exists();

            if (!$isInOtherTeams && !$isMainInstructor) {
                // Remove instructor role if they're not a main instructor and not in other teams
                $user->removeRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'));

                // Also remove instructor record if it was created just for team membership
                $instructorRecord = Instructor::where('user_id', $request->user_id)->first();
                if ($instructorRecord && $instructorRecord->type === 'team') {
                    $instructorRecord->delete();
                }
            }

            ApiResponseService::successResponse('Team member removed successfully');
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to remove team member');
            ApiResponseService::errorResponse('Failed to remove team member');
        }
    }

    /**
     * Get Team Members with their created courses
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     *
     * Query Parameters:
     * - id: Filter by specific team member ID
     * - user_id: Filter by specific user ID
     * - search: Search by user name or email
     * - status: Filter by team member status (pending, approved, rejected, suspended)
     * - course_id: Filter team members who created specific course
     * - has_courses: Filter team members with/without created courses (true/false)
     * - sort_by: Sort field (id, created_at, updated_at)
     * - sort_order: Sort direction (asc, desc)
     * - per_page: Number of results per page (1-100)
     * - page: Page number
     */
    public function getTeamMembers(Request $request)
    {
        try {
            // In single instructor mode, return error
            if (\App\Services\InstructorModeService::isSingleInstructorMode()) {
                return ApiResponseService::validationError('Team management is disabled in Single Instructor mode.');
            }

            $validator = Validator::make($request->all(), [
                'id' => 'nullable|exists:team_members,id',
                'user_id' => 'nullable|exists:users,id',
                'search' => 'nullable|string|max:255',
                'sort_by' => 'nullable|in:id,created_at,updated_at',
                'sort_order' => 'nullable|in:asc,desc',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
                'status' => 'nullable|in:pending,approved,rejected,suspended',
                'course_id' => 'nullable|exists:courses,id',
                'has_courses' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $loggedInUserData = Auth::user();
            $isInstructor = $loggedInUserData->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'));
            if (!$isInstructor) {
                return ApiResponseService::validationError('You are not authorized to get team members');
            }

            $instructorData = $loggedInUserData->load('instructor_details')->instructor_details ?? null;
            if (!$instructorData) {
                return ApiResponseService::validationError('Instructor not found');
            }

            $instructorId = $instructorData->id ?? null;

            $query = TeamMember::where('instructor_id', $instructorId)->with([
                'user' => static function ($userQuery): void {
                    $userQuery
                        ->select('id', 'name', 'email', 'slug', 'profile', 'is_active', 'created_at')
                        ->with(['instructor_details' => static function ($instructorQuery): void {
                            $instructorQuery->select('id', 'user_id', 'type', 'status', 'reason');
                        }]);
                },
            ]);

            // Filter by specific team member ID
            if ($request->filled('id')) {
                $query->where('id', $request->id);
            }

            // Filter by specific user ID
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            // Filter by status - default to 'approved' if not specified
            $status = $request->filled('status') ? $request->status : 'approved';
            $query->where('status', $status);

            // Search functionality
            if ($request->filled('search')) {
                $search = $request->search;
                $query->whereHas('user', static function ($userQuery) use ($search): void {
                    $userQuery->where('name', 'LIKE', "%{$search}%")->orWhere('email', 'LIKE', "%{$search}%");
                });
            }

            // Filter by specific course
            if ($request->filled('course_id')) {
                $query->whereHas('user.courses', static function ($courseQuery) use ($request): void {
                    $courseQuery->where('id', $request->course_id);
                });
            }

            // Filter by team members who have courses (has_courses=true) or no courses (has_courses=false)
            if ($request->has('has_courses')) {
                if ($request->has_courses) {
                    $query->whereHas('user.courses');
                } else {
                    $query->whereDoesntHave('user.courses');
                }
            }

            // Sorting
            $sortField = $request->sort_by ?? 'created_at';
            $sortOrder = $request->sort_order ?? 'desc';
            $query->orderBy($sortField, $sortOrder);

            // Pagination
            $perPage = $request->per_page ?? 15;
            $teamMembers = $query->paginate($perPage);

            // Get invitors - teams where authenticated user is a team member
            $invitorsQuery = TeamMember::where('user_id', $loggedInUserData?->id)
                ->where('status', 'approved')
                ->with([
                    'instructor' => static function ($instructorQuery): void {
                        $instructorQuery->with(['user' => static function ($userQuery): void {
                            $userQuery
                                ->select('id', 'name', 'email', 'slug', 'profile', 'is_active', 'created_at')
                                ->with(['instructor_details' => static function ($instructorDetailsQuery): void {
                                    $instructorDetailsQuery->select('id', 'user_id', 'type', 'status', 'reason');
                                }]);
                        }]);
                    },
                ])
                ->orderBy('created_at', 'desc')
                ->get();

            // Transform invitors data to match my_team structure
            $invitors = $invitorsQuery->map(static function ($teamMember) {
                $courses = [];

                // Fetch courses created by the instructor (who invited auth user)
                if ($teamMember->instructor && $teamMember->instructor->user) {
                    $instructorUserId = $teamMember->instructor->user->id;
                    $userCourses = \App\Models\Course\Course::where('user_id', $instructorUserId)->select(
                        'id',
                        'title',
                        'slug',
                        'thumbnail',
                        'price',
                        'discount_price',
                        'status',
                        'is_active',
                        'user_id',
                        'created_at',
                    )->get();

                    foreach ($userCourses as $course) {
                        $courses[] = [
                            'id' => $course->id,
                            'title' => $course->title,
                            'slug' => $course->slug,
                            'thumbnail' => $course->thumbnail,
                            'price' => $course->price,
                            'discount_price' => $course->discount_price,
                            'status' => $course->status,
                            'is_active' => $course->is_active,
                            'created_at' => $course->created_at,
                            'user_id' => $course->user_id,
                        ];
                    }
                }

                return [
                    'id' => $teamMember->id,
                    'instructor_id' => $teamMember->instructor_id,
                    'user_id' => $teamMember->instructor && $teamMember->instructor->user
                        ? $teamMember->instructor->user->id
                        : null,
                    'status' => $teamMember->status,
                    'type' => 'invitor', // Auth user is invited by this instructor
                    'created_at' => $teamMember->created_at,
                    'updated_at' => $teamMember->updated_at,
                    'user' => $teamMember->instructor && $teamMember->instructor->user
                        ? [
                            'id' => $teamMember->instructor->user->id,
                            'name' => $teamMember->instructor->user->name,
                            'email' => $teamMember->instructor->user->email,
                            'slug' => $teamMember->instructor->user->slug,
                            'profile' => $teamMember->instructor->user->profile,
                            'is_active' => $teamMember->instructor->user->is_active,
                            'created_at' => $teamMember->instructor->user->created_at,
                            'instructor_status' => $teamMember->instructor->user->instructor_details
                                ? $teamMember->instructor->user->instructor_details->status
                                : null,
                        ] : null,
                    'courses' => $courses,
                    'total_courses' => count($courses),
                ];
            })->values();

            // Transform data to include courses and type
            $teamMembers
                ->getCollection()
                ->transform(static function ($teamMember) use ($loggedInUserData) {
                    $courses = [];

                    // Fetch courses assigned to the team member from course_instructors table
                    if ($teamMember->user) {
                        // Get course IDs from course_instructors where team member's user_id matches
                        // Only check for soft deletes, don't filter by is_active (show all assigned courses)
                        $assignedCourseIds = \Illuminate\Support\Facades\DB::table('course_instructors')
                            ->where('user_id', $teamMember->user->id)
                            ->whereNull('deleted_at')
                            ->pluck('course_id')
                            ->toArray();

                        // Fetch courses assigned to team member
                        if (!empty($assignedCourseIds)) {
                            $assignedCourses = \App\Models\Course\Course::whereIn('id', $assignedCourseIds)->select(
                                'id',
                                'title',
                                'slug',
                                'thumbnail',
                                'price',
                                'discount_price',
                                'status',
                                'is_active',
                                'user_id',
                                'created_at',
                            )->get();

                            foreach ($assignedCourses as $course) {
                                $courses[] = [
                                    'id' => $course->id,
                                    'title' => $course->title,
                                    'slug' => $course->slug,
                                    'thumbnail' => $course->thumbnail,
                                    'price' => $course->price,
                                    'discount_price' => $course->discount_price,
                                    'status' => $course->status,
                                    'is_active' => $course->is_active,
                                    'created_at' => $course->created_at,
                                    'user_id' => $course->user_id,
                                ];
                            }
                        }
                    }

                    // Determine type: check if auth user is invited by this team member
                    $type = 'team_member'; // Default: user is in auth user's team

                    // Check if auth user is invited by this team member (auth user is in their team)
                    if ($teamMember->user && $teamMember->user->instructor_details) {
                        $isInvitor = TeamMember::where('instructor_id', $teamMember->user->instructor_details->id)
                            ->where('user_id', $loggedInUserData?->id)
                            ->where('status', 'approved')
                            ->exists();

                        if ($isInvitor) {
                            $type = 'invitor'; // Auth user is invited by this user
                        }
                    }

                    return [
                        'id' => $teamMember->id,
                        'instructor_id' => $teamMember->instructor_id,
                        'user_id' => $teamMember->user_id,
                        'status' => $teamMember->status,
                        'type' => $type,
                        'created_at' => $teamMember->created_at,
                        'updated_at' => $teamMember->updated_at,
                        'user' => $teamMember->user
                            ? [
                                'id' => $teamMember->user->id,
                                'name' => $teamMember->user->name,
                                'email' => $teamMember->user->email,
                                'slug' => $teamMember->user->slug,
                                'profile' => $teamMember->user->profile,
                                'is_active' => $teamMember->user->is_active,
                                'created_at' => $teamMember->user->created_at,
                                'instructor_status' => $teamMember->user->instructor_details
                                    ? $teamMember->user->instructor_details->status
                                    : null,
                            ] : null,
                        'courses' => $courses,
                        'total_courses' => count($courses),
                    ];
                });

            // Convert both collections to plain arrays and merge
            // Get transformed data as plain arrays
            $myTeamArray = [];
            foreach ($teamMembers->getCollection() as $item) {
                $myTeamArray[] = $item; // Already transformed to array
            }

            $invitorsArray = [];
            foreach ($invitors as $item) {
                $invitorsArray[] = $item; // Already transformed to array
            }

            // Merge arrays
            $allTeamMembers = array_merge($myTeamArray, $invitorsArray);

            // Apply pagination to merged array
            $total = count($allTeamMembers);
            $page = $request->get('page', 1);
            $offset = ($page - 1) * $perPage;
            $paginatedData = array_slice($allTeamMembers, $offset, $perPage);

            // Use replacePaginationFormat helper method
            $paginatedResponse = $this->replacePaginationFormat($paginatedData, $page, $perPage, $total);

            return ApiResponseService::successResponse('Team members fetched successfully', $paginatedResponse);
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to get team members');
            return ApiResponseService::errorResponse('Failed to get team members' . $e);
        }
    }

    /**
     * Get Invitors - Teams where authenticated user is a team member
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     *
     * Query Parameters:
     * - search: Search by instructor name or email
     * - sort_by: Sort field (id, created_at, updated_at)
     * - sort_order: Sort direction (asc, desc)
     * - per_page: Number of results per page (1-100)
     * - page: Page number
     */
    public function getInvitors(Request $request)
    {
        try {
            // In single instructor mode, return error
            if (\App\Services\InstructorModeService::isSingleInstructorMode()) {
                return ApiResponseService::validationError('Team management is disabled in Single Instructor mode.');
            }

            $validator = Validator::make($request->all(), [
                'search' => 'nullable|string|max:255',
                'sort_by' => 'nullable|in:id,created_at,updated_at',
                'sort_order' => 'nullable|in:asc,desc',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $loggedInUserData = Auth::user();

            // Get teams where authenticated user is a team member and status is approved
            $query = TeamMember::where('user_id', $loggedInUserData?->id)
                ->where('status', 'approved')
                ->with([
                    'instructor' => static function ($instructorQuery): void {
                        $instructorQuery->with(['user' => static function ($userQuery): void {
                            $userQuery
                                ->select('id', 'name', 'email', 'slug', 'profile', 'is_active', 'created_at')
                                ->with(['instructor_details' => static function ($instructorDetailsQuery): void {
                                    $instructorDetailsQuery->select('id', 'user_id', 'type', 'status', 'reason');
                                }]);
                        }]);
                    },
                ]);

            // Search functionality
            if ($request->filled('search')) {
                $search = $request->search;
                $query->whereHas('instructor.user', static function ($userQuery) use ($search): void {
                    $userQuery->where('name', 'LIKE', "%{$search}%")->orWhere('email', 'LIKE', "%{$search}%");
                });
            }

            // Sorting
            $sortField = $request->sort_by ?? 'created_at';
            $sortOrder = $request->sort_order ?? 'desc';
            $query->orderBy($sortField, $sortOrder);

            // Pagination
            $perPage = $request->per_page ?? 15;
            $invitors = $query->paginate($perPage);

            // Transform data
            $invitors
                ->getCollection()
                ->transform(static fn($teamMember) => [
                    'id' => $teamMember->id,
                    'instructor_id' => $teamMember->instructor_id,
                    'user_id' => $teamMember->user_id,
                    'status' => $teamMember->status,
                    'created_at' => $teamMember->created_at,
                    'updated_at' => $teamMember->updated_at,
                    'instructor' => $teamMember->instructor && $teamMember->instructor->user
                        ? [
                            'id' => $teamMember->instructor->user->id,
                            'name' => $teamMember->instructor->user->name,
                            'email' => $teamMember->instructor->user->email,
                            'slug' => $teamMember->instructor->user->slug,
                            'profile' => $teamMember->instructor->user->profile,
                            'is_active' => $teamMember->instructor->user->is_active,
                            'created_at' => $teamMember->instructor->user->created_at,
                            'instructor_status' => $teamMember->instructor->user->instructor_details
                                ? $teamMember->instructor->user->instructor_details->status
                                : null,
                            'instructor_type' => $teamMember->instructor->type ?? null,
                        ] : null,
                ]);

            return ApiResponseService::successResponse('Invitors fetched successfully', $invitors);
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to get invitors');
            return ApiResponseService::errorResponse('Failed to get invitors' . $e);
        }
    }

    /**
     * Get Instructors
     */
    public function getInstructors(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'nullable|exists:instructors,id',
            'slug' => 'nullable|string|exists:users,slug',
            'type' => 'nullable|in:individual,team',
            'search' => 'nullable|string|max:255',
            'sort_by' => 'nullable|in:id,created_at,updated_at',
            'sort_order' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'status' => 'nullable|in:pending,approved,rejected,suspended',
            'category_id' => 'nullable|string',
            'category_slug' => 'nullable|string',
            'rating' => 'nullable|integer|min:1|max:5',
            'feature_section_id' => 'nullable|exists:feature_sections,id', // Optional: Filter by feature section
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        // Apply feature section filtering if provided
        $featureSection = null;
        if ($request->filled('feature_section_id')) {
            $featureSection = \App\Models\FeatureSection::where('id', $request->feature_section_id)
                ->where('is_active', 1)
                ->first();

            if (!$featureSection) {
                return ApiResponseService::validationError('Feature section not found or inactive');
            }
        }

        // ✅ Validate category IDs
        if ($request->filled('category_id')) {
            $categoryIds = array_map(intval(...), explode(',', $request->category_id));
            $validIds = \App\Models\Category::whereIn('id', $categoryIds)->pluck('id')->toArray();
            $invalid = array_diff($categoryIds, $validIds);
            if (!empty($invalid)) {
                return ApiResponseService::validationError('Invalid category IDs: ' . implode(', ', $invalid));
            }
        }

        // ✅ Validate category slugs
        if ($request->filled('category_slug')) {
            $categorySlugs = explode(',', $request->category_slug);
            $validSlugs = \App\Models\Category::whereIn('slug', $categorySlugs)->pluck('slug')->toArray();
            $invalid = array_diff($categorySlugs, $validSlugs);
            if (!empty($invalid)) {
                return ApiResponseService::validationError('Invalid category slugs: ' . implode(', ', $invalid));
            }
        }

        $query = Instructor::query()
            ->with([
                'user.courses.category',
                'personal_details',
                'social_medias.social_media',
                'ratings.user',
                'courses.category',
            ])
            ->where('status', 'approved')
            ->withAvg('ratings', 'rating')
            ->withCount('ratings')
            ->whereHas('user', static function ($q) use ($request): void {
                $q->where('is_active', 1);
                if ($request->filled('slug')) {
                    $q->where('slug', $request->slug);
                }
            });

        // ✅ Filter by ID, type, and status
        foreach (['id', 'type', 'status'] as $field) {
            if (!$request->filled($field)) {
                continue;
            }

            $values = explode(',', $request->$field);
            $query->whereIn($field, $values);
        }

        // ✅ Category-based filtering (direct relation by user_id)
        if ($request->filled('category_id') || $request->filled('category_slug')) {
            $categoryIds = $request->filled('category_id')
                ? array_map(intval(...), explode(',', $request->category_id))
                : [];

            $categorySlugs = $request->filled('category_slug') ? explode(',', $request->category_slug) : [];

            $query->where(static function ($q) use ($categoryIds, $categorySlugs): void {
                // Courses owned by instructor's user_id
                $q->whereHas('user', static function ($uq) use ($categoryIds, $categorySlugs): void {
                    $uq->whereHas('courses', static function ($cq) use ($categoryIds, $categorySlugs): void {
                        if ($categoryIds) {
                            $cq->whereIn('category_id', $categoryIds);
                        }
                        if ($categorySlugs) {
                            $cq->whereHas('category', static function ($cat) use ($categorySlugs): void {
                                $cat->whereIn('slug', $categorySlugs);
                            });
                        }
                    });
                });

                // OR courses where instructor is co-instructor
                $q->orWhereHas('courses', static function ($cq) use ($categoryIds, $categorySlugs): void {
                    if ($categoryIds) {
                        $cq->whereIn('category_id', $categoryIds);
                    }
                    if ($categorySlugs) {
                        $cq->whereHas('category', static function ($cat) use ($categorySlugs): void {
                            $cat->whereIn('slug', $categorySlugs);
                        });
                    }
                });
            });
        }

        // ✅ Filter by rating
        if ($request->filled('rating')) {
            $rating = (int) $request->rating;
            $query->havingRaw('ratings_avg_rating >= ?', [$rating])->havingRaw('ratings_avg_rating < ?', [$rating + 1]);
        }

        // ✅ Search by name, email, or details
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(static function ($q) use ($search): void {
                $q->whereHas('user', static fn($u) => $u->where('name', 'LIKE', "%$search%")->orWhere(
                    'email',
                    'LIKE',
                    "%$search%",
                ))->orWhereHas('personal_details', static fn($p) => $p
                    ->where('qualification', 'LIKE', "%$search%")
                    ->orWhere('skills', 'LIKE', "%$search%")
                    ->orWhere('about_me', 'LIKE', "%$search%")
                    ->orWhere('team_name', 'LIKE', "%$search%"));
            });
        }

        // Apply feature section filtering
        if ($featureSection && $featureSection->type === 'top_rated_instructors') {
            $query->orderByDesc('ratings_avg_rating');
            // Limit will be handled by pagination, but we can note it
            $limit = $featureSection->limit ?? null;
        }

        // ✅ Sorting
        $query->orderBy($request->sort_by ?? 'id', $request->sort_order ?? 'desc');

        // ✅ Pagination
        $instructors = $query->paginate($request->per_page ?? 15);

        if ($instructors->isEmpty()) {
            return ApiResponseService::validationError('No Instructors Found');
        }

        // Get authenticated user (if available)
        $authUser = Auth::user();

        // Get purchased course IDs for authenticated user (if logged in)
        $purchasedCourseIds = [];
        if ($authUser) {
            $purchasedCourseIds = \App\Models\OrderCourse::whereHas('order', static function ($q) use (
                $authUser,
            ): void {
                $q->where('user_id', $authUser->id)->where('status', 'completed');
            })
                ->pluck('course_id')
                ->toArray();
        }

        // ✅ Transform Response
        $data = $instructors->map(static function ($instructor) use ($request, $authUser, $purchasedCourseIds) {
            // Count only active courses with at least 1 active curriculum item
            $activeCoursesCount = \App\Models\Course\Course::where('user_id', $instructor->user_id)
                ->where('is_active', 1)
                ->where('approval_status', 'approved')
                ->whereHas('chapters', static function ($chapterQuery): void {
                    $chapterQuery
                        ->where('is_active', true)
                        ->where(static function ($curriculumQuery): void {
                            $curriculumQuery
                                ->whereHas('lectures', static function ($lectureQuery): void {
                                    $lectureQuery->where('is_active', true);
                                })
                                ->orWhereHas('quizzes', static function ($quizQuery): void {
                                    $quizQuery->where('is_active', true);
                                })
                                ->orWhereHas('assignments', static function ($assignmentQuery): void {
                                    $assignmentQuery->where('is_active', true);
                                })
                                ->orWhereHas('resources', static function ($resourceQuery): void {
                                    $resourceQuery->where('is_active', true);
                                });
                        });
                })
                ->count();

            // Count only published courses with at least 1 active curriculum item
            $publishedCoursesCount = \App\Models\Course\Course::where('user_id', $instructor->user_id)
                ->where('is_active', 1)
                ->where('approval_status', 'approved')
                ->whereHas('chapters', static function ($chapterQuery): void {
                    $chapterQuery
                        ->where('is_active', true)
                        ->where(static function ($curriculumQuery): void {
                            $curriculumQuery
                                ->whereHas('lectures', static function ($lectureQuery): void {
                                    $lectureQuery->where('is_active', true);
                                })
                                ->orWhereHas('quizzes', static function ($quizQuery): void {
                                    $quizQuery->where('is_active', true);
                                })
                                ->orWhereHas('assignments', static function ($assignmentQuery): void {
                                    $assignmentQuery->where('is_active', true);
                                })
                                ->orWhereHas('resources', static function ($resourceQuery): void {
                                    $resourceQuery->where('is_active', true);
                                });
                        });
                })
                ->count();

            $studentEnrolledCount = \App\Models\OrderCourse::whereHas('course', static fn($q) => $q->where(
                'user_id',
                $instructor->user_id,
            ))
                ->whereHas('order', static fn($q) => $q->where('status', 'completed'))
                ->join('orders', 'order_courses.order_id', '=', 'orders.id')
                ->distinct('orders.user_id')
                ->count('orders.user_id');

            // Calculate review count (ratings with reviews)
            $reviewCount = \App\Models\Rating::where('rateable_type', \App\Models\Instructor::class)
                ->where('rateable_id', $instructor->id)
                ->whereNotNull('review')
                ->where('review', '!=', '')
                ->count();

            // Check if authenticated user has purchased any course from this instructor
            $userPurchasedCourse = false;
            if ($authUser && !empty($purchasedCourseIds)) {
                // Check if user purchased any course created by this instructor
                $hasPurchased = \App\Models\Course\Course::where('user_id', $instructor->user_id)
                    ->whereIn('id', $purchasedCourseIds)
                    ->exists();

                // Also check if user purchased any course where this instructor is a co-instructor
                if (!$hasPurchased) {
                    $hasPurchased = \App\Models\Course\Course::whereHas('instructors', static function ($q) use (
                        $instructor,
                    ): void {
                        $q->where('user_id', $instructor->user_id);
                    })
                        ->whereIn('id', $purchasedCourseIds)
                        ->exists();
                }

                $userPurchasedCourse = $hasPurchased;
            }

            return [
                'id' => $instructor->id,
                'user_id' => $instructor->user_id,
                'type' => $instructor->type,
                'status' => $instructor->status,
                'name' => $instructor->user->name ?? '',
                'email' => $instructor->user->email ?? '',
                'slug' => $instructor->user->slug ?? '',
                'profile' => $instructor->user->profile ?? '',
                'qualification' => $instructor->personal_details->qualification ?? '',
                'years_of_experience' => $instructor->personal_details->years_of_experience ?? 0,
                'skills' => $instructor->personal_details->skills ?? '',
                'about_me' => $instructor->personal_details->about_me ?? '',
                'preview_video' => $instructor->personal_details->preview_video ?? '',
                'team_name' => $instructor->personal_details->team_name ?? '',
                'social_medias' => $instructor->social_medias->map(static fn($social) => [
                    'title' => $social->title ?? '',
                    'url' => $social->url ?? '',
                ]),
                'average_rating' => round($instructor->ratings_avg_rating ?? 0, 1),
                'total_ratings' => (int) ($instructor->ratings_count ?? 0),
                'review_count' => $reviewCount,
                'student_enrolled_count' => $studentEnrolledCount,
                'active_courses_count' => $activeCoursesCount,
                'published_courses_count' => $publishedCoursesCount,
                'user_purchased_course' => $userPurchasedCourse,
            ];
        });

        $pagination = $this->replacePaginationFormat(
            $data->toArray(),
            $instructors->currentPage(),
            $instructors->perPage(),
            $instructors->total(),
        );

        return ApiResponseService::successResponse('Instructors fetched successfully', $pagination);
    }

    /**
     * Get Instructor Details by ID or Slug
     */
    public function getInstructorDetails(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'id' => 'nullable|exists:instructors,id',
                'slug' => 'nullable|string|exists:users,slug',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            // Check if either id or slug is provided
            if (!$request->filled('id') && !$request->filled('slug')) {
                return ApiResponseService::validationError('Either id or slug parameter is required');
            }

            $query = Instructor::with([
                'user',
                'personal_details',
                'social_medias.social_media',
                'ratings.user',
            ])
                ->where('status', 'approved')
                ->withAvg('ratings', 'rating')
                ->withCount('ratings')
                ->whereHas('user', static function ($userQuery) use ($request): void {
                    $userQuery->where('is_active', 1);

                    // Filter by slug if provided
                    if ($request->filled('slug')) {
                        $userQuery->where('slug', $request->slug);
                    }
                });

            // Filter by ID if provided
            if ($request->filled('id')) {
                $query->where('id', $request->id);
            }

            $instructor = $query->first();

            if (!$instructor) {
                return ApiResponseService::validationError('Instructor not found');
            }

            // Get student enrolled count for this instructor's courses
            // Count distinct students enrolled in courses created by this instructor
            $studentEnrolledCount = \App\Models\OrderCourse::whereHas('course', static function ($q) use (
                $instructor,
            ): void {
                $q->where('user_id', $instructor->user_id);
            })
                ->whereHas('order', static function ($q): void {
                    $q->where('status', 'completed');
                })
                ->join('orders', 'order_courses.order_id', '=', 'orders.id')
                ->distinct('orders.user_id')
                ->count('orders.user_id');

            // Get active courses count (consistent with courses array - only courses with active curriculum)
            $activeCoursesCount = \App\Models\Course\Course::where('user_id', $instructor->user_id)
                ->where('is_active', 1)
                ->where('approval_status', 'approved')
                ->where('status', 'publish')
                ->whereHas('chapters', static function ($chapterQuery): void {
                    $chapterQuery
                        ->where('is_active', true)
                        ->where(static function ($curriculumQuery): void {
                            $curriculumQuery
                                ->whereHas('lectures', static function ($lectureQuery): void {
                                    $lectureQuery->where('is_active', true);
                                })
                                ->orWhereHas('quizzes', static function ($quizQuery): void {
                                    $quizQuery->where('is_active', true);
                                })
                                ->orWhereHas('assignments', static function ($assignmentQuery): void {
                                    $assignmentQuery->where('is_active', true);
                                })
                                ->orWhereHas('resources', static function ($resourceQuery): void {
                                    $resourceQuery->where('is_active', true);
                                });
                        });
                })
                ->count();

            // Get published courses count (consistent with get-instructors API - only courses with active curriculum)
            $publishedCoursesCount = \App\Models\Course\Course::where('user_id', $instructor->user_id)
                ->where('is_active', 1)
                ->where('approval_status', 'approved')
                ->whereHas('chapters', static function ($chapterQuery): void {
                    $chapterQuery
                        ->where('is_active', true)
                        ->where(static function ($curriculumQuery): void {
                            $curriculumQuery
                                ->whereHas('lectures', static function ($lectureQuery): void {
                                    $lectureQuery->where('is_active', true);
                                })
                                ->orWhereHas('quizzes', static function ($quizQuery): void {
                                    $quizQuery->where('is_active', true);
                                })
                                ->orWhereHas('assignments', static function ($assignmentQuery): void {
                                    $assignmentQuery->where('is_active', true);
                                })
                                ->orWhereHas('resources', static function ($resourceQuery): void {
                                    $resourceQuery->where('is_active', true);
                                });
                        });
                })
                ->count();

            // Get authenticated user's review for this instructor (before fetching ratings list)
            $myReview = null;
            $authUser = Auth::user();
            $authUserId = null;
            if ($authUser) {
                $myReviewData = \App\Models\Rating::where('rateable_type', \App\Models\Instructor::class)
                    ->where('rateable_id', $instructor->id)
                    ->where('user_id', $authUser->id)
                    ->first();

                if ($myReviewData) {
                    $authUserId = $authUser->id;
                    $myReview = [
                        'id' => $myReviewData->id,
                        'rating' => $myReviewData->rating,
                        'review' => $myReviewData->review,
                        'created_at' => $myReviewData->created_at->format('Y-m-d H:i:s'),
                        'updated_at' => $myReviewData->updated_at->format('Y-m-d H:i:s'),
                    ];
                }
            }

            // Get recent ratings (excluding authenticated user's review if exists)
            $ratingsQuery = $instructor->ratings()->with('user')->latest();

            // Exclude authenticated user's review from ratings list
            if ($authUserId) {
                $ratingsQuery->where('user_id', '!=', $authUserId);
            }

            $ratingsList = $ratingsQuery
                ->limit(10)
                ->get()
                ->map(static fn($rating) => [
                    'id' => $rating->id,
                    'rating' => $rating->rating,
                    'review' => $rating->review,
                    'user_name' => $rating->user->name ?? 'Anonymous',
                    'user_profile' => $rating->user->profile ?? '',
                    'created_at' => $rating->created_at->format('Y-m-d H:i:s'),
                ]);

            // Get instructor's courses with details (consistent with get-courses API format)
            // Only include courses that have active chapters with active curriculum items
            $coursesList = \App\Models\Course\Course::where('user_id', $instructor->user_id)
                ->where('is_active', 1)
                ->where('approval_status', 'approved')
                ->where('status', 'publish')
                ->whereHas('chapters', static function ($chapterQuery): void {
                    $chapterQuery
                        ->where('is_active', true)
                        ->where(static function ($curriculumQuery): void {
                            $curriculumQuery
                                ->whereHas('lectures', static function ($lectureQuery): void {
                                    $lectureQuery->where('is_active', true);
                                })
                                ->orWhereHas('quizzes', static function ($quizQuery): void {
                                    $quizQuery->where('is_active', true);
                                })
                                ->orWhereHas('assignments', static function ($assignmentQuery): void {
                                    $assignmentQuery->where('is_active', true);
                                })
                                ->orWhereHas('resources', static function ($resourceQuery): void {
                                    $resourceQuery->where('is_active', true);
                                });
                        });
                })
                ->with(['category', 'ratings', 'user', 'chapters.lectures'])
                ->withCount('ratings')
                ->withAvg('ratings', 'rating')
                ->latest()
                ->get()
                ->map(function ($course) {
                    // Calculate discount percentage
                    $discountPercentage = 0;
                    if ($course->has_discount) {
                        $discountPercentage = round(
                            (($course->price - $course->discount_price) / $course->price) * 100,
                            2,
                        );
                    }

                    // Check if wishlisted
                    $isWishlisted = Auth::check()
                        ? \App\Models\Wishlist::where('user_id', Auth::id())->where('course_id', $course->id)->exists()
                        : false;

                    // Check if enrolled
                    $isEnrolled = Auth::check()
                        ? \App\Models\OrderCourse::whereHas('order', static function ($query): void {
                            $query->where('user_id', Auth::id())->where('status', 'completed');
                        })
                            ->where('course_id', $course->id)
                            ->exists()
                        : false;

                    // Calculate total course duration
                    $totalDuration = 0;
                    foreach ($course->chapters as $chapter) {
                        foreach ($chapter->lectures as $lecture) {
                            $totalDuration +=
                                (($lecture->hours ?? 0) * 3600)
                                + (($lecture->minutes ?? 0) * 60)
                                + ($lecture->seconds ?? 0);
                        }
                    }

                    return [
                        'id' => $course->id,
                        'slug' => $course->slug,
                        'image' => $course->thumbnail,
                        'category_id' => $course->category->id ?? null,
                        'category_name' => $course->category->name ?? null,
                        'course_type' => $course->course_type,
                        'level' => $course->level,
                        'sequential_access' => $course->sequential_access ?? true,
                        'certificate_enabled' => $course->certificate_enabled ?? false,
                        'certificate_fee' => $course->certificate_fee ? (float) $course->certificate_fee : null,
                        'ratings' => $course->ratings_count ?? 0,
                        'average_rating' => round($course->ratings_avg_rating ?? 0, 2),
                        'title' => $course->title,
                        'short_description' => $course->short_description,
                        'author_id' => $course->user->id ?? null,
                        'author_name' => $course->user->name ?? null,
                        'author_slug' => $course->user->slug ?? null,
                        'price' => (float) $course->display_price,
                        'discount_price' => (float) $course->display_discount_price,
                        'total_tax_percentage' => (float) $course->total_tax_percentage,
                        'tax_amount' => (float) $course->tax_amount,
                        'discount_percentage' => $discountPercentage,
                        'total_duration' => $totalDuration, // in seconds
                        'total_duration_formatted' => $this->formatDuration($totalDuration),
                        'is_wishlisted' => $isWishlisted,
                        'is_enrolled' => $isEnrolled,
                    ];
                });

            // Calculate review count (ratings with reviews)
            $reviewCount = \App\Models\Rating::where('rateable_type', \App\Models\Instructor::class)
                ->where('rateable_id', $instructor->id)
                ->whereNotNull('review')
                ->where('review', '!=', '')
                ->count();

            // Determine type based on user role: admin or instructor
            $user = $instructor->user;
            $type = 'instructor'; // default
            if ($user && $user->hasRole(config('constants.SYSTEM_ROLES.ADMIN'))) {
                $type = 'admin';
            } elseif ($user && $user->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))) {
                $type = 'instructor';
            }

            // Get personal details safely
            $personalDetails = $instructor->personal_details;

            $instructorData = [
                'id' => $instructor->id,
                'user_id' => $instructor->user_id,
                'type' => $type,
                'status' => $instructor->status,
                'name' => $instructor->user->name ?? '',
                'email' => $instructor->user->email ?? '',
                'slug' => $instructor->user->slug ?? '',
                'profile' => $instructor->user->profile ?? '',
                'qualification' => $personalDetails ? $personalDetails->qualification ?? '' : '',
                'years_of_experience' => $personalDetails ? $personalDetails->years_of_experience ?? 0 : 0,
                'skills' => $personalDetails ? $personalDetails->skills ?? '' : '',
                'about_me' => $personalDetails ? $personalDetails->about_me ?? '' : '',
                'preview_video' => $personalDetails ? $personalDetails->preview_video ?? '' : '',
                'team_name' => $personalDetails ? $personalDetails->team_name ?? '' : '',
                'team_logo' => $personalDetails ? $personalDetails->team_logo ?? '' : '',
                'social_medias' => $instructor->social_medias->map(static fn($social) => [
                    'title' => $social->title ?? '',
                    'url' => $social->url ?? '',
                ]),
                'average_rating' => round($instructor->ratings_avg_rating ?? 0, 1),
                'total_ratings' => (int) ($instructor->ratings_count ?? 0),
                'review_count' => $reviewCount,
                'student_enrolled_count' => $studentEnrolledCount,
                'active_courses_count' => $activeCoursesCount,
                'published_courses_count' => $publishedCoursesCount,
                'ratings' => $ratingsList,
                'courses' => $coursesList,
                'my_review' => $myReview,
                'created_at' => $instructor->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $instructor->updated_at->format('Y-m-d H:i:s'),
            ];

            return ApiResponseService::successResponse('Instructor details fetched successfully', $instructorData);
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to get instructor details');
            return ApiResponseService::errorResponse('Failed to get instructor details' . $e->getMessage());
        }
    }

    /**
     * Add Promo Code
     */
    public function storePromoCodeByInstructor(Request $request)
    {
        if (!Auth::user()->hasRole('Instructor')) {
            return ApiResponseService::unauthorizedResponse('Only instructors can create promo codes.');
        }

        $validator = Validator::make($request->all(), [
            'promo_code' => 'required|string|max:255|unique:promo_codes,promo_code',
            'message' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'no_of_users' => 'nullable|numeric|min:0',
            'discount' => 'required|numeric|min:0',
            'discount_type' => 'required|in:amount,percentage',
            'course_ids' => 'required|array|min:1',
            'course_ids.*' => 'exists:courses,id',
        ]);

        if ($validator->fails()) {
            return ApiResponseService::validationError($validator->errors()->first());
        }

        // Check if all course_ids belong to the authenticated instructor
        $instructorId = Auth::id();
        $courseIds = $request->course_ids;

        // Get courses that belong to this instructor
        $instructorCourses = Course::where('user_id', $instructorId)
            ->whereIn('id', $courseIds)
            ->pluck('id')
            ->toArray();

        // Check if all requested course_ids belong to this instructor
        $invalidCourses = array_diff($courseIds, $instructorCourses);

        if (!empty($invalidCourses)) {
            return ApiResponseService::validationError('You can only create promo codes for your own courses. The following course IDs do not belong to you: '
            . implode(', ', $invalidCourses));
        }

        try {
            $data = $request->only([
                'promo_code',
                'message',
                'start_date',
                'end_date',
                'no_of_users',
                'discount',
                'discount_type',
            ]);

            $data['user_id'] = Auth::id();

            // Handle no_of_users: if not provided or empty string, set to null (unlimited)
            // Note: 0 means "used up", null means "unlimited"
            if (!isset($data['no_of_users']) || $data['no_of_users'] === '') {
                $data['no_of_users'] = null;
            }

            // Set default values for repeat_usage fields
            $data['repeat_usage'] = false;
            $data['no_of_repeat_usage'] = 0;

            $promoCode = PromoCode::create($data);

            foreach ($request->course_ids as $course_id) {
                PromoCodeCourse::create([
                    'promo_code_id' => $promoCode->id,
                    'course_id' => $course_id,
                ]);
            }

            return ApiResponseService::successResponse('Promo Code created successfully', $promoCode);
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Instructor promo code creation failed');
            return ApiResponseService::errorResponse('Failed to create promo code');
        }
    }

    /**
     * Get Instructor's Promo Codes List or Single Promo Code Details
     */
    public function getPromoCodesByInstructor(Request $request)
    {
        if (!Auth::user()->hasRole('Instructor')) {
            return ApiResponseService::unauthorizedResponse('Only instructors can view promo codes.');
        }

        try {
            $instructorId = Auth::id();

            // If ID is provided, return single promo code details
            if ($request->filled('id')) {
                $validator = Validator::make($request->all(), [
                    'id' => 'required|integer|exists:promo_codes,id',
                ]);

                if ($validator->fails()) {
                    return ApiResponseService::validationError($validator->errors()->first());
                }

                $promoCode = PromoCode::where('id', $request->id)
                    ->where('user_id', $instructorId)
                    ->with(['courses' => static function ($q): void {
                        $q->select(
                            'courses.id',
                            'courses.title',
                            'courses.slug',
                            'courses.price',
                            'courses.discount_price',
                        );
                    }])
                    ->withCount('courses')
                    ->first();

                if (!$promoCode) {
                    return ApiResponseService::validationError(
                        'Promo code not found or you do not have permission to view it',
                    );
                }

                // Format single promo code response with additional details
                $promoCodeData = [
                    'id' => $promoCode->id,
                    'promo_code' => $promoCode->promo_code,
                    'message' => $promoCode->message,
                    'start_date' => $promoCode->start_date,
                    'end_date' => $promoCode->end_date,
                    'no_of_users' => $promoCode->no_of_users,
                    'discount' => $promoCode->discount,
                    'discount_type' => $promoCode->discount_type,
                    'status' => $promoCode->status,
                    'created_at' => $promoCode->created_at,
                    'updated_at' => $promoCode->updated_at,
                    'courses' => $promoCode->courses,
                    'courses_count' => $promoCode->courses_count,
                    'is_active' =>
                        $promoCode->start_date <= today()
                        && $promoCode->end_date >= today()
                        && ($promoCode->no_of_users === null || $promoCode->no_of_users > 0),
                    'is_expired' => $promoCode->end_date < today(),
                    'is_upcoming' => $promoCode->start_date > today(),
                    'is_used_up' => $promoCode->no_of_users !== null && $promoCode->no_of_users <= 0,
                ];

                return ApiResponseService::successResponse('Promo code details retrieved successfully', $promoCodeData);
            }

            // If no ID provided, return paginated list
            $query = PromoCode::where('user_id', $instructorId)
                ->with(['courses' => static function ($q): void {
                    $q->select('courses.id', 'courses.title', 'courses.slug');
                }])
                ->withCount('courses')
                ->withCount(['orders' => static function ($q): void {
                    $q->where('status', 'completed'); // Count only completed orders
                }]);

            // Search functionality
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(static function ($q) use ($search): void {
                    $q->where('promo_code', 'LIKE', "%{$search}%")->orWhere('message', 'LIKE', "%{$search}%");
                });
            }

            // Filter by status
            if ($request->filled('status')) {
                $now = now();
                switch ($request->status) {
                    case 'active':
                        $query
                            ->where('start_date', '<=', $now)
                            ->where('end_date', '>=', $now)
                            ->where(static function ($q): void {
                                $q->where('no_of_users', '>', 0)->orWhereNull('no_of_users');
                            });
                        break;
                    case 'expired':
                        $query->where('end_date', '<', $now);
                        break;
                    case 'upcoming':
                        $query->where('start_date', '>', $now);
                        break;
                    case 'used_up':
                        $query->whereNotNull('no_of_users')->where('no_of_users', '<=', 0);
                        break;
                }
            }

            // Sorting
            $sortField = $request->sort_by ?? 'created_at';
            $sortOrder = $request->sort_order ?? 'desc';
            $query->orderBy($sortField, $sortOrder);

            // Pagination
            $perPage = $request->per_page ?? 15;
            $promoCodes = $query->paginate($perPage);

            // Transform response to add used_count and remaining_count
            $promoCodes
                ->getCollection()
                ->transform(static function ($promoCode) {
                    $usedCount = $promoCode->orders_count ?? 0;
                    $totalLimit = $promoCode->no_of_users;
                    $remainingCount = $totalLimit !== null ? max(0, $totalLimit - $usedCount) : null;

                    // Add the counts to the promo code object
                    $promoCode->used_count = $usedCount;
                    $promoCode->remaining_count = $remainingCount;

                    return $promoCode;
                });

            return ApiResponseService::successResponse('Promo codes retrieved successfully', $promoCodes);
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to retrieve instructor promo codes');
            return ApiResponseService::errorResponse('Failed to retrieve promo codes');
        }
    }

    /**
     * Get Single Promo Code for Edit
     */
    public function getPromoCodeByInstructor(Request $request, $id = null)
    {
        if (!Auth::user()->hasRole('Instructor')) {
            return ApiResponseService::unauthorizedResponse('Only instructors can view promo codes.');
        }

        try {
            $instructorId = Auth::id();

            // જો $id null હોય તો request માંથી લઇ લો
            $promoCodeId = $id ?? $request->id;

            $promoCode = PromoCode::where('id', $promoCodeId)
                ->where('user_id', $instructorId)
                ->with(['courses' => static function ($q): void {
                    $q->select('courses.id', 'courses.title', 'courses.slug');
                }])
                ->first();

            if (!$promoCode) {
                return ApiResponseService::validationError(
                    'Promo code not found or you do not have permission to view it',
                );
            }

            return ApiResponseService::successResponse('Promo code retrieved successfully', $promoCode);
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to retrieve promo code');
            return ApiResponseService::errorResponse('Failed to retrieve promo code');
        }
    }

    /**
     * Update Promo Code by Instructor
     */
    public function updatePromoCodeByInstructor(Request $request, $id = null)
    {
        if (!Auth::user()->hasRole('Instructor')) {
            return ApiResponseService::unauthorizedResponse('Only instructors can update promo codes.');
        }

        try {
            $instructorId = Auth::id();

            // જો $id null હોય તો request માંથી લઇ લો
            $promoCodeId = $id ?? $request->id;

            $promoCode = PromoCode::where('id', $promoCodeId)->where('user_id', $instructorId)->first();

            if (!$promoCode) {
                return ApiResponseService::validationError(
                    'Promo code not found or you do not have permission to update it',
                );
            }

            $validator = Validator::make($request->all(), [
                'promo_code' => 'required|string|max:255|unique:promo_codes,promo_code,' . $promoCodeId,
                'message' => 'required|string|max:255',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'no_of_users' => 'nullable|numeric|min:0',
                'discount' => 'required|numeric|min:0',
                'discount_type' => 'required|in:amount,percentage',
                'course_ids' => 'required|array|min:1',
                'course_ids.*' => 'exists:courses,id',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            // Check if all course_ids belong to the instructor
            $courseIds = $request->course_ids;
            $instructorCourses = Course::where('user_id', $instructorId)
                ->whereIn('id', $courseIds)
                ->pluck('id')
                ->toArray();

            $invalidCourses = array_diff($courseIds, $instructorCourses);

            if (!empty($invalidCourses)) {
                return ApiResponseService::validationError('You can only update promo codes for your own courses. The following course IDs do not belong to you: '
                . implode(', ', $invalidCourses));
            }

            $data = $request->only([
                'promo_code',
                'message',
                'start_date',
                'end_date',
                'no_of_users',
                'discount',
                'discount_type',
            ]);

            // Handle no_of_users: if not provided or empty string, set to null (unlimited)
            // Note: 0 means "used up", null means "unlimited"
            if (!isset($data['no_of_users']) || $data['no_of_users'] === '') {
                $data['no_of_users'] = null;
            }

            // Set default values for repeat_usage fields
            $data['repeat_usage'] = false;
            $data['no_of_repeat_usage'] = 0;

            $promoCode->update($data);

            // Update related courses
            PromoCodeCourse::where('promo_code_id', $promoCode->id)->delete();

            foreach ($request->course_ids as $course_id) {
                PromoCodeCourse::create([
                    'promo_code_id' => $promoCode->id,
                    'course_id' => $course_id,
                ]);
            }

            return ApiResponseService::successResponse('Promo Code updated successfully', $promoCode);
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Instructor promo code update failed');
            return ApiResponseService::errorResponse('Failed to update promo code');
        }
    }

    /**
     * Delete Promo Code by Instructor
     */
    public function deletePromoCodeByInstructor(Request $request, $id = null)
    {
        if (!Auth::user()->hasRole('Instructor')) {
            return ApiResponseService::unauthorizedResponse('Only instructors can delete promo codes.');
        }

        try {
            $instructorId = Auth::id();

            // જો $id null હોય તો request માંથી લઇ લો
            $promoCodeId = $id ?? $request->id;

            if (!$promoCodeId) {
                return ApiResponseService::validationError('Promo code id is required.');
            }

            $promoCode = PromoCode::where('id', $promoCodeId)->where('user_id', $instructorId)->first();

            if (!$promoCode) {
                return ApiResponseService::validationError(
                    'Promo code not found or you do not have permission to delete it',
                );
            }

            // Delete associated course relationships first
            PromoCodeCourse::where('promo_code_id', $promoCode->id)->delete();

            // Delete the promo code
            $promoCode->delete();

            return ApiResponseService::successResponse('Promo Code deleted successfully');
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Instructor promo code deletion failed');
            return ApiResponseService::errorResponse('Failed to delete promo code');
        }
    }

    /**
     * Get Instructor Commissions
     */
    public function getCommissions(Request $request)
    {
        try {
            $loggedInUser = Auth::user();

            // Check if user is instructor
            if (!$loggedInUser->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))) {
                return ApiResponseService::validationError('You are not authorized to view commissions');
            }

            $validator = Validator::make($request->all(), [
                'status' => 'nullable|in:pending,paid,cancelled',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $status = $request->status;
            $perPage = $request->per_page ?? 15;

            // Get commissions for the logged-in instructor
            $query = CommissionService::getInstructorCommissions($loggedInUser?->id, $status);

            // Convert to paginated collection manually since we're getting from service
            $commissions = collect($query);
            $total = $commissions->count();
            $currentPage = $request->page ?? 1;
            $offset = ($currentPage - 1) * $perPage;
            $paginatedCommissions = $commissions->slice($offset, $perPage)->values();

            // Calculate totals
            $totalEarned = $commissions->where('status', 'paid')->sum('instructor_commission_amount');
            $totalPending = $commissions->where('status', 'pending')->sum('instructor_commission_amount');

            $response = [
                'commissions' => $paginatedCommissions,
                'pagination' => [
                    'current_page' => $currentPage,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage),
                    'from' => $offset + 1,
                    'to' => min($offset + $perPage, $total),
                ],
                'summary' => [
                    'total_earned' => round($totalEarned, 2),
                    'total_pending' => round($totalPending, 2),
                    'total_commissions' => $total,
                ],
            ];

            return ApiResponseService::successResponse('Commissions retrieved successfully', $response);
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to get instructor commissions');
            return ApiResponseService::errorResponse('Failed to get instructor commissions');
        }
    }

    /**
     * Get instructor wallet balance and recent transactions
     */
    public function getWalletDetails(Request $request)
    {
        try {
            $user = Auth::user();

            // Check if user is instructor
            if (!$user->hasRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'))) {
                return ApiResponseService::validationError('You are not authorized to view wallet details');
            }

            $walletBalance = $user->wallet_balance ?? 0;

            // Get recent wallet transactions
            $recentTransactions = WalletHistory::where('user_id', $user?->id)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            // Get commission summary
            $totalEarned = Commission::where('instructor_id', $user->id)->where('status', 'paid')->sum(
                'instructor_commission_amount',
            );

            $totalPending = Commission::where('instructor_id', $user->id)->where('status', 'pending')->sum(
                'instructor_commission_amount',
            );

            $walletDetails = [
                'current_balance' => $walletBalance,
                'total_earned' => $totalEarned,
                'pending_commissions' => $totalPending,
                'recent_transactions' => $recentTransactions,
            ];

            return ApiResponseService::successResponse('Wallet details retrieved successfully', $walletDetails);
        } catch (\Throwable) {
            return ApiResponseService::errorResponse('Failed to retrieve wallet details');
        }
    }

    /**
     * Get courses for authenticated instructor
     * Returns courses with instructor email and name along with course ID and name
     */
    public function getCoursesForCoupon(Request $request)
    {
        try {
            // Get authenticated instructor
            $instructorId = Auth::id();

            // Check if user is instructor
            if (!Auth::user()->hasRole('Instructor')) {
                return ApiResponseService::unauthorizedResponse('Only instructors can access this endpoint.');
            }

            // Get courses belonging to this instructor
            $courses = Course::where('user_id', $instructorId)
                ->where('is_active', true)
                ->get()
                ->map(static fn($course) => [
                    'id' => $course->id,
                    'name' => $course->title,
                ]);

            return ApiResponseService::successResponse('Instructor courses fetched successfully', $courses);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to fetch instructor courses');
            return ApiResponseService::errorResponse('Something went wrong while fetching instructor courses.');
        }
    }

    /**
     * Update Course Status and Settings
     * Draft -> Draft
     * Publish -> Pending
     * If already published -> No change
     *
     * Also allows updating:
     * - sequential_access: Boolean for chapter access control
     * - certificate_enabled: Boolean for certificate availability
     * - certificate_fee: Numeric fee for certificate generation
     */
    public function updateCourseStatus(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'course_id' => 'required|exists:courses,id',
                'status' => 'nullable|in:draft,publish',
                'sequential_access' => 'nullable|boolean',
                'certificate_enabled' => 'nullable|boolean',
                'certificate_fee' => 'nullable|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $courseId = $request->course_id;
            $status = $request->status;
            $instructorId = Auth::id();

            // Check if user is instructor
            if (!Auth::user()->hasRole('Instructor')) {
                return ApiResponseService::unauthorizedResponse('Only instructors can update course status.');
            }

            // Get the course and check if it belongs to this instructor
            $course = Course::where('id', $courseId)->where('user_id', $instructorId)->first();

            if (!$course) {
                return ApiResponseService::errorResponse(
                    'Course not found or you do not have permission to update it.',
                );
            }

            // Prepare the update data
            $updateData = [];

            // Add optional fields if provided
            if ($request->has('sequential_access')) {
                $updateData['sequential_access'] = $request->boolean('sequential_access');
            }
            if ($request->has('certificate_enabled')) {
                $updateData['certificate_enabled'] = $request->boolean('certificate_enabled');
            }
            if ($request->has('certificate_fee')) {
                $updateData['certificate_fee'] = $request->certificate_fee;
            }

            // Update status based on request (only if status is provided)
            if ($request->has('status') && $status !== null) {
                // Check if course is already published (only when trying to change status)
                if ($course->approval_status === 'approved') {
                    return ApiResponseService::errorResponse('Cannot change status of already published course.');
                }

                if ($status === 'draft') {
                    $updateData['is_active'] = false;
                    $message = 'Course status updated to draft successfully.';
                } elseif ($status === 'publish') {
                    $updateData['is_active'] = false;
                    $updateData['status'] = 'pending';
                    $message = 'Course status updated to pending for review.';
                }
            } else {
                // If no status update, just update other fields
                $message = 'Course updated successfully.';
            }

            // Update course if there's data to update
            if (!empty($updateData)) {
                $course->update($updateData);
            }

            // Refresh the course to get updated values
            $course->refresh();

            return ApiResponseService::successResponse($message, [
                'course_id' => $course->id,
                'course_title' => $course->title,
                'current_status' => $course->approval_status,
                'is_active' => $course->is_active,
                'sequential_access' => $course->sequential_access,
                'certificate_enabled' => $course->certificate_enabled,
                'certificate_fee' => $course->certificate_fee,
            ]);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to update course status');
            return ApiResponseService::errorResponse('Something went wrong while updating course status.');
        }
    }

    /**
     * Get instructor's wallet history
     */
    public function getWalletHistory(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'transaction_type' => 'nullable|in:credit,debit,all',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
                'sort_by' => 'nullable|in:id,amount,created_at',
                'sort_order' => 'nullable|in:asc,desc',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $instructorId = Auth::id();

            // Check if user is instructor
            if (!Auth::user()->hasRole('Instructor')) {
                return ApiResponseService::unauthorizedResponse('Only instructors can access wallet history.');
            }

            $transactionType = $request->transaction_type ?? 'all';
            $perPage = $request->per_page ?? 15;
            $page = $request->page ?? 1;
            $sortBy = $request->sort_by ?? 'created_at';
            $sortOrder = $request->sort_order ?? 'desc';

            // Build query
            $query = \App\Models\WalletHistory::where('user_id', $instructorId);

            // Apply transaction type filter
            if ($transactionType === 'credit') {
                $query->where('amount', '>', 0);
            } elseif ($transactionType === 'debit') {
                $query->where('amount', '<', 0);
            }

            // Apply sorting
            $query->orderBy($sortBy, $sortOrder);

            // Get paginated results
            $walletHistory = $query->paginate($perPage, ['*'], 'page', $page);

            // Format the response
            $formattedHistory = $walletHistory->map(static function ($transaction) {
                $isCredit = $transaction->amount > 0;

                return [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'transaction_type' => $isCredit ? 'credit' : 'debit',
                    'amount' => (float) $transaction->amount,
                    'formatted_amount' => ($isCredit ? '+' : '') . '₹' . number_format(abs($transaction->amount), 2),
                    'description' => $transaction->description,
                    'reference_id' => $transaction->reference_id,
                    'reference_type' => $transaction->reference_type,
                    'created_at' => $transaction->created_at,
                    'formatted_date' => $transaction->created_at->format('d M Y, h:i A'),
                ];
            });

            // Calculate summary
            $totalCredit = \App\Models\WalletHistory::where('user_id', $instructorId)->where('amount', '>', 0)->sum(
                'amount',
            );

            $totalDebit = \App\Models\WalletHistory::where('user_id', $instructorId)->where('amount', '<', 0)->sum(
                'amount',
            );

            $currentBalance = Auth::user()->wallet_balance ?? 0;

            $response = [
                'transactions' => $formattedHistory,
                'pagination' => [
                    'current_page' => $walletHistory->currentPage(),
                    'per_page' => $walletHistory->perPage(),
                    'total' => $walletHistory->total(),
                    'last_page' => $walletHistory->lastPage(),
                    'has_more_pages' => $walletHistory->hasMorePages(),
                ],
                'summary' => [
                    'current_balance' => (float) $currentBalance,
                    'formatted_balance' => '₹' . number_format($currentBalance, 2),
                    'total_credit' => (float) $totalCredit,
                    'formatted_total_credit' => '₹' . number_format($totalCredit, 2),
                    'total_debit' => (float) abs($totalDebit),
                    'formatted_total_debit' => '₹' . number_format(abs($totalDebit), 2),
                    'net_amount' => (float) ($totalCredit + $totalDebit),
                    'formatted_net_amount' => '₹' . number_format($totalCredit + $totalDebit, 2),
                ],
                'filters' => [
                    'transaction_type' => $transactionType,
                    'sort_by' => $sortBy,
                    'sort_order' => $sortOrder,
                ],
            ];

            return ApiResponseService::successResponse('Wallet history retrieved successfully', $response);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to retrieve wallet history');
            return ApiResponseService::errorResponse('Something went wrong while retrieving wallet history.');
        }
    }

    /**
     * Get assignment submissions for instructor's courses
     */
    public function getAssignmentSubmissions(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'course_id' => 'nullable|exists:courses,id',
                'assignment_id' => 'nullable|exists:course_chapter_assignments,id',
                'assignment_slug' => 'nullable|string|exists:course_chapter_assignments,slug',
                'status' => 'nullable|in:pending,submitted,accepted,rejected,suspended',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
                'sort_by' => 'nullable|in:id,created_at,points',
                'sort_order' => 'nullable|in:asc,desc',
                'search' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $instructorId = Auth::id();

            // Check if user is instructor
            if (!Auth::user()->hasRole('Instructor')) {
                return ApiResponseService::unauthorizedResponse('Only instructors can view assignment submissions.');
            }

            // Custom validation: only one of assignment_id or assignment_slug should be provided
            if ($request->filled('assignment_id') && $request->filled('assignment_slug')) {
                return ApiResponseService::validationError(
                    'Please provide either assignment_id or assignment_slug, not both',
                );
            }

            // Build query for assignments belonging to instructor's courses or assigned courses
            $query = UserAssignmentSubmission::with([
                'user:id,name,email,profile',
                'assignment.chapter.course:id,title,slug',
                'files',
            ])->whereHas('assignment.chapter.course', static function ($courseQuery) use ($instructorId): void {
                // Check if instructor owns the course OR is assigned as instructor
                $courseQuery->where(static function ($q) use ($instructorId): void {
                    $q->where('user_id', $instructorId)->orWhereExists(static function ($subQuery) use (
                        $instructorId,
                    ): void {
                        $subQuery
                            ->select(DB::raw(1))
                            ->from('course_instructors')
                            ->whereColumn('course_instructors.course_id', 'courses.id')
                            ->where('course_instructors.user_id', $instructorId)
                            ->whereNull('course_instructors.deleted_at');
                    });
                });
            });

            // Filter by course
            if ($request->filled('course_id')) {
                $query->whereHas('assignment.chapter', static function ($chapterQuery) use ($request): void {
                    $chapterQuery->where('course_id', $request->course_id);
                });
            }

            // Filter by assignment
            if ($request->filled('assignment_id')) {
                $query->where('course_chapter_assignment_id', $request->assignment_id);
            } elseif ($request->filled('assignment_slug')) {
                $assignment = \App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment::where(
                    'slug',
                    $request->assignment_slug,
                )->first();
                if ($assignment) {
                    $query->where('course_chapter_assignment_id', $assignment->id);
                } else {
                    return ApiResponseService::validationError('Assignment not found');
                }
            }

            // Filter by status
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Search functionality
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(static function ($q) use ($search): void {
                    $q
                        ->whereHas('user', static function ($userQuery) use ($search): void {
                            $userQuery->where('name', 'LIKE', "%{$search}%")->orWhere('email', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('assignment', static function ($assignmentQuery) use ($search): void {
                            $assignmentQuery->where('title', 'LIKE', "%{$search}%");
                        })
                        ->orWhere('comment', 'LIKE', "%{$search}%");
                });
            }

            // Sorting
            $sortField = $request->sort_by ?? 'created_at';
            $sortOrder = $request->sort_order ?? 'desc';

            // Map submitted_at to created_at since submitted_at column doesn't exist
            if ($sortField === 'submitted_at') {
                $sortField = 'created_at';
            }

            $query->orderBy($sortField, $sortOrder);

            // Pagination
            $perPage = $request->per_page ?? 15;
            $submissions = $query->paginate($perPage);

            if ($submissions->isEmpty()) {
                return ApiResponseService::validationError('No assignment submissions found');
            }

            // Transform data
            $submissions
                ->getCollection()
                ->transform(static fn($submission) => [
                    'id' => $submission->id,
                    'user' => [
                        'id' => $submission->user->id,
                        'name' => $submission->user->name,
                        'email' => $submission->user->email,
                        'profile' => $submission->user->profile,
                    ],
                    'assignment' => [
                        'id' => $submission->assignment->id,
                        'title' => $submission->assignment->title,
                        'points' => $submission->assignment->points,
                        'chapter_name' => $submission->assignment->chapter->title,
                    ],
                    'course' => [
                        'id' => $submission->assignment->chapter->course->id,
                        'title' => $submission->assignment->chapter->course->title,
                        'slug' => $submission->assignment->chapter->course->slug,
                    ],
                    'status' => $submission->status,
                    'comment' => $submission->comment,
                    'points' => $submission->points,
                    'feedback' => $submission->feedback,
                    'submitted_at' => $submission->created_at,
                    'files' => $submission->files->map(static fn($file) => [
                        'id' => $file->id,
                        'type' => $file->type,
                        'file' => !empty($file->file) ? \App\Services\FileService::getFileUrl($file->file) : null,
                        'url' => $file->url,
                        'file_extension' => $file->file_extension,
                    ]),
                ]);

            // Get assignment information if assignment_id or assignment_slug is provided
            $assignmentInfo = null;
            if ($request->filled('assignment_id') || $request->filled('assignment_slug')) {
                $assignment = null;
                if ($request->filled('assignment_id')) {
                    $assignment = \App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment::with([
                        'chapter.course',
                    ])->find($request->assignment_id);
                } elseif ($request->filled('assignment_slug')) {
                    $assignment = \App\Models\Course\CourseChapter\Assignment\CourseChapterAssignment::with([
                        'chapter.course',
                    ])->where('slug', $request->assignment_slug)->first();
                }

                if ($assignment) {
                    $assignmentInfo = [
                        'id' => $assignment->id,
                        'title' => $assignment->title,
                        'slug' => $assignment->slug,
                        'description' => $assignment->description,
                        'instructions' => $assignment->instructions,
                        'points' => $assignment->points,
                        'max_file_size' => $assignment->max_file_size,
                        'allowed_file_types' => $assignment->allowed_file_types,
                        'media' => $assignment->media,
                        'media_extension' => $assignment->media_extension,
                        'is_active' => $assignment->is_active,
                        'can_skip' => $assignment->can_skip,
                        'order' => $assignment->order,
                        'chapter_order' => $assignment->chapter_order,
                        'created_at' => $assignment->created_at,
                        'updated_at' => $assignment->updated_at,
                        'chapter' => [
                            'id' => $assignment->chapter->id,
                            'title' => $assignment->chapter->title,
                            'chapter_order' => $assignment->chapter->chapter_order,
                            'is_active' => $assignment->chapter->is_active,
                        ],
                        'course' => [
                            'id' => $assignment->chapter->course->id,
                            'title' => $assignment->chapter->course->title,
                            'slug' => $assignment->chapter->course->slug,
                        ],
                    ];
                }
            }

            // Prepare response data
            $responseData = [
                'assignment' => $assignmentInfo,
                'current_page' => $submissions->currentPage(),
                'data' => $submissions->items(),
                'first_page_url' => $submissions->url(1),
                'from' => $submissions->firstItem(),
                'last_page' => $submissions->lastPage(),
                'last_page_url' => $submissions->url($submissions->lastPage()),
                'links' => $submissions->linkCollection()->toArray(),
                'next_page_url' => $submissions->nextPageUrl(),
                'path' => $submissions->path(),
                'per_page' => $submissions->perPage(),
                'prev_page_url' => $submissions->previousPageUrl(),
                'to' => $submissions->lastItem(),
                'total' => $submissions->total(),
            ];

            return ApiResponseService::successResponse('Assignment submissions retrieved successfully', $responseData);
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to get assignment submissions');
            return ApiResponseService::errorResponse('Failed to retrieve assignment submissions' . $e);
        }
    }

    /**
     * Update assignment submission status and grade
     */
    public function updateAssignmentSubmission(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'submission_id' => 'required|exists:user_assignment_submissions,id',
                'status' => 'required|in:accepted,rejected',
                'points' => 'required|numeric|min:0',
                'feedback' => 'required|string|max:1000',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $authUser = Auth::user();

            // Check if user is instructor
            if (!$authUser->hasRole('Instructor')) {
                return ApiResponseService::unauthorizedResponse('Only instructors can update assignment submissions.');
            }

            // Get submission with course relationship
            $submission = UserAssignmentSubmission::with(['assignment.chapter.course'])->where(
                'id',
                $request->submission_id,
            )->first();

            if (!$submission) {
                return ApiResponseService::validationError('Assignment submission not found');
            }

            $course = $submission->assignment->chapter->course;
            $isCourseOwner = $course->user_id === $authUser->id;

            // Check if user is a team member of the course instructor OR course owner is team member of auth instructor
            $isTeamMember = false;
            if (!$isCourseOwner) {
                // Case 1: Auth user is a team member of the course owner's instructor
                $courseOwnerInstructor = \App\Models\Instructor::where('user_id', $course->user_id)->first();

                if ($courseOwnerInstructor) {
                    $isTeamMember = TeamMember::where('instructor_id', $courseOwnerInstructor->id)
                        ->where('user_id', $authUser->id)
                        ->where('status', 'approved')
                        ->exists();
                }

                // Case 2: Auth user is an instructor and course owner is their team member
                if (!$isTeamMember) {
                    $authInstructor = \App\Models\Instructor::where('user_id', $authUser->id)->first();

                    if ($authInstructor) {
                        $isTeamMember = TeamMember::where('instructor_id', $authInstructor->id)
                            ->where('user_id', $course->user_id)
                            ->where('status', 'approved')
                            ->exists();
                    }
                }
            }

            // Authorization check: Course Owner or Approved Team Member
            if (!$isCourseOwner && !$isTeamMember) {
                return ApiResponseService::validationError(
                    'You do not have permission to update this assignment submission',
                );
            }

            // Prepare update data
            $updateData = [
                'status' => $request->status,
            ];

            // Add points if provided and status is accepted
            if ($request->status === 'accepted' && $request->has('points')) {
                $updateData['points'] = $request->points;
            }

            // Add comment if provided
            if ($request->has('feedback')) {
                $updateData['feedback'] = $request->feedback;
            }

            $submission->update($updateData);

            // Load updated submission with relationships
            $submission->load(['user:id,name,email', 'assignment.chapter.course:id,title', 'files']);

            $response = [
                'id' => $submission->id,
                'user' => [
                    'id' => $submission->user->id,
                    'name' => $submission->user->name,
                    'email' => $submission->user->email,
                ],
                'assignment' => [
                    'id' => $submission->assignment->id,
                    'title' => $submission->assignment->title,
                    'max_points' => $submission->assignment->points,
                ],
                'course' => [
                    'id' => $submission->assignment->chapter->course->id,
                    'title' => $submission->assignment->chapter->course->title,
                ],
                'status' => $submission->status,
                'points' => $submission->points,
                'feedback' => $submission->feedback,
                'updated_at' => $submission->updated_at,
            ];

            return ApiResponseService::successResponse('Assignment submission updated successfully', $response);
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to update assignment submission');
            return ApiResponseService::errorResponse('Failed to update assignment submission');
        }
    }

    /**
     * Get assignment submission details
     */
    public function getAssignmentSubmissionDetails(Request $request, $submissionId = null)
    {
        try {
            $id = $submissionId ?: $request->get('id');

            if (!$id) {
                return ApiResponseService::validationError('Submission ID is required');
            }

            $instructorId = Auth::id();

            // Check if user is instructor
            if (!Auth::user()->hasRole('Instructor')) {
                return ApiResponseService::unauthorizedResponse(
                    'Only instructors can view assignment submission details.',
                );
            }

            // Get submission with all relationships
            $submission = UserAssignmentSubmission::with([
                'user:id,name,email,profile',
                'assignment.chapter.course:id,title,slug',
                'files',
            ])
                ->where('id', $id)
                ->whereHas('assignment.chapter.course', static function ($courseQuery) use ($instructorId): void {
                    $courseQuery->where('user_id', $instructorId);
                })
                ->first();

            if (!$submission) {
                return ApiResponseService::validationError(
                    'Assignment submission not found or you do not have permission to view it',
                );
            }

            $response = [
                'id' => $submission->id,
                'user' => [
                    'id' => $submission->user->id,
                    'name' => $submission->user->name,
                    'email' => $submission->user->email,
                    'profile' => $submission->user->profile,
                ],
                'assignment' => [
                    'id' => $submission->assignment->id,
                    'title' => $submission->assignment->title,
                    'description' => $submission->assignment->description,
                    'instructions' => $submission->assignment->instructions,
                    'points' => $submission->assignment->points,
                ],
                'course' => [
                    'id' => $submission->assignment->chapter->course->id,
                    'title' => $submission->assignment->chapter->course->title,
                    'slug' => $submission->assignment->chapter->course->slug,
                ],
                'status' => $submission->status,
                'comment' => $submission->comment,
                'points' => $submission->points,
                'feedback' => $submission->feedback,
                'submitted_at' => $submission->created_at,
                'updated_at' => $submission->updated_at,
                'files' => $submission->files->map(static fn($file) => [
                    'id' => $file->id,
                    'type' => $file->type,
                    'file' => $file->file,
                    'url' => $file->url,
                    'file_extension' => $file->file_extension,
                ]),
            ];

            return ApiResponseService::successResponse(
                'Assignment submission details retrieved successfully',
                $response,
            );
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'Failed to get assignment submission details');
            return ApiResponseService::errorResponse('Failed to retrieve assignment submission details');
        }
    }

    /**
     * Replace old pagination format with Laravel format
     */
    private function replacePaginationFormat($data, $currentPage, $perPage, $total)
    {
        $lastPage = $perPage > 0 ? ceil($total / $perPage) : 0;
        $offset = ($currentPage - 1) * $perPage;
        $baseUrl = request()->url();

        return [
            'current_page' => $currentPage,
            'data' => $data,
            'first_page_url' => $baseUrl . '?page=1',
            'from' => $total > 0 ? $offset + 1 : null,
            'last_page' => $lastPage,
            'last_page_url' => $baseUrl . '?page=' . $lastPage,
            'links' => $this->generatePaginationLinks($currentPage, $lastPage, $baseUrl),
            'next_page_url' => $currentPage < $lastPage ? $baseUrl . '?page=' . ($currentPage + 1) : null,
            'path' => $baseUrl,
            'per_page' => $perPage,
            'prev_page_url' => $currentPage > 1 ? $baseUrl . '?page=' . ($currentPage - 1) : null,
            'to' => $total > 0 ? min($offset + $perPage, $total) : null,
            'total' => $total,
        ];
    }

    /**
     * Generate pagination links
     */
    private function generatePaginationLinks($currentPage, $lastPage, $baseUrl)
    {
        $links = [];

        // Previous page link
        $links[] = [
            'url' => $currentPage > 1 ? $baseUrl . '?page=' . ($currentPage - 1) : null,
            'label' => '&laquo; Previous',
            'active' => false,
        ];

        // Page number links
        $start = max(1, $currentPage - 2);
        $end = min($lastPage, $currentPage + 2);

        for ($i = $start; $i <= $end; $i++) {
            $links[] = [
                'url' => $baseUrl . '?page=' . $i,
                'label' => (string) $i,
                'active' => $i === $currentPage,
            ];
        }

        // Next page link
        $links[] = [
            'url' => $currentPage < $lastPage ? $baseUrl . '?page=' . ($currentPage + 1) : null,
            'label' => 'Next &raquo;',
            'active' => false,
        ];

        return $links;
    }

    /**
     * Get active categories for instructor panel in tree structure
     * Uses the same logic as HelperService::getActiveCategories()
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCategories(Request $request)
    {
        try {
            // Get all active categories
            $allCategories = Category::where('status', 1)
                ->where(static function ($query): void {
                    $query
                        ->whereHas('parent_category', static function ($query): void {
                            $query->where('status', 1);
                        })
                        ->orWhereNull('parent_category_id');
                })
                ->orderBy('sequence')
                ->get();

            // Helper function to build category tree recursively
            $buildTree = static function ($parentId = null) use (&$buildTree, $allCategories) {
                $result = [];
                foreach ($allCategories as $category) {
                    if ($category->parent_category_id != $parentId) {
                        continue;
                    }

                    $categoryData = [
                        'id' => $category->id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                        'description' => $category->description,
                        'image' => $category->image,
                        'status' => $category->status,
                        'parent_category_id' => $category->parent_category_id,
                        'sequence' => $category->sequence,
                        'created_at' => $category->created_at ? $category->created_at->format('Y-m-d H:i:s') : null,
                        'updated_at' => $category->updated_at ? $category->updated_at->format('Y-m-d H:i:s') : null,
                    ];

                    // Recursively get children
                    $children = $buildTree($category->id);
                    if (!empty($children)) {
                        $categoryData['subcategories'] = $children;
                    } else {
                        $categoryData['subcategories'] = [];
                    }

                    $result[] = $categoryData;
                }
                return $result;
            };

            // Build tree starting from root categories (parent_category_id is null)
            $categoryTree = $buildTree(null);

            return ApiResponseService::successResponse('Categories retrieved successfully', $categoryTree);
        } catch (Exception $e) {
            ApiResponseService::logErrorResponse($e, 'InstructorApiController -> getCategories method');
            return ApiResponseService::errorResponse('Failed to retrieve categories');
        }
    }

    /**
     * Format duration in seconds to human-readable format
     */
    private function formatDuration($seconds)
    {
        if ($seconds < 60) {
            return $seconds . 's';
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $remainingSeconds = $seconds % 60;
            return $remainingSeconds > 0 ? $minutes . 'm ' . $remainingSeconds . 's' : $minutes . 'm';
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            $remainingSeconds = $seconds % 60;

            $formatted = $hours . 'h';
            if ($minutes > 0) {
                $formatted .= ' ' . $minutes . 'm';
            }
            if ($remainingSeconds > 0) {
                $formatted .= ' ' . $remainingSeconds . 's';
            }
            return $formatted;
        }
    }
}
