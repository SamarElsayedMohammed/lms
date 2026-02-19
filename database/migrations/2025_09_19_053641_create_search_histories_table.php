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
        Schema::create('search_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('query');
            $table->string('ip_address', 45)->nullable();
            $table->integer('search_count')->default(1);
            $table->timestamp('last_searched_at');
            $table->timestamps();
            
            $table->index(['user_id', 'last_searched_at']);
            $table->index(['query', 'last_searched_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_histories');
    }
};
