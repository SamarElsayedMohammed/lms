<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Models\Instructor;
use App\Models\InstructorPersonalDetail;
use App\Models\User;
use App\Services\HelperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class InstructorAdminApiController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('instructors-list');

        $query = User::whereHas('instructor_details')
            ->with(['instructor_details.personal_details', 'instructor_details.social_medias'])
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%");
            }))
            ->when($request->status, fn ($q) => $q->whereHas('instructor_details', fn ($iq) => $iq->where('status', $request->status)));

        $perPage = min((int) $request->input('per_page', 15), 100);
        $instructors = $query->orderBy('id', 'desc')->paginate($perPage);

        return $this->jsonSuccess(__('Instructors retrieved'), $instructors);
    }

    public function show(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('instructors-list');

        $user = User::with(['instructor_details.personal_details', 'instructor_details.social_medias', 'instructor_details.other_details', 'courses'])
            ->whereHas('instructor_details')
            ->find($id);

        if (!$user) {
            return $this->jsonError(__('Instructor not found'), 404);
        }

        return $this->jsonSuccess(__('Instructor retrieved'), $user);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('instructors-create');

        $validator = Validator::make($request->all(), [
            'name'                  => 'required|string|max:255',
            'email'                 => 'required|email|unique:users,email',
            'password'              => 'required|string|min:8',
            'mobile'                => 'nullable|string|max:20',
            'type'                  => 'required|in:individual,team',
            'status'                => 'nullable|in:pending,approved',
            'qualification'         => 'nullable|string',
            'years_of_experience'   => 'nullable|numeric|min:0|max:100',
            'skills'                => 'nullable|string',
            'about_me'              => 'nullable|string',
            'team_name'             => 'nullable|required_if:type,team|string',
            'bank_account_number'   => 'nullable|string',
            'bank_name'             => 'nullable|string',
            'bank_account_holder_name' => 'nullable|string',
            'bank_ifsc_code'        => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();

            $name = $request->input('name');
            $slug = HelperService::generateUniqueSlug(User::class, $name);

            // 1. Create User
            $user = User::create([
                'name'      => $name,
                'slug'      => $slug,
                'email'     => $request->input('email'),
                'password'  => Hash::make($request->input('password')),
                'mobile'    => $request->input('mobile'),
                'is_active' => 1,
            ]);

            // 2. Assign Role
            $user->assignRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'));

            // 3. Create Instructor record
            $instructor = Instructor::create([
                'user_id' => $user->id,
                'type'    => $request->input('type'),
                'status'  => $request->input('status', 'approved'),
            ]);

            // 4. Create Personal Details
            InstructorPersonalDetail::create([
                'instructor_id'             => $instructor->id,
                'qualification'             => $request->input('qualification'),
                'years_of_experience'       => $request->input('years_of_experience'),
                'skills'                    => $request->input('skills'),
                'about_me'                  => $request->input('about_me'),
                'team_name'                 => $request->input('team_name'),
                'bank_account_number'       => $request->input('bank_account_number'),
                'bank_name'                 => $request->input('bank_name'),
                'bank_account_holder_name'  => $request->input('bank_account_holder_name'),
                'bank_ifsc_code'            => $request->input('bank_ifsc_code'),
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->jsonError(__('Failed to create instructor: ') . $e->getMessage(), 500);
        }

        return $this->jsonSuccess(__('Instructor created successfully'), $instructor->load(['user', 'personal_details']), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('instructors-edit');

        $user = User::whereHas('instructor_details')->find($id);
        if (!$user) {
            return $this->jsonError(__('Instructor not found'), 404);
        }

        $instructor = $user->instructor_details;

        $validator = Validator::make($request->all(), [
            'name'                  => 'sometimes|string|max:255',
            'email'                 => 'sometimes|email|unique:users,email,'.$user->id,
            'password'              => 'nullable|string|min:8',
            'mobile'                => 'nullable|string|max:20',
            'type'                  => 'sometimes|in:individual,team',
            'status'                => 'sometimes|in:pending,approved,suspended',
            'qualification'         => 'nullable|string',
            'years_of_experience'   => 'nullable|numeric|min:0|max:100',
            'skills'                => 'nullable|string',
            'about_me'              => 'nullable|string',
            'team_name'                 => 'nullable|string',
            'bank_account_number'       => 'nullable|string',
            'bank_name'                 => 'nullable|string',
            'bank_account_holder_name'  => 'nullable|string',
            'bank_ifsc_code'            => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();

            // Update User
            $userData = $request->only(['name', 'email', 'mobile', 'is_active']);
            if ($request->filled('name') && $request->input('name') !== $user->name) {
                $userData['slug'] = HelperService::generateUniqueSlug(User::class, $request->input('name'), $user->id);
            }
            if ($request->filled('password')) {
                $userData['password'] = Hash::make($request->input('password'));
            }
            $user->update($userData);

            // Update Instructor
            $instructor->update($request->only(['type', 'status']));

            // Update Personal Details
            $instructor->personal_details()->updateOrCreate(
                ['instructor_id' => $instructor->id],
                $request->only([
                    'qualification', 'years_of_experience', 'skills', 'about_me', 'team_name',
                    'bank_account_number', 'bank_name', 'bank_account_holder_name', 'bank_ifsc_code'
                ])
            );

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->jsonError(__('Failed to update instructor: ') . $e->getMessage(), 500);
        }

        return $this->jsonSuccess(__('Instructor updated successfully'), $instructor->fresh(['user', 'personal_details']));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('instructors-delete');

        $instructor = Instructor::where('user_id', $id)->first();
        if (!$instructor) {
            return $this->jsonError(__('Instructor not found'), 404);
        }

        $instructor->delete(); // Soft delete instructor record
        return $this->jsonSuccess(__('Instructor deleted successfully'));
    }

    public function suspend(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('instructors-edit');

        $instructor = Instructor::where('user_id', $id)->first();
        if (!$instructor) {
            return $this->jsonError(__('Instructor not found'), 404);
        }

        $instructor->update([
            'status' => 'suspended',
            'reason' => $request->input('reason', __('Administrative suspension'))
        ]);

        return $this->jsonSuccess(__('Instructor suspended'), $instructor->fresh(['user']));
    }

    public function restore(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('instructors-edit');

        $instructor = Instructor::onlyTrashed()->where('user_id', $id)->first();
        if (!$instructor) {
            return $this->jsonError(__('Deleted instructor not found'), 404);
        }

        $instructor->restore();
        return $this->jsonSuccess(__('Instructor restored'), $instructor->fresh(['user']));
    }

    public function approve(int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('instructors-edit');

        $instructor = Instructor::where('user_id', $id)->first();
        if (!$instructor) {
            return $this->jsonError(__('Instructor not found'), 404);
        }

        $instructor->update(['status' => 'approved']);
        $instructor->user->assignRole(config('constants.SYSTEM_ROLES.INSTRUCTOR'));

        return $this->jsonSuccess(__('Instructor approved'), $instructor->fresh(['user']));
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin();
        $this->checkPermission('instructors-edit');

        $instructor = Instructor::where('user_id', $id)->first();
        if (!$instructor) {
            return $this->jsonError(__('Instructor not found'), 404);
        }

        $instructor->update([
            'status' => 'rejected',
            'reason' => $request->input('reason')
        ]);

        return $this->jsonSuccess(__('Instructor rejected'), $instructor->fresh(['user']));
    }
}
