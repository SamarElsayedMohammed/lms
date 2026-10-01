<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BunnyStreamService
{
    /**
     * @return array{type: string, value: string, library_id: string|null, guid: string|null}
     */
    public static function storeUploadedVideo(UploadedFile $file, string $title, string $folder, ?string $collectionName = null): array
    {
        if (self::isConfigured() && self::isVideo($file)) {
            $uploaded = self::upload($file, $title, $collectionName);

            return [
                'type' => 'url',
                'value' => $uploaded['embed_url'],
                'library_id' => $uploaded['library_id'],
                'guid' => $uploaded['guid'],
            ];
        }

        return [
            'type' => 'file',
            'value' => FileService::upload($file, $folder),
            'library_id' => null,
            'guid' => null,
        ];
    }

    public static function isConfigured(): bool
    {
        return self::libraryId() !== '' && self::apiKey() !== '';
    }

    public static function isVideo(UploadedFile $file): bool
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: '');

        return in_array($extension, ['mp4', 'mov', 'webm', 'mkv', 'avi', 'm4v', 'flv', 'wmv'], true);
    }

    /**
     * @return array{embed_url: string, library_id: string, guid: string}
     */
    public static function upload(UploadedFile $file, string $title, ?string $collectionName = null): array
    {
        $libraryId = self::libraryId();
        $apiKey = self::apiKey();
        if ($libraryId === '' || $apiKey === '') {
            throw new RuntimeException('Bunny Stream is not configured.');
        }

        $payload = [
            'title' => $title !== '' ? $title : 'Lesson',
        ];
        $collectionId = self::collectionId($collectionName);
        if ($collectionId !== null) {
            $payload['collectionId'] = $collectionId;
        }

        $created = Http::withHeaders(self::headers($apiKey))
            ->connectTimeout(10)
            ->timeout(30)
            ->post("https://video.bunnycdn.com/library/{$libraryId}/videos", $payload);

        if (! $created->successful()) {
            throw new RuntimeException('Bunny Stream create failed: '.$created->body());
        }

        $guid = (string) $created->json('guid');
        if ($guid === '') {
            throw new RuntimeException('Bunny Stream did not return a video id.');
        }

        $realPath = $file->getRealPath();
        if ($realPath === false) {
            throw new RuntimeException('Uploaded video is not readable.');
        }

        $handle = fopen($realPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Uploaded video is not readable.');
        }

        try {
            $uploaded = Http::withHeaders(self::headers($apiKey))
                ->withBody(\GuzzleHttp\Psr7\Utils::streamFor($handle), 'application/octet-stream')
                ->connectTimeout(10)
                ->timeout(3600)
                ->put("https://video.bunnycdn.com/library/{$libraryId}/videos/{$guid}");
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if (! $uploaded->successful()) {
            throw new RuntimeException('Bunny Stream upload failed: '.$uploaded->body());
        }

        return [
            'embed_url' => "https://iframe.mediadelivery.net/embed/{$libraryId}/{$guid}",
            'library_id' => $libraryId,
            'guid' => $guid,
        ];
    }

    public static function deleteByUrl(?string $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        if (! preg_match('#iframe\.mediadelivery\.net/embed/([a-zA-Z0-9_-]+)/([a-zA-Z0-9_-]+)#', $url, $matches)) {
            return false;
        }

        $apiKey = self::apiKey();
        if ($apiKey === '') {
            return false;
        }

        $response = Http::withHeaders(self::headers($apiKey))
            ->connectTimeout(5)
            ->timeout(20)
            ->delete("https://video.bunnycdn.com/library/{$matches[1]}/videos/{$matches[2]}");

        return $response->successful() || $response->status() === 404;
    }

    /** @var array<string, string> */
    private static array $collections = [];

    private static function collectionId(?string $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '' || ! self::isConfigured()) {
            return null;
        }

        if (isset(self::$collections[$name])) {
            return self::$collections[$name];
        }

        $libraryId = self::libraryId();
        $apiKey = self::apiKey();
        $listed = Http::withHeaders(self::headers($apiKey))
            ->connectTimeout(5)
            ->timeout(20)
            ->get("https://video.bunnycdn.com/library/{$libraryId}/collections", [
                'search' => $name,
                'page' => 1,
                'itemsPerPage' => 100,
            ]);

        $items = $listed->successful() ? $listed->json('items') : [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_array($item) && ($item['name'] ?? null) === $name && ! empty($item['guid'])) {
                    return self::$collections[$name] = (string) $item['guid'];
                }
            }
        }

        $created = Http::withHeaders(self::headers($apiKey))
            ->connectTimeout(5)
            ->timeout(20)
            ->post("https://video.bunnycdn.com/library/{$libraryId}/collections", [
                'name' => $name,
            ]);

        $guid = (string) $created->json('guid');
        if (! $created->successful() || $guid === '') {
            return null;
        }

        return self::$collections[$name] = $guid;
    }

    private static function libraryId(): string
    {
        return trim((string) config('services.bunny.library_id'));
    }

    private static function apiKey(): string
    {
        return trim((string) config('services.bunny.api_key'));
    }

    /**
     * @return array<string, string>
     */
    private static function headers(string $apiKey): array
    {
        return [
            'AccessKey' => $apiKey,
            'Accept' => 'application/json',
        ];
    }
}
