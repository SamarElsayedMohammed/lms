<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a `token_type` discriminator column to `personal_access_tokens`.
 *
 * This allows the backend to differentiate between:
 *   - 'access'  — short-lived token used in Authorization header for API calls
 *   - 'refresh' — long-lived token used ONLY to obtain a new token pair
 *
 * The `expires_at` column (already present) is used to enforce different
 * lifespans: access tokens expire quickly (60 min), refresh tokens live
 * much longer (30 days by default, configurable in .env).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // 'access' | 'refresh' — null = legacy tokens (treated as access)
            $table->string('token_type', 10)->nullable()->default('access')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('token_type');
        });
    }
};
