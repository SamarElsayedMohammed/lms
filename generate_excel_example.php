<?php
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();

// Sheet 1: الكورسات
$sheet1 = $spreadsheet->getActiveSheet();
$sheet1->setTitle('الكورسات');
$sheet1->setCellValue('A1', 'course_id');
$sheet1->setCellValue('B1', 'اسم الكورس');

$sheet1->setCellValue('A2', 'LMS-PHP-01');
$sheet1->setCellValue('B2', 'كورس تطوير الويب الشامل باستخدام PHP');

$sheet1->setCellValue('A3', 'LMS-MARKET-02');
$sheet1->setCellValue('B3', 'دورة التسويق الإلكتروني المتقدمة');

// Sheet 2: الدروس
$sheet2 = $spreadsheet->createSheet();
$sheet2->setTitle('الدروس');
$sheet2->setCellValue('A1', 'course_id');
$sheet2->setCellValue('B1', 'اسم المحاضرة');
$sheet2->setCellValue('C1', 'lesson_id');

// دروس الكورس الأول
$sheet2->setCellValue('A2', 'LMS-PHP-01');
$sheet2->setCellValue('B2', 'المقدمة: كيف يعمل الويب؟');
$sheet2->setCellValue('C2', 'd3b07384-d113-4d6b-93e7-f1388b3941a5'); // Bunny CDN Video UUID

$sheet2->setCellValue('A3', 'LMS-PHP-01');
$sheet2->setCellValue('B3', 'تثبيت بيئة العمل');
$sheet2->setCellValue('C3', 'e4c07384-a113-4d6b-93e7-f1388b3941a6');

// دروس الكورس الثاني
$sheet2->setCellValue('A4', 'LMS-MARKET-02');
$sheet2->setCellValue('B4', 'كيف تطلق أول حملة على فيسبوك');
$sheet2->setCellValue('C4', 'f5d07384-b113-4d6b-93e7-f1388b3941a7');

$writer = new Xlsx($spreadsheet);
$path = __DIR__ . '/sample_course_import.xlsx';
$writer->save($path);
echo "File created successfully at: " . $path . PHP_EOL;
