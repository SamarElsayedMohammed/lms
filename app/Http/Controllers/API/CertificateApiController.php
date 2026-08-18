<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CertificateController;
use Illuminate\Http\Request;

class CertificateApiController extends Controller
{
    /**
     * Public endpoint to verify a certificate by code
     */
    public function verifyPublic(Request $request)
    {
        return app(CertificateController::class)->verifyApi($request);
    }
}
