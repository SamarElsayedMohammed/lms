<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ServesApiAccount;
use App\Http\Controllers\Concerns\ServesApiAuth;
use App\Http\Controllers\Concerns\ServesApiPublicContent;
use App\Http\Controllers\Concerns\ServesApiSessions;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    use ServesApiAuth;
    use ServesApiAccount;
    use ServesApiPublicContent;
    use ServesApiSessions;

    public function __construct()
    {
        // Public auth endpoints must stay public even when the client still
        // has a leftover Authorization header or ?token= query.
        // Route groups already apply auth:sanctum where it is required.
    }
}
