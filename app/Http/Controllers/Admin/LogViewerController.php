<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

class LogViewerController extends Controller
{
    private const LOG_PATH = '/app/storage/logs/';
    private const MAX_LINES = 1000;

    /**
     * Show log viewer page
     */
    public function index(): View
    {
        $files = $this->getLogFiles();
        return view('admin.logs.viewer', compact('files'));
    }

    /**
     * Get list of log files
     */
    private function getLogFiles(): array
    {
        $path = self::LOG_PATH;
        
        if (!File::isDirectory($path)) {
            return [];
        }

        $files = File::files($path);
        $logFiles = [];

        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), '.log')) {
                $logFiles[] = [
                    'name' => $file->getFilename(),
                    'size' => $this->formatBytes($file->getSize()),
                    'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                ];
            }
        }

        // Sort by modified date desc
        usort($logFiles, fn($a, $b) => strtotime($b['modified']) - strtotime($a['modified']));

        return $logFiles;
    }

    /**
     * Read log file contents
     */
    public function read(Request $request): JsonResponse
    {
        $file = $request->input('file', 'laravel.log');
        $lines = (int) $request->input('lines', 100);
        $filter = $request->input('filter', '');
        $level = $request->input('level', '');

        $lines = min($lines, self::MAX_LINES);
        $path = self::LOG_PATH . $file;

        if (!File::exists($path) || !File::isReadable($path)) {
            return response()->json([
                'error' => true,
                'message' => 'Log file not found or not readable: ' . $file,
            ], 404);
        }

        // Read file content
        $content = File::get($path);
        $allLines = explode("\n", $content);

        // Filter by level (ERROR, WARNING, INFO, etc.)
        if ($level) {
            $allLines = array_filter($allLines, fn($line) => 
                str_contains($line, ".{$level}.") || 
                str_contains($line, "{$level}:")
            );
        }

        // Filter by search text
        if ($filter) {
            $allLines = array_filter($allLines, fn($line) => 
                stripos($line, $filter) !== false
            );
        }

        // Get last N lines
        $allLines = array_slice($allLines, -$lines);

        // Parse log entries
        $entries = $this->parseLogEntries($allLines);

        return response()->json([
            'error' => false,
            'file' => $file,
            'total_lines' => count($allLines),
            'entries' => $entries,
        ]);
    }

    /**
     * Parse log entries
     */
    private function parseLogEntries(array $lines): array
    {
        $entries = [];
        $currentEntry = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Check if this is a new log entry
            // Format: [2026-06-22 00:29:04] production.ERROR: message
            if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.+)/', $line, $matches)) {
                if ($currentEntry) {
                    $entries[] = $currentEntry;
                }

                $currentEntry = [
                    'datetime' => $matches[1],
                    'env' => $matches[2],
                    'level' => $matches[3],
                    'message' => $matches[4],
                    'stack_trace' => '',
                    'full_line' => $line,
                ];
            } elseif ($currentEntry) {
                // This is part of stack trace
                $currentEntry['stack_trace'] .= $line . "\n";
            }
        }

        if ($currentEntry) {
            $entries[] = $currentEntry;
        }

        // Reverse to show newest first
        return array_reverse($entries);
    }

    /**
     * Clear log file
     */
    public function clear(Request $request): JsonResponse
    {
        $file = $request->input('file', 'laravel.log');
        $path = self::LOG_PATH . $file;

        if (!File::exists($path) || !File::isWritable($path)) {
            return response()->json([
                'error' => true,
                'message' => 'Cannot clear log file: ' . $file,
            ], 400);
        }

        File::put($path, '');

        return response()->json([
            'error' => false,
            'message' => 'Log file cleared successfully',
        ]);
    }

    /**
     * Download log file
     */
    public function download(Request $request)
    {
        $file = $request->input('file', 'laravel.log');
        $path = self::LOG_PATH . $file;

        if (!File::exists($path)) {
            return response()->json([
                'error' => true,
                'message' => 'File not found: ' . $file,
            ], 404);
        }

        return response()->download($path, $file);
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }
}
