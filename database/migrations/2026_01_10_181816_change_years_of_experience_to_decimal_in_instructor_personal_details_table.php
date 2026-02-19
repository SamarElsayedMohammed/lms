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
        Schema::table('instructor_personal_details', function (Blueprint $table) {
            $table->decimal('years_of_experience', 5, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instructor_personal_details', function (Blueprint $table) {
            $table->integer('years_of_experience')->nullable()->change();
        });
    }
};
