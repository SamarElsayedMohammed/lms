<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('languages', static function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('name_in_english', 32); // 🔁 Fixed line
            $table->string('slug', 512)->nullable();
            $table->string('app_file');
            $table->string('panel_file');
            $table->string('web_file')->nullable();
            $table->boolean('rtl');
            $table->string('image', 512)->nullable();
            $table->string('country_code')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
