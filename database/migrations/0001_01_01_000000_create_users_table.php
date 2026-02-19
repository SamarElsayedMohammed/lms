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
        Schema::create('users', static function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
            $table->string('country_code')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('profile')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(1);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['email', 'mobile']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('social_logins', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('firebase_id', 512);
            $table->enum('type', ['google', 'email', 'phone']);
            $table->timestamps();
            $table->unique(['user_id', 'type']);
        });

        Schema::create('user_fcm_tokens', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->references('id')->on('users')->onDelete('cascade')->onUpdate('cascade');
            $table->enum('platform_type', ['Android', 'iOS'])->nullable();
            $table->string('fcm_token');
            $table->timestamps();
            $table->unique('fcm_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('users');
        Schema::dropIfExists('social_logins');
        Schema::dropIfExists('user_fcm_tokens');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
        Schema::enableForeignKeyConstraints();
    }
};
