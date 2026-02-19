<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FaqController extends Controller
{
    public function index()
    {
        ResponseService::noAnyPermissionThenRedirect(['faqs-list', 'faqs-create', 'faqs-edit', 'faqs-delete']);
        return view('faq.index', ['type_menu' => 'faqs']);
    }

    public function store(Request $request)
    {
        ResponseService::noPermissionThenRedirect(['faqs-create']);

        $validator = Validator::make($request->all(), [
            'question' => 'required|string|min:2',
            'answer' => 'required|string|min:2',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();

            $data = $validator->validated();
            $data['is_active'] = $request->is_active ?? 0;
            Faq::create($data);

            DB::commit();
            ResponseService::successResponse('FAQ Created Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'FaqController -> Store Method');
            ResponseService::errorResponse();
        }
    }

    public function show(Request $request)
    {
        ResponseService::noAnyPermissionThenRedirect(['faqs-list']);

        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $search = request('search');
        $showDeleted = request('show_deleted');

        $sql = Faq::query()->when(!empty($search), static function ($query) use ($search): void {
            $query->where(static function ($q) use ($search): void {
                $q->where('question', 'LIKE', "%$search%")->orWhere('answer', 'LIKE', "%$search%");
            });
        })->when(!empty($showDeleted), static function ($query): void {
            $query->onlyTrashed();
        });

        $total = $sql->count();

        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = [];
        $bulkData['total'] = $total;
        $rows = [];
        $no = 1;

        foreach ($res as $row) {
            $operate = '';
            if ($showDeleted) {
                if (auth()->user()->can('faqs-restore')) {
                    $operate .= BootstrapTableService::restoreButton(route('faqs.restore', $row->id));
                }
                if (auth()->user()->can('faqs-trash')) {
                    $operate .= BootstrapTableService::trashButton(route('faqs.trash', $row->id));
                }
            } else {
                if (auth()->user()->can('faqs-edit')) {
                    $operate .= BootstrapTableService::editButton(
                        route('faqs.update', $row->id),
                        true,
                        '#faqEditModal',
                        $row->id,
                    );
                }
                if (auth()->user()->can('faqs-delete')) {
                    $operate .= BootstrapTableService::deleteButton(route('faqs.destroy', $row->id));
                }
            }

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            // Add export column for is_active
            $tempRow['is_active_export'] =
                $tempRow['is_active'] == 1
                || $tempRow['is_active'] === 1
                || $tempRow['is_active'] === '1'
                || $tempRow['is_active'] === true
                    ? 'Active'
                    : 'Deactive';
            $tempRow['operate'] = $operate;

            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function update(Request $request, $id)
    {
        ResponseService::noPermissionThenRedirect('faqs-edit');

        $validator = Validator::make($request->all(), [
            'question' => 'required|string|min:2',
            'answer' => 'required|string|min:2',
        ]);

        if ($validator->fails()) {
            return ResponseService::validationError($validator->errors()->first());
        }

        try {
            DB::beginTransaction();
            $faq = Faq::findOrFail($id);
            $data = $validator->validated();
            // is_active is not updated from edit form - keep existing value
            $faq->update($data);
            DB::commit();
            ResponseService::successResponse('FAQ Updated Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'FaqController -> Update Method');
            ResponseService::errorResponse();
        }
    }

    public function destroy($id)
    {
        ResponseService::noPermissionThenRedirect(['faqs-delete']);
        try {
            DB::beginTransaction();
            Faq::findOrFail($id)->delete();
            DB::commit();
            ResponseService::successResponse('FAQ Deleted Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'FaqController -> Destroy Method');
            ResponseService::errorResponse();
        }
    }

    public function restore($id)
    {
        ResponseService::noAnyPermissionThenRedirect(['faqs-edit']);
        try {
            DB::beginTransaction();

            $faq = Faq::onlyTrashed()->findOrFail($id);
            $faq->restore();
            DB::commit();
            ResponseService::successResponse('FAQ Restored Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'FaqController -> Restore Method');
            ResponseService::errorResponse();
        }
    }

    public function trash($id)
    {
        ResponseService::noAnyPermissionThenRedirect(['faqs-delete']);
        try {
            DB::beginTransaction();
            $faq = Faq::onlyTrashed()->findOrFail($id);
            $faq->forceDelete();
            DB::commit();
            ResponseService::successResponse('FAQ Deleted Successfully');
        } catch (Exception $th) {
            DB::rollBack();
            ResponseService::logErrorRedirect($th, 'FaqController -> Trash Method');
            ResponseService::errorResponse();
        }
    }
}
