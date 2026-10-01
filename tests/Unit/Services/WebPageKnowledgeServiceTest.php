<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\WebPageKnowledgeService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WebPageKnowledgeServiceTest extends TestCase
{
    public function test_html_to_text_drops_scripts_and_keeps_visible_copy(): void
    {
        $service = new WebPageKnowledgeService();
        $text = $service->htmlToText(
            '<html><head><style>.x{}</style><title>كورسات</title></head><body><script>alert(1)</script><h1>دورة Laravel</h1><p>سعرها 200 جنيه</p></body></html>'
        );

        $this->assertStringContainsString('دورة Laravel', $text);
        $this->assertStringContainsString('سعرها 200 جنيه', $text);
        $this->assertStringNotContainsString('alert', $text);
        $this->assertStringNotContainsString('.x{}', $text);
    }

    public function test_private_and_non_http_urls_are_rejected(): void
    {
        $service = new WebPageKnowledgeService();

        $this->expectException(RuntimeException::class);
        $service->normalizePublicUrl('http://127.0.0.1/admin');
    }

    public function test_file_urls_are_rejected(): void
    {
        $service = new WebPageKnowledgeService();

        $this->expectException(RuntimeException::class);
        $service->normalizePublicUrl('file:///etc/passwd');
    }
}
