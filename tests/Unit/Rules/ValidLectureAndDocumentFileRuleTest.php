<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\ValidDocumentFile;
use App\Rules\ValidLectureFile;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class ValidLectureAndDocumentFileRuleTest extends TestCase
{
    public function test_valid_lecture_file_passes_with_allowed_type(): void
    {
        $rule = new ValidLectureFile('lecture', 'file', ['mp4', 'mov']);
        $file = UploadedFile::fake()->create('lecture.mp4', 1024, 'video/mp4');

        $failed = false;
        $rule->validate('lecture_file', $file, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    public function test_valid_lecture_file_rejects_dangerous_extension(): void
    {
        $rule = new ValidLectureFile('lecture', 'file', ['mp4', 'mov', 'php']);
        $file = UploadedFile::fake()->create('shell.php', 10, 'text/x-php');

        $errorMessage = '';
        $rule->validate('lecture_file', $file, function (string $msg) use (&$errorMessage) {
            $errorMessage = $msg;
        });

        $this->assertStringContainsString('insecure file type', $errorMessage);
    }

    public function test_valid_document_file_passes_with_allowed_type(): void
    {
        $rule = new ValidDocumentFile('document', 'file', ['pdf', 'docx']);
        $file = UploadedFile::fake()->create('syllabus.pdf', 100, 'application/pdf');

        $failed = false;
        $rule->validate('document_file', $file, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    public function test_valid_document_file_rejects_dangerous_extension(): void
    {
        $rule = new ValidDocumentFile('document', 'file', ['pdf', 'exe']);
        $file = UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload');

        $errorMessage = '';
        $rule->validate('document_file', $file, function (string $msg) use (&$errorMessage) {
            $errorMessage = $msg;
        });

        $this->assertStringContainsString('insecure file type', $errorMessage);
    }
}
