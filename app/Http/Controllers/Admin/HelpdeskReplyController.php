<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HelpdeskQuestion;
use App\Models\HelpdeskReply;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class HelpdeskReplyController extends Controller
{
    public function index(Request $request)
    {
        // If it's an AJAX request, return JSON data for the table
        if ($request->ajax() || $request->wantsJson()) {
            ResponseService::noPermissionThenSendJson('helpdesk-replies-list');
            $query = HelpdeskReply::with(['question', 'user', 'parent']);

            // Apply filters
            if ($request->filled('question_id')) {
                $query->where('question_id', $request->question_id);
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            if ($request->filled('parent_id')) {
                $query->where('parent_id', $request->parent_id);
            }

            if ($request->filled('search')) {
                $search = trim($request->search);
                if (!empty($search)) {
                    $query->where(static function ($q) use ($search): void {
                        $q
                            ->where('reply', 'like', "%{$search}%")
                            ->orWhereHas('user', static function ($userQuery) use ($search): void {
                                $userQuery->where('name', 'like', "%{$search}%")->orWhere(
                                    'email',
                                    'like',
                                    "%{$search}%",
                                );
                            })
                            ->orWhereHas('question', static function ($questionQuery) use ($search): void {
                                $questionQuery->where('title', 'like', "%{$search}%");
                            });
                    });
                }
            }

            $total = $query->count();
            $offset = $request->get('offset', 0);
            $limit = $request->get('limit', 10);

            $result = $query->skip($offset)->take($limit)->get();

            $rows = [];
            $no = 1;
            foreach ($result as $row) {
                $operate = '';
                if (auth()->user()->can('helpdesk-replies-list')) {
                    $operate .= BootstrapTableService::button(
                        'fa fa-eye',
                        route('admin.helpdesk.replies.show', $row->id),
                        ['btn-info', 'view-reply'],
                        ['title' => 'View'],
                    );
                }
                if (auth()->user()->can('helpdesk-replies-edit')) {
                    $operate .= BootstrapTableService::button(
                        'fa fa-edit',
                        route('admin.helpdesk.replies.edit', $row->id),
                        ['btn-primary', 'edit-reply'],
                        ['title' => 'Edit'],
                    );
                }
                if (auth()->user()->can('helpdesk-replies-delete')) {
                    $operate .= BootstrapTableService::button(
                        'fas fa-trash',
                        '#',
                        ['btn-danger', 'delete-reply'],
                        [
                            'title' => 'Delete',
                            'data-id' => $row->id,
                        ],
                    );
                }

                $tempRow = [
                    'id' => (string) $row->id,
                    'reply' => \Illuminate\Support\Str::limit($row->reply, 100),
                    'question_title' => (string) ($row->question->title ?? 'N/A'),
                    'user_name' => (string) ($row->user->name ?? 'N/A'),
                    'parent_reply' => $row->parent ? \Illuminate\Support\Str::limit($row->parent->reply, 30) : 'N/A',
                    'created_at' => (string) $row->created_at,
                    'no' => (int) $no++,
                    'operate' => $operate,
                ];

                $rows[] = $tempRow;
            }

            return response()->json([
                'total' => $total,
                'rows' => $rows,
            ]);
        }

        // For regular GET requests, return the view
        ResponseService::noAnyPermissionThenRedirect([
            'helpdesk-replies-list',
            'helpdesk-replies-edit',
            'helpdesk-replies-delete',
        ]);
        return view('admin.helpdesk.replies.index', ['type_menu' => 'help-desk']);
    }

    public function show($id)
    {
        $reply = HelpdeskReply::with(['question', 'user', 'parent', 'children.user'])->findOrFail($id);
        return view('admin.helpdesk.replies.show', compact('reply'), ['type_menu' => 'help-desk']);
    }

    public function edit($id)
    {
        ResponseService::noPermissionThenRedirect('helpdesk-replies-edit');
        $reply = HelpdeskReply::with(['question', 'user', 'parent'])->findOrFail($id);
        $questions = HelpdeskQuestion::with('group')->get();
        return view('admin.helpdesk.replies.edit', compact('reply', 'questions'), ['type_menu' => 'help-desk']);
    }

    public function update(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson('helpdesk-replies-edit');
        $validator = Validator::make($request->all(), [
            'reply' => 'required|string',
            'question_id' => 'required|exists:helpdesk_questions,id',
            'parent_id' => 'nullable|exists:helpdesk_replies,id',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $reply = HelpdeskReply::findOrFail($id);
            $reply->update($validator->validated());

            return ResponseService::successResponse('Reply updated successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, 'HelpdeskReplyController -> update()');
            return ResponseService::errorResponse();
        }
    }

    public function destroy($id)
    {
        ResponseService::noPermissionThenSendJson('helpdesk-replies-delete');
        try {
            $reply = HelpdeskReply::findOrFail($id);
            $reply->delete();

            return response()->json([
                'success' => true,
                'error' => false,
                'message' => 'Reply deleted successfully',
            ]);
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, 'HelpdeskReplyController -> destroy()');
            return response()->json([
                'success' => false,
                'error' => true,
                'message' => 'Failed to delete reply: ' . $th->getMessage(),
            ], 500);
        }
    }

    public function getDashboardData()
    {
        $totalReplies = HelpdeskReply::count();
        $recentReplies = HelpdeskReply::with(['user', 'question'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'total_replies' => $totalReplies,
            'recent_replies' => $recentReplies,
        ]);
    }
}
