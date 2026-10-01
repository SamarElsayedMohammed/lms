<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessKnowledgeIngestionJob;
use App\Models\ChatbotKnowledgeBase;
use App\Models\Course\Course;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WebPageKnowledgeService
{
    private const MAX_REDIRECTS = 3;

    private const MAX_BYTES = 1_500_000;

    private const MAX_TEXT = 60_000;

    /**
     * @return array{url: string, title: string, text: string}
     */
    public function extract(string $url): array
    {
        $current = $this->normalizePublicUrl($url);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $this->assertPublicHost((string) parse_url($current, PHP_URL_HOST));

            $response = Http::withOptions(['allow_redirects' => false])
                ->connectTimeout(5)
                ->timeout(12)
                ->withHeaders([
                    'User-Agent' => 'SkillsoKnowledgeBot/1.0',
                    'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.9',
                ])
                ->get($current);

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');
                if (! is_string($location) || $location === '') {
                    throw new RuntimeException('الرابط أعاد توجيهاً بدون عنوان جديد.');
                }
                $current = $this->normalizePublicUrl($this->resolveRedirect($current, $location));

                continue;
            }

            if (! $response->successful()) {
                throw new RuntimeException('تعذر فتح الصفحة. تأكد أن الرابط عام ويمكن الوصول إليه.');
            }

            $body = $response->body();
            if (strlen($body) > self::MAX_BYTES) {
                throw new RuntimeException('الصفحة أكبر من الحجم المسموح لقراءتها.');
            }

            $type = strtolower((string) $response->header('Content-Type'));
            $isText = $type === ''
                || str_contains($type, 'text/html')
                || str_contains($type, 'application/xhtml')
                || str_contains($type, 'text/plain');
            if (! $isText || str_starts_with($body, '%PDF')) {
                throw new RuntimeException('الرابط ليس صفحة ويب نصية. استخدم رابط صفحة الكورسات أو الموقع.');
            }

            $text = $this->htmlToText($body);
            if ($text === '') {
                throw new RuntimeException('الصفحة لا تحتوي نصاً يمكن للمساعد قراءته.');
            }

            return [
                'url' => mb_substr($current, 0, 500),
                'title' => $this->pageTitle($body),
                'text' => mb_substr($text, 0, self::MAX_TEXT),
            ];
        }

        throw new RuntimeException('الرابط أعاد التوجيه أكثر من اللازم.');
    }

    /**
     * @param  array{url: string, title: string, text: string}  $page
     */
    public function indexCourse(Course $course, array $page): void
    {
        $this->indexCombined($course, $page['text'], null, $page['url'], $page['title'] !== '' ? $page['title'] : null);
    }

    public function indexCombined(Course $course, string $content, ?string $filePath, ?string $sourceUrl, ?string $title = null): void
    {
        $content = trim($content);
        if ($content === '') {
            return;
        }

        $storedFile = $this->storedFilePath($filePath, $sourceUrl);
        $entry = ChatbotKnowledgeBase::updateOrCreate(
            ['course_id' => $course->id, 'target_audience' => 'course'],
            [
                'title' => ($title !== null && $title !== '') ? $title : $course->title,
                'content' => $content,
                'file_path' => $storedFile,
                'file_type' => $storedFile
                    ? ($this->extensionFromPath($storedFile) ?: 'file')
                    : ($sourceUrl ? 'url' : null),
                'source_url' => $sourceUrl,
                'is_active' => true,
                'processing_status' => 'queued',
                'failure_reason' => null,
            ]
        );

        ProcessKnowledgeIngestionJob::dispatch($entry->id, (int) $course->id, 'course');
    }

    public function joinTexts(string ...$parts): string
    {
        $kept = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $needle = mb_substr($part, 0, min(180, mb_strlen($part)));
            $alreadyStored = false;
            foreach ($kept as $existing) {
                if ($needle !== '' && str_contains($existing, $needle)) {
                    $alreadyStored = true;
                    break;
                }
            }
            if (! $alreadyStored) {
                $kept[] = $part;
            }
        }

        return implode("\n\n", $kept);
    }

    /**
     * @param  array{url: string, title: string, text: string}  $page
     * @return array{content: string, file_path: null, file_type: string, source_url: string}
     */
    public function knowledgeAttributes(array $page): array
    {
        return [
            'content' => $page['text'],
            'file_path' => null,
            'file_type' => 'url',
            'source_url' => $page['url'],
        ];
    }

    private function storedFilePath(?string $filePath, ?string $sourceUrl): ?string
    {
        $filePath = trim((string) $filePath);
        if ($filePath === '') {
            return null;
        }
        if ($sourceUrl && $filePath === $sourceUrl) {
            return null;
        }
        if (str_starts_with($filePath, 'http') && ! str_contains($filePath, 'b-cdn.net') && ! str_contains($filePath, 'mediadelivery.net')) {
            return null;
        }

        return $filePath;
    }

    private function extensionFromPath(string $path): string
    {
        $target = parse_url($path, PHP_URL_PATH);
        $target = is_string($target) && $target !== '' ? $target : $path;

        return strtolower(pathinfo($target, PATHINFO_EXTENSION));
    }

    public function htmlToText(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6]|\/tr)\b[^>]*>/i', "\n", $html) ?? $html;

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n[ \t]+/u", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    public function normalizePublicUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (! is_array($parts)) {
            throw new RuntimeException('صيغة الرابط غير صحيحة.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('الرابط لازم يبدأ بـ http أو https.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('الرابط لا يمكن أن يحتوي بيانات دخول.');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $this->isBlockedHost($host)) {
            throw new RuntimeException('هذا الرابط غير مسموح.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($host);
        }

        return $url;
    }

    private function pageTitle(string $html): string
    {
        if (! preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            return '';
        }

        $title = trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return mb_substr($title, 0, 255);
    }

    private function resolveRedirect(string $current, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME)) {
            return $location;
        }

        $base = parse_url($current);
        $origin = ($base['scheme'] ?? 'https').'://'.($base['host'] ?? '');
        if (isset($base['port'])) {
            $origin .= ':'.$base['port'];
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = $base['path'] ?? '/';
        $dir = str_ends_with($path, '/') ? $path : (preg_replace('#/[^/]*$#', '/', $path) ?: '/');

        return $origin.$dir.ltrim($location, '/');
    }

    private function assertPublicHost(string $host): void
    {
        if ($this->isBlockedHost($host)) {
            throw new RuntimeException('هذا الرابط غير مسموح.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($host);

            return;
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (! empty($record['ip'])) {
                    $ips[] = (string) $record['ip'];
                }
                if (! empty($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }
        if ($ips === []) {
            $resolved = @gethostbynamel($host);
            if (is_array($resolved)) {
                $ips = $resolved;
            }
        }
        if ($ips === []) {
            throw new RuntimeException('تعذر الوصول إلى هذا الرابط.');
        }

        foreach ($ips as $ip) {
            $this->assertPublicIp($ip);
        }
    }

    private function assertPublicIp(string $ip): void
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('لا يمكن قراءة صفحات داخل الشبكة الخاصة.');
        }
    }

    private function isBlockedHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return $host === ''
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || $host === 'metadata.google.internal'
            || $host === '0.0.0.0';
    }
}
