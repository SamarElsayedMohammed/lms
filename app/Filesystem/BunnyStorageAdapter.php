<?php

namespace App\Filesystem;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use RuntimeException;

/**
 * Bunny Storage HTTP API (storage zone). Public URLs come from the pull-zone config on the disk.
 */
class BunnyStorageAdapter implements FilesystemAdapter
{
    public function __construct(
        private readonly string $zone,
        private readonly string $accessKey,
        private readonly string $host = 'storage.bunnycdn.com',
        private readonly string $cdnUrl = '',
    ) {}

    /**
     * Laravel 12 resolves Storage::url() through this method. Without it the
     * disk throws "This driver does not support retrieving URLs."
     */
    public function getUrl(string $path): string
    {
        $base = rtrim($this->cdnUrl, '/');
        if ($base === '') {
            throw new RuntimeException('Bunny CDN URL is not configured.');
        }

        $path = ltrim($path, '/');

        return $path === '' ? $base : $base.'/'.$path;
    }

    public function fileExists(string $path): bool
    {
        $response = Http::withHeaders($this->headers())
            ->connectTimeout(5)
            ->timeout(15)
            ->head($this->objectUrl($path));

        return $response->successful();
    }

    public function directoryExists(string $path): bool
    {
        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->put($path, $contents);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->put($path, Utils::streamFor($contents));
    }

    public function read(string $path): string
    {
        $response = Http::withHeaders($this->headers())
            ->connectTimeout(5)
            ->timeout(60)
            ->get($this->objectUrl($path));

        if (! $response->successful()) {
            throw UnableToReadFile::fromLocation($path, $response->body());
        }

        return $response->body();
    }

    public function readStream(string $path)
    {
        $contents = $this->read($path);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw UnableToReadFile::fromLocation($path, 'Unable to open temp stream');
        }
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        $response = Http::withHeaders($this->headers())
            ->connectTimeout(5)
            ->timeout(20)
            ->delete($this->objectUrl($path));

        if ($response->status() === 404) {
            return;
        }

        if (! $response->successful()) {
            throw UnableToDeleteFile::atLocation($path, $response->body());
        }
    }

    public function deleteDirectory(string $path): void
    {
        $this->delete($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        // Bunny creates parent folders when an object is uploaded.
    }

    public function setVisibility(string $path, string $visibility): void
    {
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, 'public');
    }

    public function mimeType(string $path): FileAttributes
    {
        $type = $this->head($path)->header('Content-Type');
        if (! is_string($type) || $type === '') {
            throw UnableToRetrieveMetadata::mimeType($path);
        }

        return new FileAttributes($path, null, null, null, $type);
    }

    public function lastModified(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::lastModified($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        $length = $this->head($path)->header('Content-Length');
        if (! is_numeric($length)) {
            throw UnableToRetrieveMetadata::fileSize($path);
        }

        return new FileAttributes($path, (int) $length);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return [];
    }

    public function move(string $source, string $destination, Config $config): void
    {
        throw UnableToMoveFile::fromLocationTo($source, $destination);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        throw UnableToCopyFile::fromLocationTo($source, $destination);
    }

    private function put(string $path, mixed $body): void
    {
        $response = Http::withHeaders($this->headers())
            ->withBody($body, 'application/octet-stream')
            ->connectTimeout(10)
            ->timeout(600)
            ->put($this->objectUrl($path));

        if (! $response->successful()) {
            throw UnableToWriteFile::atLocation($path, $response->body());
        }
    }

    private function head(string $path): \Illuminate\Http\Client\Response
    {
        return Http::withHeaders($this->headers())
            ->connectTimeout(5)
            ->timeout(15)
            ->head($this->objectUrl($path));
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'AccessKey' => $this->accessKey,
            'Accept' => '*/*',
        ];
    }

    private function objectUrl(string $path): string
    {
        $path = ltrim($path, '/');
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));

        return 'https://'.$this->host.'/'.$this->zone.'/'.$encoded;
    }
}
