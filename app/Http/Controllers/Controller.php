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

            // Table-to-permission mapping for status changes
            $tablePermissions = [
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
            ];

            // Check if this table requires permission and validate
            if (isset($tablePermissions[$request->table])) {
                ResponseService::noPermissionThenSendJson($tablePermissions[$request->table]);
            }

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
        } catch (Throwable $th) {
            ResponseService::logErrorRedirect($th);
            ResponseService::errorResponse();
        }
    }

    public function readLanguageFile()
    {
        try {
            header('Content-Type: text/javascript');

            $lang = Session::get('language');

            $test = $lang->code ?? 'en';
            $files = resource_path('lang/' . $test . '.json');

            echo 'window.languageLabels = ' . File::get($files);

            exit();
        } catch (Throwable $th) {
            ResponseService::errorResponse($th);
        }
    }
}
