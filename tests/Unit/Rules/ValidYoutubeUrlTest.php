<?php

namespace Tests\Unit\Rules;

use App\Rules\ValidYoutubeUrl;
use Tests\TestCase;

class ValidYoutubeUrlTest extends TestCase
{
    public function test_valid_youtube_urls_pass()
    {
        $rule = new ValidYoutubeUrl('lecture', 'youtube_url');

        $validUrls = [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
        ];

        $passedCount = 0;
        foreach ($validUrls as $url) {
            $rule->validate('video_url', $url, function ($message): void {
                $this->fail("Validation failed for valid URL: $message");
            });
            $passedCount++;
        }

        $this->assertEquals(count($validUrls), $passedCount, 'All valid URLs should pass validation');
    }

    public function test_invalid_youtube_urls_fail()
    {
        $rule = new ValidYoutubeUrl('lecture', 'youtube_url');

        $invalidUrls = [
            'https://www.google.com',
            'https://vimeo.com/123456789',
            'https://www.youtube.com/watch',
            'not-a-url',
            'https://www.youtube.com/watch?x=123',
            'https://www.youtube.com/playlist?list=123',
            'https://www.youtube.com/channel/123',
        ];

        foreach ($invalidUrls as $url) {
            $validationFailed = false;
            $rule->validate('video_url', $url, static function ($message) use (&$validationFailed): void {
                $validationFailed = true;
            });
            $this->assertTrue($validationFailed, "Should have failed for URL: {$url}");
        }
    }

    public function test_empty_url_fails_when_required()
    {
        $rule = new ValidYoutubeUrl('lecture', 'youtube_url');

        $validationFailed = false;
        $rule->validate('video_url', '', static function ($message) use (&$validationFailed): void {
            $validationFailed = true;
        });
        $this->assertTrue($validationFailed);
    }

    public function test_rule_accepts_null_when_not_lecture_type()
    {
        $rule = new ValidYoutubeUrl('other', 'other_type');

        $validationFailed = false;
        $rule->validate('video_url', '', static function ($message) use (&$validationFailed): void {
            $validationFailed = true;
        });
        $this->assertFalse($validationFailed);
    }

    public function test_rule_accepts_null_when_not_youtube_type()
    {
        $rule = new ValidYoutubeUrl('lecture', 'other_type');

        $validationFailed = false;
        $rule->validate('video_url', '', static function ($message) use (&$validationFailed): void {
            $validationFailed = true;
        });
        $this->assertFalse($validationFailed);
    }
}
