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
        Schema::table('webinars', function (Blueprint $table) {
            if (!Schema::hasColumn('webinars', 'is_published')) {
                $table->boolean('is_published')->default(true)->after('status');
            }
            if (!Schema::hasColumn('webinars', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->after('is_published');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webinars', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('webinars', 'is_published')) {
                $columnsToDrop[] = 'is_published';
            }
            if (Schema::hasColumn('webinars', 'is_featured')) {
                $columnsToDrop[] = 'is_featured';
            }
            
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
