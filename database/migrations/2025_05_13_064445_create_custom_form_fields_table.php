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
        Schema::create('custom_form_fields', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128)->unique();
            $table->enum('type', ['text', 'textarea', 'number', 'dropdown', 'checkbox', 'radio', 'file', 'email']);
            $table->boolean('is_required')->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('custom_form_field_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_form_field_id')->references('id')->on('custom_form_fields')->onDelete('cascade');
            $table->string('option');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('custom_form_field_options');
        Schema::dropIfExists('custom_form_fields');
        Schema::enableForeignKeyConstraints();
    }
};
