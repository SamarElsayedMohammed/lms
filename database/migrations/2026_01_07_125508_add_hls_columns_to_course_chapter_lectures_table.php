<?php

declare(strict_types=1);

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
        Schema::table('course_chapter_lectures', function (Blueprint $table): void {
            // HLS encoding status: pending, processing, completed, failed
            $table->enum('hls_status', ['pending', 'processing', 'completed', 'failed'])
                ->nullable()
                ->after('file_extension')
                ->comment('Status of HLS encoding for video files');

            // Path to HLS master playlist (e.g., "hls/lectures/123/master.m3u8")
            $table->string('hls_manifest_path', 500)
                ->nullable()
                ->after('hls_status')
                ->comment('Path to HLS master playlist file');

            // Error message if encoding fails
            $table->text('hls_error_message')
                ->nullable()
                ->after('hls_manifest_path')
                ->comment('Error message if HLS encoding fails');

            // Timestamp when HLS encoding completed
            $table->timestamp('hls_encoded_at')
                ->nullable()
                ->after('hls_error_message')
                ->comment('Timestamp when HLS encoding completed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_chapter_lectures', function (Blueprint $table): void {
            $table->dropColumn([
                'hls_status',
                'hls_manifest_path',
                'hls_error_message',
                'hls_encoded_at',
            ]);
        });
    }
};
