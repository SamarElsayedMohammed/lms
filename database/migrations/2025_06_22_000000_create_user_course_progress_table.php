<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_course_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('course_id')->constrained()->onDelete('cascade');
            $table->integer('completed_items')->default(0);
            $table->integer('total_items')->default(0);
            $table->decimal('progress_percentage', 5, 2)->default(0.00);
            $table->timestamp('last_accessed_at')->nullable();
            $table->enum('status', ['not_started', 'started', 'in_progress', 'completed'])->default('not_started');
            $table->timestamps();
            
            $table->unique(['user_id', 'course_id']);
            $table->index(['user_id', 'status']);
            $table->index(['course_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_course_progress');
    }
};
