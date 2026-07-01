<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course\Course;
use App\Models\Course\CourseChapter\CourseChapter;
use App\Models\Course\CourseChapter\Lecture\CourseChapterLecture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

final class CourseExcelImportService
{
    private const CHAPTER_TITLE = 'المحتوى';

    /**
     * @return array{courses_upserted: int, lectures_upserted: int, skipped_rows: list<string>, errors: list<string>}
     */
    public function import(
        string $absolutePath,
        int $userId,
        int $categoryId,
        ?int $languageId,
    ): array {
        $spreadsheet = IOFactory::load($absolutePath);

        $coursesSheet = $spreadsheet->getSheetByName('الكورسات')
            ?? $spreadsheet->getSheet(0);
        $lessonsSheet = $spreadsheet->getSheetByName('الدروس')
            ?? ($spreadsheet->getSheetCount() > 1 ? $spreadsheet->getSheet(1) : null);

        if ($lessonsSheet === null) {
            throw new \InvalidArgumentException(__('Excel must contain two sheets: الكورسات and الدروس'));
        }

        $hasImportCode = Schema::hasColumn('courses', 'import_code');

        $coursesRows = $this->extractDataRows($coursesSheet, 'course_id');
        $lessonsRows = $this->extractDataRows($lessonsSheet, 'course_id');

        $stats = [
            'courses_upserted' => 0,
            'lectures_upserted' => 0,
            'skipped_rows' => [],
            'errors' => [],
        ];

        $courseByCode = [];

        DB::beginTransaction();
        try {
            foreach ($coursesRows as $rowIndex => $row) {
                $code = $this->normalizeCourseCode($row['course_id'] ?? null);
                $title = $this->stringCell($row['name'] ?? null);
                if ($code === '' || $title === '') {
                    $stats['skipped_rows'][] = "courses row {$rowIndex}: empty course_id or title";

                    continue;
                }

                $stableSlug = $this->stableCourseSlug($code);

                $price = (float) ($row['price'] ?? 0);
                $isFree = $price <= 0;
                $level = $this->stringCell($row['level'] ?? 'beginner') ?: 'beginner';
                $status = $this->stringCell($row['status'] ?? 'draft') ?: 'draft';
                $shortDesc = $this->stringCell($row['short_description'] ?? null) ?: null;

                if (!in_array($level, ['beginner', 'intermediate', 'advanced'], true)) {
                    $level = 'beginner';
                }
                if (!in_array($status, ['draft', 'pending', 'publish'], true)) {
                    $status = 'draft';
                }

                $attributes = [
                    'title' => $title,
                    'user_id' => $userId,
                    'category_id' => $categoryId,
                    'language_id' => $languageId,
                    'level' => $level,
                    'course_type' => $isFree ? 'free' : 'paid',
                    'price' => $price,
                    'discount_price' => null,
                    'status' => $status,
                    'approval_status' => $status === 'publish' ? 'approved' : null,
                    'is_active' => false,
                    'is_free' => $isFree,
                    'short_description' => $shortDesc,
                    'meta_keywords' => $code,
                    'slug' => $stableSlug,
                ];

                if ($hasImportCode) {
                    $attributes['import_code'] = $code;
                }

                $course = Course::query()->updateOrCreate(
                    $hasImportCode ? ['import_code' => $code] : ['slug' => $stableSlug],
                    $attributes,
                );

                $courseByCode[$code] = $course;
                $stats['courses_upserted']++;
            }

            foreach ($lessonsRows as $rowIndex => $row) {
                $code = $this->normalizeCourseCode($row['course_id'] ?? null);
                $lectureTitle = $this->stringCell($row['lecture_name'] ?? null);
                $lessonUuid = $this->stringCell($row['lesson_id'] ?? null);

                if ($code === '' || $lectureTitle === '') {
                    $stats['skipped_rows'][] = "lessons row {$rowIndex}: empty course_id or lecture title";

                    continue;
                }

                if (! isset($courseByCode[$code])) {
                    $course = $this->findCourseByCode($code, $hasImportCode);
                    if ($course === null) {
                        $stats['errors'][] = "lessons row {$rowIndex}: course {$code} not found in الكورسات sheet";

                        continue;
                    }
                    $courseByCode[$code] = $course;
                } else {
                    $course = $courseByCode[$code];
                }

                $chapter = $this->ensureImportChapter($course, $userId);

                $lectureSlug = $this->stableLectureSlug($lessonUuid, $lectureTitle);

                // 1. Find the Bunny ID
                $bunnyId = null;
                if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $lessonUuid)) {
                    // Try the lesson_id column first (as it usually contains the UUID in the provided file)
                    $bunnyId = $lessonUuid;
                } else {
                    // Fallback to checking any string in the row
                    foreach ($row as $cell) {
                        $cellStr = trim((string) $cell);
                        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $cellStr)) {
                            $bunnyId = $cellStr;
                            break;
                        }
                    }
                }

                $libId = '423625';
                $videoUrl = $bunnyId ? "https://iframe.mediadelivery.net/embed/{$libId}/{$bunnyId}" : null;
                $lectureType = $videoUrl ? 'youtube_url' : 'file';

                CourseChapterLecture::query()->updateOrCreate(
                    ['slug' => $lectureSlug],
                    [
                        'user_id' => $userId,
                        'course_chapter_id' => $chapter->id,
                        'title' => $lectureTitle,
                        'type' => $lectureType,
                        'file' => null,
                        'file_extension' => null,
                        'youtube_url' => $videoUrl,
                        'hours' => 0,
                        'minutes' => 0,
                        'seconds' => 0,
                        'description' => $lessonUuid !== '' ? 'import_uuid:'.$lessonUuid : null,
                        'is_active' => true,
                        'free_preview' => false,
                        'is_free' => false,
                    ],
                );

                $stats['lectures_upserted']++;
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $stats;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractDataRows(Worksheet $sheet, string $headerMarker): array
    {
        $headerRowIndex = $this->findHeaderRow($sheet, $headerMarker);
        $headers = $this->readHeaderMap($sheet, $headerRowIndex);
        $out = [];
        $highestRow = $sheet->getHighestDataRow();

        for ($r = $headerRowIndex + 1; $r <= $highestRow; $r++) {
            $row = [];
            foreach ($headers as $colLetter => $key) {
                $coord = $colLetter.$r;
                $row[$key] = $sheet->getCell($coord)->getValue();
            }
            if ($this->rowIsEmpty($row)) {
                continue;
            }
            $out[$r] = $row;
        }

        return $out;
    }

    private function findHeaderRow(Worksheet $sheet, string $marker): int
    {
        $maxScan = min(50, $sheet->getHighestDataRow());
        for ($r = 1; $r <= $maxScan; $r++) {
            $rowValues = [];
            foreach (range('A', 'Z') as $col) {
                $v = $sheet->getCell($col.$r)->getValue();
                if ($v !== null && $v !== '') {
                    $rowValues[] = mb_strtolower(trim((string) $v));
                }
            }
            $joined = implode(' ', $rowValues);
            if (str_contains($joined, mb_strtolower($marker))) {
                return $r;
            }
        }

        return 2;
    }

    /**
     * @return array<string, string> column letter => normalized key
     */
    private function readHeaderMap(Worksheet $sheet, int $headerRowIndex): array
    {
        $map = [];
        foreach (range('A', 'Z') as $col) {
            $raw = $sheet->getCell($col.$headerRowIndex)->getValue();
            if ($raw === null || $raw === '') {
                continue;
            }
            $label = mb_strtolower(trim((string) $raw));
            $key = match (true) {
                str_contains($label, 'course_id') => 'course_id',
                str_contains($label, 'course_name') || str_contains($label, 'اسم الكورس') => 'name',
                str_contains($label, 'lecture_name') || str_contains($label, 'lesson_name') || str_contains($label, 'اسم المحاضرة') => 'lecture_name',
                str_contains($label, 'lesson_id') || str_contains($label, 'lecture_id') => 'lesson_id',
                str_contains($label, 'short_description') || str_contains($label, 'وصف') => 'short_description',
                str_contains($label, 'level') || str_contains($label, 'مستوى') => 'level',
                str_contains($label, 'price') || str_contains($label, 'سعر') => 'price',
                str_contains($label, 'status') || str_contains($label, 'حالة') => 'status',
                default => null,
            };
            if ($key !== null) {
                $map[$col] = $key;
            }
        }

        if (! in_array('course_id', $map, true)) {
            throw new \InvalidArgumentException(__('Could not find course_id column in header row.'));
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $v) {
            if ($v !== null && trim((string) $v) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeCourseCode(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        $s = trim((string) $value);

        return $s;
    }

    private function stringCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function stableCourseSlug(string $code): string
    {
        $base = Str::slug(mb_strtolower(trim($code)));
        if ($base === '') {
            $base = 'import-'.substr(md5($code), 0, 12);
        }

        return mb_substr($base, 0, 240);
    }

    private function stableLectureSlug(string $uuid, string $titleFallback): string
    {
        $uuid = trim($uuid);
        if ($uuid !== '' && preg_match('/^[a-f0-9-]{36}$/i', $uuid)) {
            $base = 'imp-'.str_replace('-', '', strtolower($uuid));
        } else {
            $base = 'imp-'.substr(sha1($titleFallback.'|'.$uuid), 0, 32);
        }

        return mb_substr($base, 0, 240);
    }

    private function findCourseByCode(
        string $code,
        bool $hasImportCode,
    ): ?Course {
        if ($hasImportCode) {
            $c = Course::query()->where('import_code', $code)->first();
            if ($c !== null) {
                return $c;
            }
        }

        $slug = $this->stableCourseSlug($code);

        return Course::query()->where('slug', $slug)->first();
    }

    private function ensureImportChapter(Course $course, int $userId): CourseChapter
    {
        $existing = CourseChapter::query()
            ->where('course_id', $course->id)
            ->where('title', self::CHAPTER_TITLE)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $slug = HelperService::generateUniqueSlug(CourseChapter::class, 'content-'.$course->slug);

        $maxOrder = (int) CourseChapter::query()->where('course_id', $course->id)->max('chapter_order');

        return CourseChapter::withoutEvents(function () use ($course, $userId, $slug, $maxOrder): CourseChapter {
            return CourseChapter::query()->create([
                'course_id' => $course->id,
                'user_id' => $userId,
                'title' => self::CHAPTER_TITLE,
                'slug' => $slug,
                'description' => null,
                'is_active' => true,
                'chapter_order' => $maxOrder + 1,
            ]);
        });
    }
}
