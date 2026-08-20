<?php

declare(strict_types=1);

namespace App\Services;

class DocumentParserService
{
    public const MAX_FILE_SIZE_BYTES = 25 * 1024 * 1024;

    /**
     * Extract plain text content from a document file path.
     * Supports: PDF, DOCX, TXT, CSV, JSON, MD, HTML.
     */
    public function extractText(string $filePath, ?string $fileType = null): string
    {
        if (! file_exists($filePath)) {
            throw new \RuntimeException("File does not exist: {$filePath}");
        }

        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            throw new \RuntimeException('Could not determine document size.');
        }

        if ($fileSize > self::MAX_FILE_SIZE_BYTES) {
            throw new \RuntimeException('Document exceeds the maximum allowed size of 25 MB.');
        }

        $extension = strtolower($fileType ?: pathinfo($filePath, PATHINFO_EXTENSION));

        switch ($extension) {
            case 'pdf':
                return $this->parsePdf($filePath);
            case 'docx':
                return $this->parseDocx($filePath);
            case 'json':
                return $this->parseJson($filePath);
            case 'csv':
                return $this->parseCsv($filePath);
            case 'html':
            case 'htm':
                return $this->parseHtml($filePath);
            case 'txt':
            case 'md':
            default:
                return $this->parsePlainText($filePath);
        }
    }

    /**
     * Extract text from PDF file.
     * Uses stream scanning for text objects stream + fallback regex for readable UTF-8 text strings.
     */
    private function parsePdf(string $filePath): string
    {
        $raw = file_get_contents($filePath);
        if ($raw === false || empty($raw)) {
            return '';
        }

        // Try extracting text streams inside PDF (BT...ET)
        $text = '';
        if (preg_match_all('/BT[\s\S]*?ET/m', $raw, $matches)) {
            foreach ($matches[0] as $block) {
                if (preg_match_all('/\((.*?)\)\s*TJ|\((.*?)\)\s*Tj/s', $block, $strMatches)) {
                    $strings = array_merge(array_filter($strMatches[1]), array_filter($strMatches[2]));
                    $text .= implode(' ', $strings)."\n";
                }
            }
        }

        // Fallback: strip PDF binary markers and extract printable UTF-8 / Arabic blocks
        if (mb_strlen(trim($text)) < 50) {
            $clean = preg_replace('/[^\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}a-zA-Z0-9\s\.,!\?\-:\(\)\n]/u', ' ', $raw);
            $lines = explode("\n", (string) $clean);
            $meaningfulLines = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (mb_strlen($trimmed) > 10 && ! str_contains($trimmed, 'endobj') && ! str_contains($trimmed, 'stream')) {
                    $meaningfulLines[] = $trimmed;
                }
            }
            $text = implode("\n", $meaningfulLines);
        }

        return $this->normalizeText($text);
    }

    /**
     * Extract text from DOCX file.
     */
    private function parseDocx(string $filePath): string
    {
        if (! class_exists('\ZipArchive')) {
            return $this->parsePlainText($filePath);
        }

        $zip = new \ZipArchive;
        if ($zip->open($filePath) === true) {
            $documentStats = $zip->statName('word/document.xml');
            if (
                is_array($documentStats)
                && ($documentStats['size'] ?? 0) > self::MAX_FILE_SIZE_BYTES
            ) {
                $zip->close();
                throw new \RuntimeException('Extracted document exceeds the maximum allowed size of 25 MB.');
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xml) {
                $text = strip_tags($xml);

                return $this->normalizeText($text);
            }
        }

        return $this->parsePlainText($filePath);
    }

    /**
     * Parse JSON files into readable text blocks.
     */
    private function parseJson(string $filePath): string
    {
        $raw = file_get_contents($filePath) ?: '';
        $data = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $this->normalizeText(print_r($data, true));
        }

        return $this->normalizeText($raw);
    }

    /**
     * Parse CSV files.
     */
    private function parseCsv(string $filePath): string
    {
        $text = '';
        if (($handle = fopen($filePath, 'r')) !== false) {
            try {
                while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                    $text .= implode(' | ', array_filter($row))."\n";
                }
            } finally {
                fclose($handle);
            }
        }

        return $this->normalizeText($text);
    }

    /**
     * Parse HTML files.
     */
    private function parseHtml(string $filePath): string
    {
        $raw = file_get_contents($filePath) ?: '';
        $clean = strip_tags($raw);

        return $this->normalizeText($clean);
    }

    /**
     * Parse Plain Text files.
     */
    private function parsePlainText(string $filePath): string
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return '';
        }

        $raw = '';
        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 64 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException('Could not read document.');
                }
                $raw .= $chunk;
            }
        } finally {
            fclose($handle);
        }

        return $this->normalizeText($raw);
    }

    /**
     * Clean and normalize raw extracted text.
     */
    public function normalizeText(string $text): string
    {
        // Replace non-breaking spaces and invalid spaces
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Replace multiple empty lines with maximum 2 newlines
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        // Trim each line
        $lines = array_map('trim', explode("\n", $text));
        $text = implode("\n", array_filter($lines, fn ($line) => $line !== ''));

        return trim($text);
    }
}
