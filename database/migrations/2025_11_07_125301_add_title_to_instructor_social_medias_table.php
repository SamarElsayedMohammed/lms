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
        // Check if table exists
        if (!Schema::hasTable('instructor_social_medias')) {
            return;
        }
        
        // Get the actual index name from database
        $indexName = null;
        try {
            $indexes = DB::select("SHOW INDEX FROM instructor_social_medias WHERE Key_name = 'unique_instructor_social_media'");
            if (!empty($indexes)) {
                $indexName = 'unique_instructor_social_media';
            } else {
                // Try to find any unique index on instructor_id and social_media_id
                $indexes = DB::select("SHOW INDEX FROM instructor_social_medias WHERE Column_name IN ('instructor_id', 'social_media_id') AND Non_unique = 0");
                if (!empty($indexes)) {
                    // Group by Key_name to find the composite index
                    $indexGroups = [];
                    foreach ($indexes as $idx) {
                        $indexGroups[$idx->Key_name][] = $idx->Column_name;
                    }
                    foreach ($indexGroups as $keyName => $columns) {
                        if (in_array('instructor_id', $columns) && in_array('social_media_id', $columns)) {
                            $indexName = $keyName;
                            break;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Continue without dropping index
        }
        
        // Drop the existing unique constraint if found
        if ($indexName) {
            try {
                DB::statement("ALTER TABLE instructor_social_medias DROP INDEX `{$indexName}`");
            } catch (\Exception $e) {
                // Index might not exist, continue
            }
        }
        
        // Drop foreign key constraint before modifying the column (if it exists)
        try {
            $foreignKeys = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instructor_social_medias' AND COLUMN_NAME = 'social_media_id' AND REFERENCED_TABLE_NAME IS NOT NULL");
            if (!empty($foreignKeys)) {
                $fkName = $foreignKeys[0]->CONSTRAINT_NAME;
                DB::statement("ALTER TABLE instructor_social_medias DROP FOREIGN KEY `{$fkName}`");
            }
        } catch (\Exception $e) {
            // Foreign key might not exist, continue
        }
        
        Schema::table('instructor_social_medias', function (Blueprint $table) {
            // Make social_media_id nullable if column exists
            if (Schema::hasColumn('instructor_social_medias', 'social_media_id')) {
                $table->unsignedBigInteger('social_media_id')->nullable()->change();
            }
            
            // Add title column if it doesn't exist
            if (!Schema::hasColumn('instructor_social_medias', 'title')) {
                $table->string('title')->nullable()->after('social_media_id');
            }
        });
        
        // Create new unique constraint with instructor_id and title
        // Check if index already exists before creating
        $newIndexExists = false;
        try {
            $newIndexes = DB::select("SHOW INDEX FROM instructor_social_medias WHERE Key_name = 'unique_instructor_social_media_title'");
            if (!empty($newIndexes)) {
                $newIndexExists = true;
            }
        } catch (\Exception $e) {
            // Continue
        }
        
        if (!$newIndexExists) {
            Schema::table('instructor_social_medias', function (Blueprint $table) {
                $table->unique(['instructor_id', 'title'], 'unique_instructor_social_media_title');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('instructor_social_medias')) {
            return;
        }
        
        // Drop the new unique constraint if it exists
        try {
            $newIndexes = DB::select("SHOW INDEX FROM instructor_social_medias WHERE Key_name = 'unique_instructor_social_media_title'");
            if (!empty($newIndexes)) {
                Schema::table('instructor_social_medias', function (Blueprint $table) {
                    $table->dropUnique('unique_instructor_social_media_title');
                });
            }
        } catch (\Exception $e) {
            // Index might not exist, continue
        }
        
        // Remove title column if it exists
        if (Schema::hasColumn('instructor_social_medias', 'title')) {
            Schema::table('instructor_social_medias', function (Blueprint $table) {
                $table->dropColumn('title');
            });
        }
        
        // Make social_media_id not nullable again if column exists
        if (Schema::hasColumn('instructor_social_medias', 'social_media_id')) {
            Schema::table('instructor_social_medias', function (Blueprint $table) {
                $table->unsignedBigInteger('social_media_id')->nullable(false)->change();
            });
        }
        
        // Restore foreign key constraint if it doesn't exist
        try {
            $foreignKeys = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instructor_social_medias' AND COLUMN_NAME = 'social_media_id' AND REFERENCED_TABLE_NAME IS NOT NULL");
            if (empty($foreignKeys)) {
                Schema::table('instructor_social_medias', function (Blueprint $table) {
                    $table->foreign('social_media_id')->references('id')->on('social_medias')->onDelete('cascade');
                });
            }
        } catch (\Exception $e) {
            // Continue
        }
        
        // Restore the original unique constraint if it doesn't exist
        try {
            $oldIndexes = DB::select("SHOW INDEX FROM instructor_social_medias WHERE Key_name = 'unique_instructor_social_media'");
            if (empty($oldIndexes)) {
                Schema::table('instructor_social_medias', function (Blueprint $table) {
                    $table->unique(['instructor_id', 'social_media_id'], 'unique_instructor_social_media');
                });
            }
        } catch (\Exception $e) {
            // Continue
        }
    }
};
