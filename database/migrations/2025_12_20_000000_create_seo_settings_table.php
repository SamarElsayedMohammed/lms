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
        Schema::create('seo_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained('languages')->onDelete('cascade');
            $table->enum('page_type', [
                'home',
                'courses',
                'instructor',
                'help_and_support',
                'all_categories',
                'search_page',
                'contact_us'
            ]);
            $table->string('meta_title');
            $table->text('meta_description');
            $table->text('meta_keywords');
            $table->text('schema_markup');
            $table->string('og_image');
            $table->timestamps();
            $table->softDeletes();
            
            // Unique constraint: same language + same page type cannot be duplicated
            $table->unique(['language_id', 'page_type'], 'seo_settings_language_page_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seo_settings');
    }
};

