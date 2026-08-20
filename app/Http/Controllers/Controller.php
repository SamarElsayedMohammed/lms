<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use App\Services\ResponseService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Session;
use Throwable;

/*Create Method which are common across the system*/

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    public function changeRowOrder(Request $request)
    {
        try {
            $request->validate([
                'data' => 'required|array',
                'table' => 'required|string',
                'column' => 'nullable',
            ]);

            $allowedTables = $this->mutableStatusTables();
            if (!isset($allowedTables[$request->table])) {
                ResponseService::errorResponse('الجدول غير مسموح.', null, 403);
            }

            ResponseService::noPermissionThenSendJson($allowedTables[$request->table]);

            $column = $request->column ?? 'sequence';

            $data = [];
            foreach ($request->data as $index => $row) {
                $data[] = [
                    'id' => $row['id'],
                    (string) $column => $index,
                ];
            }
            DB::table($request->table)->upsert($data, ['id'], [(string) $column]);
            ResponseService::successResponse('Order Changed Successfully');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th);
            ResponseService::errorResponse();
        }
    }

    public function changeStatus(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required|numeric',
                'status' => 'required|boolean',
                'table' => 'required|string',
                'column' => 'nullable',
            ]);

            $tablePermissions = $this->mutableStatusTables();

            if (!isset($tablePermissions[$request->table])) {
                ResponseService::errorResponse('الجدول غير مسموح.', null, 403);
            }

            ResponseService::noPermissionThenSendJson($tablePermissions[$request->table]);

            $column = $request->column ?? 'status';

            //Special case for deleted_at column
            if ($column == 'deleted_at') {
                //If status is active then deleted_At will be empty otherwise it will have the current time
                $request->status = $request->status ? null : now();
            }
            DB::table($request->table)->where('id', $request->id)->update([(string) $column => $request->status]);
            if ($request->table === 'items') {
                $item = DB::table('items')->where('id', $request->id)->first();
                if ($item) {
                    $user = DB::table('users')->where('id', $item->user_id)->first();
                    if ($user) {
                        $userToken = DB::table('user_fcm_tokens')
                            ->where('user_id', $user->id)
                            ->pluck('fcm_token')
                            ->toArray();

                        if (!empty($userToken)) {
                            NotificationService::sendFcmNotification(
                                $userToken,
                                'About ' . $item->name,
                                'Your Advertisement is '
                                . (is_null($request->status) ? 'Active' : 'Inactive')
                                . ' by Admin',
                                'item-update',
                                ['id' => $request->id],
                            );
                        }
                    }
                }
            }
            ResponseService::successResponse('Status Updated Successfully');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th);
            ResponseService::errorResponse();
        }
    }

    public function readLanguageFile()
    {
        try {
            $lang = Session::get('language');
            $code = 'en';
            if (is_object($lang) && isset($lang->code) && is_string($lang->code) && $lang->code !== '') {
                $code = $lang->code;
            } elseif (is_string($lang) && $lang !== '') {
                $code = $lang;
            }

            $code = preg_replace('/[^a-zA-Z0-9_\-]/', '', $code) ?: 'en';
            $file = resource_path('lang/' . $code . '.json');
            if (!File::isReadable($file)) {
                $file = resource_path('lang/en.json');
            }

            $labels = File::isReadable($file) ? File::get($file) : '{}';

            return response('window.languageLabels = ' . $labels, 200, [
                'Content-Type' => 'text/javascript; charset=UTF-8',
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (Throwable $th) {
            ResponseService::errorResponse();
        }
    }

    /**
     * Tables allowed for generic status/order mutations. Unknown tables must 403.
     *
     * @return array<string, string>
     */
    private function mutableStatusTables(): array
    {
        return [
            'categories' => 'categories-edit',
            'users' => 'users-edit',
            'courses' => 'courses-edit',
            'sliders' => 'sliders-edit',
            'faqs' => 'faqs-edit',
            'pages' => 'pages-edit',
            'taxes' => 'taxes-edit',
            'promo_codes' => 'promo-codes-edit',
            'feature_sections' => 'feature-sections-edit',
            'course_languages' => 'course-languages-edit',
            'course_tags' => 'course-tags-edit',
            'notifications' => 'notifications-edit',
            'course_chapters' => 'courses-edit',
            'course_chapter_lectures' => 'courses-edit',
            'course_chapter_quizzes' => 'courses-edit',
            'course_chapter_assignments' => 'courses-edit',
            'course_chapter_resources' => 'courses-edit',
        ];
    }

    public function serveStorage(string $path)
    {
        $path = str_replace(['../', '..\\'], '', $path);
        if ($path === '' || !\Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
            abort(404);
        }
        $fullPath = \Illuminate\Support\Facades\Storage::disk('public')->path($path);
        $mimeType = \Illuminate\Support\Facades\File::mimeType($fullPath);
        return response()->file($fullPath, ['Content-Type' => $mimeType]);
    }
}
