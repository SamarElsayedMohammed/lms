<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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

    public static function storeChunk(string $uploadId, int $index, UploadedFile $chunk): void
    {
        $directory = self::chunkDirectory($uploadId);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('تعذر تجهيز مجلد أجزاء الفيديو.');
        }

        $target = $directory.DIRECTORY_SEPARATOR.$index.'.part';
        $chunk->move($directory, $index.'.part');
        if (! is_file($target)) {
            throw new RuntimeException('تعذر حفظ جزء الفيديو.');
        }
    }

    /**
     * @return array{path: string, original_name: string, mime: string}
     */
    public static function assemble(int $userId, string $uploadId, int $total, string $originalName, string $mime): array
    {
        $directory = self::chunkDirectory($uploadId);
        $extension = self::safeExtension($originalName);
        $filename = Str::uuid()->toString().'.'.$extension;
        $destinationDir = storage_path('app/course-media-queue');
        if (! is_dir($destinationDir) && ! mkdir($destinationDir, 0775, true) && ! is_dir($destinationDir)) {
            throw new RuntimeException('تعذر تجهيز مجلد رفع الكورس.');
        }

        $destination = $destinationDir.DIRECTORY_SEPARATOR.$filename;
        $output = fopen($destination, 'wb');
        if ($output === false) {
            throw new RuntimeException('تعذر تجميع الفيديو.');
        }

        try {
            for ($index = 0; $index < $total; $index++) {
                $part = $directory.DIRECTORY_SEPARATOR.$index.'.part';
                if (! is_file($part)) {
                    throw new RuntimeException('جزء من الفيديو ناقص. أعد الرفع.');
                }
                $input = fopen($part, 'rb');
                if ($input === false) {
                    throw new RuntimeException('تعذر قراءة جزء الفيديو.');
                }
                stream_copy_to_stream($input, $output);
                fclose($input);
            }
        } catch (\Throwable $e) {
            fclose($output);
            self::delete($destination);
            throw $e;
        }

        fclose($output);
        self::deleteDirectory($directory);

        $staged = [
            'path' => $destination,
            'original_name' => $originalName !== '' ? $originalName : $filename,
            'mime' => $mime !== '' ? $mime : 'application/octet-stream',
        ];
        Cache::put(self::cacheKey($userId, $uploadId), $staged, now()->addHours(6));

        return $staged;
    }

    /**
     * @return array{path: string, original_name: string, mime: string}
     */
    public static function claim(int $userId, string $uploadId): array
    {
        $staged = Cache::pull(self::cacheKey($userId, $uploadId));
        if (! is_array($staged) || ! is_string($staged['path'] ?? null) || ! is_file($staged['path'])) {
            throw new RuntimeException('الفيديو المرفوع غير موجود. أعد اختيار الملف من الفورم.');
        }

        return [
            'path' => $staged['path'],
            'original_name' => is_string($staged['original_name'] ?? null) ? $staged['original_name'] : basename($staged['path']),
            'mime' => is_string($staged['mime'] ?? null) ? $staged['mime'] : 'application/octet-stream',
        ];
    }

    public static function extensionFor(int $userId, string $uploadId): string
    {
        $staged = Cache::get(self::cacheKey($userId, $uploadId));
        $name = is_array($staged) && is_string($staged['original_name'] ?? null) ? $staged['original_name'] : '';

        return self::safeExtension($name);
    }

    private static function cacheKey(int $userId, string $uploadId): string
    {
        return 'course-media-upload:'.$userId.':'.$uploadId;
    }

    private static function chunkDirectory(string $uploadId): string
    {
        return storage_path('app/course-media-queue/chunks/'.$uploadId);
    }

    private static function safeExtension(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin');

        return preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? $extension : 'bin';
    }

    private static function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
