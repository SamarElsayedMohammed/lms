<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class WebinarMediaController extends AdminCrudApiController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Upload Image
     * POST /api/admin/webinars/upload-image
     */
    public function upload(Request $request): JsonResponse
    {
        $this->ensureAdmin();

        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return $this->jsonError($validator->errors()->first(), 422);
        }

        try {
            $path = $request->file('image')->store('webinars', 'public');
            
            // Or if using S3
            // $path = $request->file('image')->store('webinars', 's3');
            // $url = Storage::disk('s3')->url($path);

            $url = Storage::disk('public')->url($path);

            return $this->jsonSuccess('Image uploaded successfully', [
                'url' => $url,
                'path' => $path
            ]);
        } catch (\Throwable $e) {
            return $this->jsonError('Failed to upload image: ' . $e->getMessage());
        }
    }
}
