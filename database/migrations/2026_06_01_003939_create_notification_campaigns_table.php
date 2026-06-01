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
        Schema::create('notification_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('message');
            $table->string('target_type'); // all, free_users, any_plan, by_plan, students, instructors
            $table->foreignId('plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->integer('sent_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_campaigns');
    }
};
