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
        // First, update any 'url' type to 'youtube_url'
        \Illuminate\Support\Facades\DB::statement("UPDATE course_chapter_lectures SET type = 'youtube_url' WHERE type = 'url'");
        
        // Add new youtube_url_temp column
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE course_chapter_lectures ADD COLUMN youtube_url_temp TEXT NULL AFTER `url`");
        
        // Copy data from url to youtube_url_temp
        \Illuminate\Support\Facades\DB::statement("UPDATE course_chapter_lectures SET youtube_url_temp = `url`");
        
        // Drop the old youtube_url column
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE course_chapter_lectures DROP COLUMN youtube_url");
        
        // Rename youtube_url_temp to youtube_url
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE course_chapter_lectures CHANGE youtube_url_temp youtube_url TEXT NULL");
        
        // Drop the old url column
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE course_chapter_lectures DROP COLUMN `url`");
        
        // Update the enum type to only have 'file' and 'youtube_url'
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE course_chapter_lectures MODIFY COLUMN type ENUM('file', 'youtube_url') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore the original enum type first
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE course_chapter_lectures MODIFY COLUMN type ENUM('file', 'url', 'youtube_url') NOT NULL");
        
        // Add back the url column
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE course_chapter_lectures ADD COLUMN `url` TEXT NULL AFTER file_extension");
        
        // Copy data from youtube_url to url
        \Illuminate\Support\Facades\DB::statement("UPDATE course_chapter_lectures SET `url` = youtube_url");
        
        // Rename youtube_url to youtube_url_old
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE course_chapter_lectures CHANGE youtube_url youtube_url_old TEXT NULL");
        
        // Add back the youtube_url column
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE course_chapter_lectures ADD COLUMN youtube_url TEXT NULL AFTER `url`");
        
        // Drop the youtube_url_old column
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE course_chapter_lectures DROP COLUMN youtube_url_old");
    }
};
