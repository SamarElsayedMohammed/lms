<?php
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();

// Sheet 1: الكورسات
$sheet1 = $spreadsheet->getActiveSheet();
$sheet1->setTitle('الكورسات');

// الأعمدة الأساسية والإضافية
$sheet1->setCellValue('A1', 'course_id');
$sheet1->setCellValue('B1', 'اسم الكورس');
$sheet1->setCellValue('C1', 'وصف الكورس');
$sheet1->setCellValue('D1', 'مستوى');
$sheet1->setCellValue('E1', 'سعر');
$sheet1->setCellValue('F1', 'حالة');

// الكورس الأول (مجاني - مبتدئ - منشور)
$sheet1->setCellValue('A2', 'LMS-PHP-01');
$sheet1->setCellValue('B2', 'كورس تطوير الويب الشامل باستخدام PHP');
$sheet1->setCellValue('C2', 'هذا الكورس يغطي أساسيات PHP وبناء تطبيقات الويب.');
$sheet1->setCellValue('D2', 'beginner');
$sheet1->setCellValue('E2', '0');
$sheet1->setCellValue('F2', 'publish');

// الكورس الثاني (مدفوع - متقدم - مسودة)
$sheet1->setCellValue('A3', 'LMS-MARKET-02');
$sheet1->setCellValue('B3', 'دورة التسويق الإلكتروني المتقدمة');
$sheet1->setCellValue('C3', 'تعلم كيف تحلل البيانات وتطلق حملات قوية.');
$sheet1->setCellValue('D3', 'advanced');
$sheet1->setCellValue('E3', '500'); // 500 جنيه/دولار
$sheet1->setCellValue('F3', 'draft');

// Sheet 2: الدروس
$sheet2 = $spreadsheet->createSheet();
$sheet2->setTitle('الدروس');
$sheet2->setCellValue('A1', 'course_id');
$sheet2->setCellValue('B1', 'اسم المحاضرة');
$sheet2->setCellValue('C1', 'lesson_id');

// دروس الكورس الأول
$sheet2->setCellValue('A2', 'LMS-PHP-01');
$sheet2->setCellValue('B2', 'المقدمة: كيف يعمل الويب؟');
$sheet2->setCellValue('C2', 'd3b07384-d113-4d6b-93e7-f1388b3941a5'); 

$sheet2->setCellValue('A3', 'LMS-PHP-01');
$sheet2->setCellValue('B3', 'تثبيت بيئة العمل');
$sheet2->setCellValue('C3', 'e4c07384-a113-4d6b-93e7-f1388b3941a6');

// دروس الكورس الثاني
$sheet2->setCellValue('A4', 'LMS-MARKET-02');
$sheet2->setCellValue('B4', 'كيف تطلق أول حملة على فيسبوك');
$sheet2->setCellValue('C4', 'f5d07384-b113-4d6b-93e7-f1388b3941a7');

$writer = new Xlsx($spreadsheet);
$path = __DIR__ . '/comprehensive_course_import.xlsx';
$writer->save($path);
echo "File created successfully at: " . $path . PHP_EOL;
