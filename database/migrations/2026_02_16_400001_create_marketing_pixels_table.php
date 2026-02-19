<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketing_pixels', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 50)->unique();
            $table->string('pixel_id')->default('');
            $table->boolean('is_active')->default(false);
            $table->json('additional_config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_pixels');
    }
};
