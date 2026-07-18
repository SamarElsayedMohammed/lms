<?php
/**
 * Certificate Integrity Report (READ-ONLY — no data modifications).
 *
 * Finds all course certificates where the stored progress_percentage < 100,
 * indicating the certificate was issued before the course was fully completed.
 *
 * Usage (from backend-skillso directory):
 *   php scratch/certificate_integrity_report.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Certificate Integrity Report ===\n";
echo "Generated at: " . now()->toDateTimeString() . "\n\n";

// -----------------------------------------------------------------------
// 1. Certificates with progress < 100 (the core integrity check)
// -----------------------------------------------------------------------
$badCerts = DB::select("
    SELECT
        cc.id                     AS cert_id,
        cc.certificate_number,
        cc.status                 AS cert_status,
        cc.issued_date,
        cc.created_at             AS cert_created_at,
        u.id                      AS user_id,
        u.name                    AS user_name,
        u.email                   AS user_email,
        c.id                      AS course_id,
        c.title                   AS course_title,
        ucp.progress_percentage,
        ucp.status                AS progress_status,
        ucp.completed_items,
        ucp.total_items
    FROM course_certificates cc
    JOIN users  u ON u.id  = cc.user_id  AND u.deleted_at IS NULL
    JOIN courses c ON c.id = cc.course_id
    LEFT JOIN user_course_progress ucp
        ON ucp.user_id = cc.user_id AND ucp.course_id = cc.course_id
    WHERE cc.status != 'revoked'
      AND (ucp.progress_percentage IS NULL OR ucp.progress_percentage < 100)
    ORDER BY cc.created_at DESC
");

if (empty($badCerts)) {
    echo "OK: No integrity violations found — all active certificates have progress = 100%.\n";
} else {
    echo "WARNING: Found " . count($badCerts) . " certificate(s) with progress < 100%:\n\n";
    printf("%-8s %-25s %-12s %-10s %-40s %-40s %-12s %-15s\n",
        'CERT_ID', 'CERT_NUMBER', 'CERT_STATUS', 'USER_ID', 'USER_NAME', 'COURSE_TITLE', 'PROGRESS%', 'ISSUED_DATE'
    );
    echo str_repeat('-', 170) . "\n";

    foreach ($badCerts as $row) {
        printf("%-8s %-25s %-12s %-10s %-40s %-40s %-12s %-15s\n",
            $row->cert_id,
            $row->certificate_number ?? 'N/A',
            $row->cert_status,
            $row->user_id,
            mb_substr($row->user_name, 0, 38),
            mb_substr($row->course_title, 0, 38),
            $row->progress_percentage !== null ? number_format((float)$row->progress_percentage, 2) . '%' : 'NULL',
            $row->issued_date ?? 'N/A'
        );
    }
}

// -----------------------------------------------------------------------
// 2. Certificates with non-standard number format (potential manual inserts)
// -----------------------------------------------------------------------
echo "\n\n--- Certificates with non-standard certificate_number format ---\n";
echo "    (Expected standard format matches: CERT-{userId}-{timestamp})\n\n";

$nonStandardCerts = DB::select("
    SELECT
        cc.id, cc.certificate_number, cc.status, cc.created_at,
        u.id AS user_id, u.name AS user_name,
        c.id AS course_id, c.title AS course_title
    FROM course_certificates cc
    JOIN users  u ON u.id  = cc.user_id
    JOIN courses c ON c.id = cc.course_id
    WHERE cc.certificate_number IS NOT NULL
      AND cc.certificate_number NOT REGEXP '^CERT-[0-9]+-[0-9]+$'
    ORDER BY cc.created_at DESC
");

if (empty($nonStandardCerts)) {
    echo "OK: All certificate numbers follow the standard format.\n";
} else {
    echo "WARNING: Found " . count($nonStandardCerts) . " certificate(s) with non-standard numbers:\n\n";
    foreach ($nonStandardCerts as $row) {
        echo "  id={$row->id}  number={$row->certificate_number}  user={$row->user_name} (uid={$row->user_id})  course={$row->course_title}  created={$row->created_at}\n";
    }
}

// -----------------------------------------------------------------------
// 3. Summary counts
// -----------------------------------------------------------------------
echo "\n\n--- Summary ---\n";
$total   = DB::table('course_certificates')->count();
$active  = DB::table('course_certificates')->where('status', 'active')->count();
$revoked = DB::table('course_certificates')->where('status', 'revoked')->count();
$noProgress = DB::table('course_certificates as cc')
    ->leftJoin('user_course_progress as ucp', function ($join) {
        $join->on('ucp.user_id', '=', 'cc.user_id')
             ->on('ucp.course_id', '=', 'cc.course_id');
    })
    ->where('cc.status', '!=', 'revoked')
    ->whereRaw('(ucp.progress_percentage IS NULL OR ucp.progress_percentage < 100)')
    ->count();

echo "Total certificates:           {$total}\n";
echo "Active certificates:          {$active}\n";
echo "Revoked certificates:         {$revoked}\n";
echo "Active with progress < 100%:  {$noProgress}  <- these need manual review\n\n";

echo "=== END OF REPORT ===\n";
echo "NOTE: This script is read-only. No data was modified.\n";
