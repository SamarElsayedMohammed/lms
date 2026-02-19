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
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained('languages')->onDelete('cascade');
            $table->string('title', 500);
            $table->string('page_type', 50);
            $table->string('slug', 500);
            $table->mediumText('page_content')->nullable();
            $table->string('page_icon')->nullable();
            $table->string('og_image')->nullable();
            $table->text('schema_markup')->nullable();
            $table->text('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('meta_keywords')->nullable();
            $table->tinyInteger('is_custom')->default(1)->comment('0-default, 1-custom');
            $table->tinyInteger('is_termspolicy')->default(0)->comment('0-no, 1-yes');
            $table->tinyInteger('is_privacypolicy')->default(0)->comment('0-no, 1-yes');
     
            $table->tinyInteger('status')->default(1)->comment('0-deactive, 1-active');
            $table->timestamps();
            $table->softDeletes();
            
            // Unique constraint for slug per language (same slug can exist in different languages)
            $table->unique(['slug', 'language_id'], 'pages_slug_language_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
