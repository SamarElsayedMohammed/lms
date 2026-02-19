<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (Schema::hasColumn('courses', 'is_approved')) {
                $table->dropColumn('is_approved');
            }
        });

        Schema::table('courses', function (Blueprint $table) {
            if (!Schema::hasColumn('courses', 'approval_status')) {
                $table->enum('approval_status', ['approved','rejected'])->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (Schema::hasColumn('courses', 'approval_status')) {
                $table->dropColumn('approval_status');
            }
        });

        Schema::table('courses', function (Blueprint $table) {
            if (!Schema::hasColumn('courses', 'is_approved')) {
                $table->boolean('is_approved')->default(false)->after('status');
            }
        });
    }
};


