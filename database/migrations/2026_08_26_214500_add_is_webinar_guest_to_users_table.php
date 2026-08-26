<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'is_webinar_guest')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_webinar_guest')->default(false);
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'is_webinar_guest')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_webinar_guest');
        });
    }
};
