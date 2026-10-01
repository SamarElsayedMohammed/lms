<?php

declare(strict_types=1);

namespace Tests\Unit\Filesystem;

use App\Filesystem\BunnyStorageAdapter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BunnyStorageAdapterTest extends TestCase
{
    public function test_get_url_joins_the_cdn_base_and_object_path(): void
    {
        $adapter = new BunnyStorageAdapter(
            'skillso',
            'key',
            'storage.bunnycdn.com',
            'https://skillso.b-cdn.net/',
        );

        $this->assertSame(
            'https://skillso.b-cdn.net/courses/demo/thumbnail/cover.jpg',
            $adapter->getUrl('/courses/demo/thumbnail/cover.jpg'),
        );
    }

    public function test_get_url_requires_a_cdn_base(): void
    {
        $adapter = new BunnyStorageAdapter('skillso', 'key');

        $this->expectException(RuntimeException::class);
        $adapter->getUrl('courses/demo/thumbnail/cover.jpg');
    }
}
