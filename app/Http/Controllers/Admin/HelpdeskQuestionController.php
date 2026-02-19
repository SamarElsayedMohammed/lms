<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HelpdeskGroup;
use App\Models\HelpdeskQuestion;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class HelpdeskQuestionController extends Controller
{
    public function index(Request $request)
    {
        // If it's an AJAX request, return JSON data for the table
        if ($request->ajax() || $request->wantsJson()) {
            ResponseService::noPermissionThenSendJson('helpdesk-questions-list');
            $showDeleted = $request->input('show_deleted');

            $query = HelpdeskQuestion::with(['group', 'user', 'replies'])->when($showDeleted == 1
            || $showDeleted === '1', static function ($query): void {
                $query->onlyTrashed();
            });

            // Apply filters
            if ($request->filled('group_id')) {
                $query->where('group_id', $request->group_id);
            }

            if ($request->filled('is_private')) {
                $query->where('is_private', $request->is_private);
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            if ($request->filled('search')) {
                $search = trim($request->search);
                if (!empty($search)) {
                    $query->where(static function ($q) use ($search): void {
                        $q
                            ->where('title', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%")
                            ->orWhereHas('user', static function ($userQuery) use ($search): void {
                                $userQuery->where('name', 'like', "%{$search}%")->orWhere(
                                    'email',
                                    'like',
                                    "%{$search}%",
                                );
                            })
                            ->orWhereHas('group', static function ($groupQuery) use ($search): void {
                                $groupQuery->where('name', 'like', "%{$search}%");
                            });
                    });
                }
            }

            // Get sort parameters
            $sort = $request->get('sort', 'id');
            $order = $request->get('order', 'DESC');

            // Apply sorting
            $query->orderBy($sort, $order);

            $total = $query->count();
            $offset = $request->get('offset', 0);
            $limit = $request->get('limit', 10);

            $result = $query->skip($offset)->take($limit)->get();

            $rows = [];
            $no = $offset + 1;
            foreach ($result as $row) {
                // Generate action buttons based on show_deleted
                if ($showDeleted == 1 || $showDeleted === '1') {
                    // Trashed items - show restore and permanent delete
                    $operate = '';
                    if (auth()->user()->can('helpdesk-questions-edit')) {
                        $operate .= BootstrapTableService::restoreButton(route(
                            'admin.helpdesk.questions.restore',
                            $row->id,
                        ));
                    }
                    if (auth()->user()->can('helpdesk-questions-delete')) {
                        $operate .= BootstrapTableService::trashButton(route(
                            'admin.helpdesk.questions.trash',
                            $row->id,
                        ));
                    }
                } else {
                    // Active items - show view, edit, delete
                    $operate = BootstrapTableService::button(
                        'fa fa-eye',
                        route('admin.helpdesk.questions.show', $row->id),
                        ['btn-info', 'view-question'],
                        ['title' => 'View'],
                    );
                    if (auth()->user()->can('helpdesk-questions-edit')) {
                        $operate .= BootstrapTableService::editButton(
                            route('admin.helpdesk.questions.edit', $row->id),
                            false,
                            null,
                            null,
                            null,
                            'fa fa-edit',
                        );
                    }
                    if (auth()->user()->can('helpdesk-questions-delete')) {
                        $operate .= BootstrapTableService::deleteButton(
                            route('admin.helpdesk.questions.destroy', $row->id),
                            null,
                            $row->id,
                        );
                    }
                }

                $tempRow = [
                    'id' => (string) $row->id,
                    'title' => (string) $row->title,
                    'slug' => (string) $row->slug,
                    'description' => \Illuminate\Support\Str::limit($row->description, 50),
                    'group_name' => (string) ($row->group->name ?? 'N/A'),
                    'user_name' => (string) ($row->user->name ?? 'N/A'),
                    'is_private' => (int) $row->is_private,
                    'replies_count' => (int) $row->replies->count(),
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
            'helpdesk-questions-list',
            'helpdesk-questions-create',
            'helpdesk-questions-edit',
            'helpdesk-questions-delete',
        ]);
        return view('admin.helpdesk.questions.index', ['type_menu' => 'help-desk']);
    }

    public function show($id)
    {
        $question = HelpdeskQuestion::with(['group', 'user', 'replies.user'])->findOrFail($id);
        return view('admin.helpdesk.questions.show', compact('question'), ['type_menu' => 'help-desk']);
    }

    public function showBySlug($slug)
    {
        $question = HelpdeskQuestion::with(['group', 'user', 'replies.user'])->where('slug', $slug)->firstOrFail();
        return view('admin.helpdesk.questions.show', compact('question'), ['type_menu' => 'help-desk']);
    }

    public function edit($id)
    {
        ResponseService::noPermissionThenRedirect('helpdesk-questions-edit');
        $question = HelpdeskQuestion::with(['group', 'user'])->findOrFail($id);
        $groups = HelpdeskGroup::where('is_active', 1)->get();
        return view('admin.helpdesk.questions.edit', compact('question', 'groups'), ['type_menu' => 'help-desk']);
    }

    public function update(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson('helpdesk-questions-edit');
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:helpdesk_questions,slug,' . $id,
            'description' => 'required|string',
            'group_id' => 'required|exists:helpdesk_groups,id',
            'is_private' => 'boolean',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            $question = HelpdeskQuestion::findOrFail($id);
            $question->update($validator->validated());

            return ResponseService::successResponse('Question updated successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, 'HelpdeskQuestionController -> update()');
            return ResponseService::errorResponse();
        }
    }

    public function destroy($id)
    {
        ResponseService::noPermissionThenSendJson('helpdesk-questions-delete');
        try {
            $question = HelpdeskQuestion::findOrFail($id);
            $question->delete();

            return ResponseService::successResponse('Question deleted successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, 'HelpdeskQuestionController -> destroy()');
            return ResponseService::errorResponse();
        }
    }

    public function restore($id)
    {
        ResponseService::noPermissionThenSendJson('helpdesk-questions-edit');
        try {
            $question = HelpdeskQuestion::onlyTrashed()->findOrFail($id);
            $question->restore();

            return ResponseService::successResponse('Question restored successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, 'HelpdeskQuestionController -> restore()');
            return ResponseService::errorResponse('Failed to restore question');
        }
    }

    public function trash($id)
    {
        ResponseService::noPermissionThenSendJson('helpdesk-questions-delete');
        try {
            $question = HelpdeskQuestion::onlyTrashed()->findOrFail($id);
            $question->forceDelete();

            return ResponseService::successResponse('Question permanently deleted successfully');
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th, 'HelpdeskQuestionController -> trash()');
            return ResponseService::errorResponse('Failed to permanently delete question');
        }
    }

    public function getDashboardData()
    {
        $totalQuestions = HelpdeskQuestion::count();
        $privateQuestions = HelpdeskQuestion::where('is_private', 1)->count();
        $publicQuestions = HelpdeskQuestion::where('is_private', 0)->count();
        $recentQuestions = HelpdeskQuestion::with(['user', 'group'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'total_questions' => $totalQuestions,
            'private_questions' => $privateQuestions,
            'public_questions' => $publicQuestions,
            'recent_questions' => $recentQuestions,
        ]);
    }
}
