<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

class CourseMediaStaging
{
    /**
     * @return array{path: string, original_name: string, mime: string}
     */
    public static function store(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $originalName = $file->getClientOriginalName() ?: ('upload.'.$extension);
        $mime = $file->getClientMimeType() ?: 'application/octet-stream';
        $directory = storage_path('app/course-media-queue');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('تعذر تجهيز مجلد رفع الكورس.');
        }

        $filename = Str::uuid()->toString().'.'.$extension;
        $file->move($directory, $filename);

        return [
            'path' => $directory.DIRECTORY_SEPARATOR.$filename,
            'original_name' => $originalName,
            'mime' => $mime,
        ];
    }

    /**
     * @param  array{path: string, original_name?: string, mime?: string}  $staged
     */
    public static function uploadedFile(array $staged): UploadedFile
    {
        return new UploadedFile(
            $staged['path'],
            $staged['original_name'] ?? basename($staged['path']),
            $staged['mime'] ?? null,
            \UPLOAD_ERR_OK,
            true,
        );
    }

    public static function delete(?string $path): void
    {
        if (is_string($path) && $path !== '' && is_file($path)) {
            unlink($path);
        }
    }
}
