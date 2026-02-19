<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\HelpdeskGroup;
use App\Models\HelpdeskGroupRequest;
use App\Models\HelpdeskQuestion;
use App\Models\HelpdeskReply;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class HelpdeskApiController extends Controller
{
    /* ---------------- PUBLIC APIS ---------------- */

    // List all groups (no auth required)
    public function groups(Request $request)
    {
        try {
            $search = $request->input('search', '');

            $groupsQuery = HelpdeskGroup::where('is_active', true);

            // Apply search filter if provided
            if (!empty($search)) {
                $groupsQuery->where(static function ($q) use ($search): void {
                    $q->where('name', 'LIKE', "%{$search}%")->orWhere('description', 'LIKE', "%{$search}%");
                });
            }

            $groups = $groupsQuery->orderBy('row_order', 'asc')->get();

            // Format groups to include full image URLs
            $formattedGroups = $groups->map(static fn($group) => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'description' => $group->description,
                'image' => $group->image ? asset('storage/' . $group->image) : null,
                'is_private' => $group->is_private,
                'row_order' => $group->row_order,
                'is_active' => $group->is_active,
                'created_at' => $group->created_at,
                'updated_at' => $group->updated_at,
            ]);

            return ApiResponseService::successResponse('Groups fetched successfully', $formattedGroups);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> groups method');
            return ApiResponseService::errorResponse();
        }
    }

    // Get group details by ID (no auth required)
    public function getGroupDetails(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|exists:helpdesk_groups,id',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $group = HelpdeskGroup::where('id', $request->id)
                ->where('is_active', true)
                ->with(['requests' => static function ($query): void {
                    $query->where('status', 'approved');
                }])
                ->first();

            if (!$group) {
                return ApiResponseService::validationError('Group not found or inactive');
            }

            // Check if authenticated user has sent a request (for private groups)
            $userRequestStatus = null;
            $hasUserSentRequest = false;

            if ($group->is_private && Auth::check()) {
                $userRequest = HelpdeskGroupRequest::where('group_id', $group->id)
                    ->where('user_id', Auth::id())
                    ->first();

                if ($userRequest) {
                    $hasUserSentRequest = true;
                    $userRequestStatus = $userRequest->status; // pending, approved, or rejected
                }
            }

            // Format the response
            $groupData = [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'description' => $group->description,
                'image' => $group->image ? asset('storage/' . $group->image) : null,
                'is_private' => $group->is_private,
                'row_order' => $group->row_order,
                'is_active' => $group->is_active,
                'created_at' => $group->created_at,
                'updated_at' => $group->updated_at,
                'member_count' => $group->requests->count(),
                'has_user_sent_request' => $hasUserSentRequest,
                'user_request_status' => $userRequestStatus, // null, pending, approved, or rejected
            ];

            return ApiResponseService::successResponse('Group details fetched successfully', $groupData);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> getGroupDetails method');
            return ApiResponseService::errorResponse();
        }
    }

    // Check if user is approved for a group (auth required)
    public function checkGroupApproval(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'group_id' => 'nullable|integer|exists:helpdesk_groups,id',
                'group_slug' => 'nullable|string|exists:helpdesk_groups,slug',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            // Check if either group_id or group_slug is provided
            if (!$request->filled('group_id') && !$request->filled('group_slug')) {
                return ApiResponseService::validationError('Either group_id or group_slug is required');
            }

            $userId = Auth::id();
            $group = null;

            // Get group by ID or slug
            if ($request->filled('group_id')) {
                $group = HelpdeskGroup::where('id', $request->group_id)->where('is_active', true)->first();
            } else {
                $group = HelpdeskGroup::where('slug', $request->group_slug)->where('is_active', true)->first();
            }

            if (!$group) {
                return ApiResponseService::validationError('Group not found or inactive');
            }

            // Check if user is approved for this group
            $isApproved = HelpdeskGroupRequest::where('user_id', $userId)
                ->where('group_id', $group->id)
                ->where('status', 'approved')
                ->exists();

            // Get user's request status if exists
            $userRequest = HelpdeskGroupRequest::where('user_id', $userId)->where('group_id', $group->id)->first();

            $response = [
                'group' => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'slug' => $group->slug,
                    'description' => $group->description,
                    'image' => $group->image ? asset('storage/' . $group->image) : null,
                    'is_private' => $group->is_private,
                    'is_active' => $group->is_active,
                ],
                'is_approved' => $isApproved,
                'user_request_status' => $userRequest ? $userRequest->status : null,
                'can_post_questions' => $isApproved || !$group->is_private,
            ];

            return ApiResponseService::successResponse('Group approval status checked successfully', $response);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> checkGroupApproval method');
            return ApiResponseService::errorResponse();
        }
    }

    // List all questions (no auth required)
    public function questions(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'group_id' => 'nullable|exists:helpdesk_groups,id',
                'group_slug' => 'nullable|exists:helpdesk_groups,slug',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
                'search' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            // Build query with relationships
            $query = HelpdeskQuestion::with(['user', 'group', 'replies']);

            // Filter by group if provided
            if ($request->filled('group_id')) {
                $query->where('group_id', $request->group_id);
            } elseif ($request->filled('group_slug')) {
                $query->whereHas('group', static function ($q) use ($request): void {
                    $q->where('slug', $request->group_slug);
                });
            }

            // Search functionality
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(static function ($q) use ($search): void {
                    $q
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('user', static function ($userQuery) use ($search): void {
                            $userQuery->where('name', 'like', "%{$search}%");
                        });
                });
            }

            // Get total counts - if group filter is applied, count only that group's questions
            if ($request->filled('group_id') || $request->filled('group_slug')) {
                // Count only questions from the filtered group
                $totalQuestions = $query->count();
                $totalReplies = HelpdeskQuestion::whereHas('group', static function ($q) use ($request): void {
                    if ($request->filled('group_id')) {
                        $q->where('id', $request->group_id);
                    } else {
                        $q->where('slug', $request->group_slug);
                    }
                })
                    ->withCount('replies')
                    ->get()
                    ->sum('replies_count');
            } else {
                // Count all questions if no group filter
                $totalQuestions = HelpdeskQuestion::count();
                $totalReplies = HelpdeskQuestion::withCount('replies')->get()->sum('replies_count');
            }

            // Apply pagination
            $perPage = $request->per_page ?? 15;
            $currentPage = $request->page ?? 1;

            $questions = $query->latest()->paginate($perPage, ['*'], 'page', $currentPage);

            // Format questions data
            $formattedQuestions = $questions->map(static function ($question) {
                $user = $question->user;
                $group = $question->group;
                $createdAt = $question->created_at;

                return [
                    'id' => $question->id,
                    'slug' => $question->slug,
                    'title' => $question->title,
                    'description' => $question->description,
                    'is_private' => $question->is_private,
                    'created_at' => $createdAt,
                    'created_at_formatted' => $createdAt ? $createdAt->format('Y-m-d H:i:s') : null,
                    'time_ago' => $createdAt ? $createdAt->diffForHumans() : null,
                    'updated_at' => $question->updated_at,
                    'author' => [
                        'id' => $user->id ?? null,
                        'name' => $user->name ?? null,
                        'avatar' => $user->profile ?? null,
                    ],
                    'group' => [
                        'id' => $group->id ?? null,
                        'name' => $group->name ?? null,
                        'slug' => $group->slug ?? null,
                    ],
                    'replies_count' => $question->replies ? $question->replies->count() : 0,
                    'views_count' => 0, // Add views count if you have this field
                ];
            });

            $response = [
                'data' => $formattedQuestions,
                'current_page' => $questions->currentPage(),
                'last_page' => $questions->lastPage(),
                'per_page' => $questions->perPage(),
                'total' => $questions->total(),
                'from' => $questions->firstItem(),
                'to' => $questions->lastItem(),
                'totals' => [
                    'total_questions' => $totalQuestions,
                    'total_replies' => $totalReplies,
                ],
            ];

            return ApiResponseService::successResponse('Questions fetched successfully', $response);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> questions method');
            return ApiResponseService::errorResponse();
        }
    }

    // View single question with replies (no auth required)
    public function showQuestion(Request $request)
    {
        try {
            // Validate request - either slug or question_id is required
            $validator = Validator::make($request->all(), [
                'slug' => 'nullable|string',
                'question_id' => 'nullable|integer',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            // Check if either slug or question_id is provided
            if (!$request->filled('slug') && !$request->filled('question_id')) {
                return ApiResponseService::validationError('Either slug or question_id is required');
            }

            // Get pagination parameters
            $perPage = $request->get('per_page', 10);
            $page = $request->get('page', 1);

            // Fetch question based on slug or question_id
            if ($request->filled('slug')) {
                $question = HelpdeskQuestion::with(['user', 'group'])->where('slug', $request->slug)->first();

                if (!$question) {
                    return ApiResponseService::validationError('Question not found with the given slug');
                }
            } else {
                $question = HelpdeskQuestion::with(['user', 'group'])->find($request->question_id);

                if (!$question) {
                    return ApiResponseService::validationError('Question not found with the given ID');
                }
            }

            // Get paginated replies
            $replies = HelpdeskReply::where('question_id', $question->id)
                ->whereNull('parent_id') // only top-level replies
                ->with(['user', 'children.user'])
                ->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            // Increment view count
            $question->increment('views');

            // Format the response with additional fields
            $formattedQuestion = [
                'question' => [
                    'id' => $question->id,
                    'title' => $question->title,
                    'description' => $question->description,
                    'slug' => $question->slug,
                    'views' => $question->views,
                    'created_at' => $question->created_at,
                    'updated_at' => $question->updated_at,
                    'time_ago' => $question->created_at->diffForHumans(),
                    'author' => [
                        'id' => $question->user->id,
                        'name' => $question->user->name,
                        'avatar' => $question->user->profile ? asset('storage/' . $question->user->profile) : null,
                        'email' => $question->user->email,
                    ],
                    'group' => [
                        'id' => $question->group->id,
                        'name' => $question->group->name,
                        'slug' => $question->group->slug,
                    ],
                    'replies_count' => $question->replies()->count(),
                ],
                'replies' => $replies->map(static fn($reply) => [
                    'id' => $reply->id,
                    'reply' => $reply->reply,
                    'created_at' => $reply->created_at,
                    'time_ago' => $reply->created_at->diffForHumans(),
                    'author' => [
                        'id' => $reply->user->id,
                        'name' => $reply->user->name,
                        'avatar' => $reply->user->profile ? asset('storage/' . $reply->user->profile) : null,
                    ],
                    'children' => $reply->children->map(static fn($child) => [
                        'id' => $child->id,
                        'reply' => $child->reply,
                        'created_at' => $child->created_at,
                        'time_ago' => $child->created_at->diffForHumans(),
                        'author' => [
                            'id' => $child->user->id,
                            'name' => $child->user->name,
                            'avatar' => $child->user->profile ? asset('storage/' . $child->user->profile) : null,
                        ],
                    ]),
                ]),
                'current_page' => $replies->currentPage(),
                'per_page' => $replies->perPage(),
                'total' => $replies->total(),
                'last_page' => $replies->lastPage(),
                'from' => $replies->firstItem(),
                'to' => $replies->lastItem(),
                'has_more_pages' => $replies->hasMorePages(),
            ];

            return ApiResponseService::successResponse('Question fetched successfully', $formattedQuestion);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> showQuestion method');
            return ApiResponseService::errorResponse();
        }
    }

    /* ---------------- AUTH REQUIRED APIS ---------------- */
    public function __construct()
    {
        // Apply auth only on specific routes
        $this->middleware('auth:sanctum')->only([
            'storeGroup',
            'requestJoin',
            'approveRequest',
            'rejectRequest',
            'storeQuestion',
            'storeReply',
            'checkGroupApproval',
        ]);
    }

    /* ---------------- GROUPS (ADMIN ONLY) ---------------- */
    public function storeGroup(Request $request)
    {
        try {
            // Optional: check admin role
            if (!Auth::user()?->is_admin) {
                return ApiResponseService::errorResponse('Only admins can create groups');
            }

            $request->validate([
                'name' => 'required|string|max:255',
            ]);

            $group = HelpdeskGroup::create([
                'name' => $request->name,
                'description' => $request->description,
                'is_private' => $request->is_private ?? false,
                'row_order' => $request->row_order,
            ]);

            return ApiResponseService::successResponse('Group created successfully', $group);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> getCounts method');
            return ApiResponseService::errorResponse();
        }
    }

    /* ---------------- GROUP REQUESTS ---------------- */
    public function requestJoin(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'group_id' => 'nullable|exists:helpdesk_groups,id',
                'group_slug' => 'nullable|exists:helpdesk_groups,slug',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::errorResponse($validator->errors()->first());
            }

            // Check if either group_id or group_slug is provided
            if (!$request->filled('group_id') && !$request->filled('group_slug')) {
                return ApiResponseService::errorResponse('Either group_id or group_slug is required');
            }

            // ✅ Fetch group details by ID or slug
            if ($request->filled('group_id')) {
                $group = HelpdeskGroup::find($request->group_id);
            } else {
                $group = HelpdeskGroup::where('slug', $request->group_slug)->first();
            }

            $exists = HelpdeskGroupRequest::where('group_id', $group->id)
                ->where('user_id', Auth::user()?->id)
                ->where('status', 'pending')
                ->first();

            if ($exists) {
                return ApiResponseService::errorResponse('You already have a pending request for this group');
            }

            $req = HelpdeskGroupRequest::create([
                'group_id' => $group->id,
                'user_id' => Auth::id(),
                'status' => 'pending',
            ]);

            return ApiResponseService::successResponse('Join request submitted successfully', $req);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> requestJoin method');
            return ApiResponseService::errorResponse();
        }
    }

    /* ---------------- QUESTIONS ---------------- */
    public function storeQuestion(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'group_id' => 'nullable|exists:helpdesk_groups,id',
                'group_slug' => 'nullable|exists:helpdesk_groups,slug',
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::errorResponse($validator->errors()->first());
            }

            // Check if either group_id or group_slug is provided
            if (!$request->filled('group_id') && !$request->filled('group_slug')) {
                return ApiResponseService::errorResponse('Either group_id or group_slug is required');
            }

            $userId = Auth::id();

            // ✅ Fetch group details by ID or slug
            if ($request->filled('group_id')) {
                $group = HelpdeskGroup::find($request->group_id);
            } else {
                $group = HelpdeskGroup::where('slug', $request->group_slug)->first();
            }

            // ✅ If group is private then check approval
            if ($group->is_private == 1) {
                $isApproved = HelpdeskGroupRequest::where('user_id', $userId)
                    ->where('group_id', $group->id)
                    ->where('status', 'approved')
                    ->exists();

                if (!$isApproved) {
                    return ApiResponseService::errorResponse(
                        'You are not approved to post questions in this private group.',
                        [],
                        403,
                    );
                }
            }

            // ✅ Create question
            $question = HelpdeskQuestion::create([
                'group_id' => $group->id,
                'user_id' => $userId,
                'title' => $request->title,
                'description' => $request->description,
                'is_private' => $request->is_private ?? false,
            ]);

            return ApiResponseService::successResponse('Question posted successfully', $question);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> storeQuestion method');
            return ApiResponseService::errorResponse();
        }
    }

    /* ---------------- REPLIES ---------------- */
    public function storeReply(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'question_id' => 'required|exists:helpdesk_questions,id',
                'reply' => 'required|string',
                'parent_id' => 'nullable|exists:helpdesk_replies,id',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::errorResponse($validator->errors()->first());
            }

            $question = HelpdeskQuestion::with('group')->findOrFail($request->question_id);

            // ✅ Check group approval only if group is private
            if ($question->group->is_private) {
                $isApproved = HelpdeskGroupRequest::where('group_id', $question->group_id)
                    ->where('user_id', Auth::user()?->id)
                    ->where('status', 'approved')
                    ->exists();

                if (!$isApproved) {
                    return ApiResponseService::errorResponse('Your group request is not approved yet', 403);
                }
            }

            $reply = HelpdeskReply::create([
                'question_id' => $request->question_id,
                'user_id' => Auth::user()?->id,
                'reply' => $request->reply,
                'parent_id' => $request->parent_id, // ✅ can be null or a reply id
            ]);

            // Reload the reply with relationships to match CourseDiscussion format
            $reply = HelpdeskReply::with(['user', 'children.user'])->find($reply->id);

            // Add time_ago for the reply
            $reply->time_ago = $reply?->created_at->diffForHumans();

            // Add reply_count for the reply (nested replies count)
            $reply->reply_count = $reply?->children->count();

            // Transform nested replies to add time_ago and rename to 'replies' for consistency
            $reply->replies = $reply->children->map(static function ($nestedReply) {
                $nestedReply->time_ago = $nestedReply->created_at->diffForHumans();
                return $nestedReply;
            });

            // Remove the 'children' key to avoid duplication
            unset($reply->children);

            return ApiResponseService::successResponse('Reply added successfully', $reply);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> getCounts method');
            return ApiResponseService::errorResponse();
        }
    }

    /**
     * Search helpdesk content (questions and groups)
     */
    public function search(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'query' => 'required|string|min:2|max:255',
                'type' => 'nullable|in:questions,groups,all',
                'group_id' => 'nullable|exists:helpdesk_groups,id',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
            ]);

            if ($validator->fails()) {
                return ApiResponseService::validationError($validator->errors()->first());
            }

            $query = $request->input('query');
            $type = $request->input('type', 'all');
            $perPage = $request->input('per_page', 15);
            $page = $request->input('page', 1);
            $groupId = $request->input('group_id');

            $results = [
                'questions' => [
                    'data' => [],
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => 0,
                    'last_page' => 0,
                ],
                'groups' => [
                    'data' => [],
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => 0,
                    'last_page' => 0,
                ],
            ];

            // Search questions if type is 'questions' or 'all'
            if ($type === 'questions' || $type === 'all') {
                $questionsQuery = HelpdeskQuestion::where('is_private', false)
                    ->where(static function ($q) use ($query): void {
                        $q->where('title', 'LIKE', "%{$query}%")->orWhere('description', 'LIKE', "%{$query}%");
                    })
                    ->with(['group', 'user', 'replies']);

                if ($groupId) {
                    $questionsQuery->where('group_id', $groupId);
                }

                $questions = $questionsQuery->paginate($perPage, ['*'], 'questions_page', $page);

                $results['questions']['data'] = $questions->map(static fn($question) => [
                    'id' => $question->id,
                    'title' => $question->title,
                    'slug' => $question->slug,
                    'description' => $question->description,
                    'views' => $question->views,
                    'is_private' => $question->is_private,
                    'group' => $question->group
                        ? [
                            'id' => $question->group->id,
                            'name' => $question->group->name,
                            'slug' => $question->group->slug,
                        ] : null,
                    'user' => $question->user
                        ? [
                            'id' => $question->user->id,
                            'name' => $question->user->name,
                        ] : null,
                    'replies_count' => $question->replies->count(),
                    'created_at' => $question->created_at,
                    'updated_at' => $question->updated_at,
                ]);

                $results['questions']['current_page'] = $questions->currentPage();
                $results['questions']['per_page'] = $questions->perPage();
                $results['questions']['total'] = $questions->total();
                $results['questions']['last_page'] = $questions->lastPage();
                $results['questions']['from'] = $questions->firstItem();
                $results['questions']['to'] = $questions->lastItem();
            }

            // Search groups if type is 'groups' or 'all'
            if ($type === 'groups' || $type === 'all') {
                $groupsQuery = HelpdeskGroup::where('is_active', true)->where(static function ($q) use ($query): void {
                    $q->where('name', 'LIKE', "%{$query}%")->orWhere('description', 'LIKE', "%{$query}%");
                });

                $groups = $groupsQuery->paginate($perPage, ['*'], 'groups_page', $page);

                $results['groups']['data'] = $groups->map(static fn($group) => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'slug' => $group->slug,
                    'description' => $group->description,
                    'image' => $group->image ? asset('storage/' . $group->image) : null,
                    'is_private' => $group->is_private,
                    'row_order' => $group->row_order,
                    'is_active' => $group->is_active,
                    'questions_count' => $group->questions()->where('is_private', false)->count(),
                    'created_at' => $group->created_at,
                    'updated_at' => $group->updated_at,
                ]);

                $results['groups']['current_page'] = $groups->currentPage();
                $results['groups']['per_page'] = $groups->perPage();
                $results['groups']['total'] = $groups->total();
                $results['groups']['last_page'] = $groups->lastPage();
                $results['groups']['from'] = $groups->firstItem();
                $results['groups']['to'] = $groups->lastItem();
            }

            return ApiResponseService::successResponse('Search completed successfully', $results);
        } catch (\Exception $e) {
            ApiResponseService::logErrorResponse($e, 'API Controller -> search method');
            return ApiResponseService::errorResponse();
        }
    }
}
