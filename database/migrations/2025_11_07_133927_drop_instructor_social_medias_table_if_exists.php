<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('instructor_social_medias')) {
            // Drop foreign key constraints first
            try {
                $foreignKeys = DB::select("
                    SELECT CONSTRAINT_NAME 
                    FROM information_schema.KEY_COLUMN_USAGE 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 'instructor_social_medias' 
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                ");
                
                foreach ($foreignKeys as $fk) {
                    DB::statement("ALTER TABLE instructor_social_medias DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                }
            } catch (\Exception $e) {
                // Continue if foreign keys don't exist
            }
            
            // Drop the table
            Schema::dropIfExists('instructor_social_medias');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate the table if needed (optional - you can leave this empty if you don't want to restore)
        if (!Schema::hasTable('instructor_social_medias')) {
            Schema::create('instructor_social_medias', function (Blueprint $table) {
                $table->id();
                $table->foreignId('instructor_id')->references('id')->on('instructors')->onDelete('cascade');
                $table->foreignId('social_media_id')->nullable()->references('id')->on('social_medias')->onDelete('cascade');
                $table->string('title')->nullable();
                $table->string('url')->nullable();
                $table->unique(['instructor_id', 'title'], 'unique_instructor_social_media_title');
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }
};
