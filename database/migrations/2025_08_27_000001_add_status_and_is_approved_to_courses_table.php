<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (!Schema::hasColumn('courses', 'status')) {
                $table->enum('status', ['draft','pending','publish'])->default('draft')->after('course_type');
            }
            if (!Schema::hasColumn('courses', 'is_approved')) {
                $table->boolean('is_approved')->default(false)->after('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (Schema::hasColumn('courses', 'is_approved')) {
                $table->dropColumn('is_approved');
            }
            if (Schema::hasColumn('courses', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};


