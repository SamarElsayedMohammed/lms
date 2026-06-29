<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ApiResponseService;
use App\Services\FirebaseConfigService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;

final class FirebaseConfigApiController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private readonly FirebaseConfigService $firebaseConfigService,
    ) {}

    public function show(): JsonResponse
    {
        $configured = $this->firebaseConfigService->isClientConfigComplete();
        $config = $this->firebaseConfigService->getClientConfig();

        return $this->ok(
            data: [
                'configured' => $configured,
                'config' => empty($config) ? null : $config,
                'missing_keys' => $this->firebaseConfigService->getMissingClientKeys(),
            ],
            message: 'Firebase configuration retrieved',
        );
    }
}
