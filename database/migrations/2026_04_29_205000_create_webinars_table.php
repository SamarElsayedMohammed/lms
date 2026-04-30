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
        Schema::create('webinars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained('users')->onDelete('cascade');
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->timestamp('start_at');
            $table->integer('duration')->comment('Duration in minutes');
            $table->string('meeting_id')->nullable();
            $table->string('meeting_password')->nullable();
            $table->string('join_url')->nullable();
            $table->enum('provider', ['zoom', 'jitsi', 'custom'])->default('jitsi');
            $table->enum('status', ['scheduled', 'live', 'completed', 'cancelled'])->default('scheduled');
            $table->boolean('is_free')->default(true);
            $table->decimal('price', 15, 2)->default(0.00);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('webinar_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('webinar_id')->constrained()->onDelete('cascade');
            $table->enum('payment_status', ['pending', 'paid', 'free'])->default('free');
            $table->decimal('paid_amount', 15, 2)->default(0.00);
            $table->timestamps();
            $table->unique(['user_id', 'webinar_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webinar_registrations');
        Schema::dropIfExists('webinars');
    }
};
