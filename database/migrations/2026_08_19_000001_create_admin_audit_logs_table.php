<?php

declare(strict_types=1);

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
        if (!Schema::hasTable('admin_audit_logs')) {
            Schema::create('admin_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('actor_name', 255)->nullable();
                $table->string('actor_email', 255)->nullable();
                $table->string('action', 100)->index();
                $table->string('target_type', 100)->nullable();
                $table->unsignedBigInteger('target_id')->nullable();
                $table->text('summary')->nullable();
                $table->json('details')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();

                $table->index(['target_type', 'target_id'], 'admin_audit_logs_target_idx');
                $table->index(['user_id', 'created_at'], 'admin_audit_logs_user_date_idx');
                $table->index(['action', 'created_at'], 'admin_audit_logs_action_date_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
    }
};
