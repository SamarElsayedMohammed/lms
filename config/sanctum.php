<?php

use Laravel\Sanctum\Sanctum;

return [
    /*
     |--------------------------------------------------------------------------
     | Stateful Domains
     |--------------------------------------------------------------------------
     |
     | Requests from the following domains / hosts will receive stateful API
     | authentication cookies. Typically, these should include your local
     | and production domains which access your API via a frontend SPA.
     |
     */

    'stateful' => explode(
        ',',
        (string) env('SANCTUM_STATEFUL_DOMAINS', sprintf(
            '%s%s',
            'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
            Sanctum::currentApplicationUrlWithPort(),
        )),
    ),
    /*
     |--------------------------------------------------------------------------
     | Sanctum Guards
     |--------------------------------------------------------------------------
     |
     | This array contains the authentication guards that will be checked when
     | Sanctum is trying to authenticate a request. If none of these guards
     | are able to authenticate the request, Sanctum will use the bearer
     | token that's present on an incoming request for authentication.
     |
     */

    'guard' => ['web'],
    /*
     |--------------------------------------------------------------------------
     | Expiration Minutes
     |--------------------------------------------------------------------------
     |
     | This value controls the number of minutes until an issued token will be
     | considered expired. This will override any values set in the token's
     | "expires_at" attribute, but first-party sessions are not affected.
     |
     */

    // Global fallback expiration (null = never expire via Sanctum's built-in check).
    // We manage expiration ourselves via the expires_at column on individual tokens.
    'expiration' => null,

    /*
     |--------------------------------------------------------------------------
     | Token Lifetimes (in minutes)
     |--------------------------------------------------------------------------
     |
     | These values are used by our dual-token auth system.
     |
     | ACCESS_TOKEN_LIFETIME  — short-lived; used for every API request
     | REFRESH_TOKEN_LIFETIME — long-lived; used only to obtain a new token pair
     |
     | Defaults: 60 min access, 43 200 min (30 days) refresh.
     | Override in .env: SANCTUM_ACCESS_TOKEN_LIFETIME / SANCTUM_REFRESH_TOKEN_LIFETIME
     |
     */
    'access_token_lifetime'  => (int) env('SANCTUM_ACCESS_TOKEN_LIFETIME',  60),       // minutes
    'refresh_token_lifetime' => (int) env('SANCTUM_REFRESH_TOKEN_LIFETIME', 43200),    // minutes (30 days)
    /*
     |--------------------------------------------------------------------------
     | Token Prefix
     |--------------------------------------------------------------------------
     |
     | Sanctum can prefix new tokens in order to take advantage of numerous
     | security scanning initiatives maintained by open source platforms
     | that notify developers if they commit tokens into repositories.
     |
     | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
     |
     */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),
    /*
     |--------------------------------------------------------------------------
     | Sanctum Middleware
     |--------------------------------------------------------------------------
     |
     | When authenticating your first-party SPA with Sanctum you may need to
     | customize some of the middleware Sanctum uses while processing the
     | request. You may change the middleware listed below as required.
     |
     */

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
