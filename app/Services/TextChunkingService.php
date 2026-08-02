<?php

declare(strict_types=1);

namespace App\Services;

class TextChunkingService
{
    /**
     * Chunk text into overlapping segments.
     *
     * @param string $text Raw normalized document text
     * @param int $chunkSize Target word/token count per chunk (~300)
     * @param int $chunkOverlap Overlap word count (~50)
     * @return array Array of chunks: [['index' => 0, 'text' => '...', 'hash' => '...', 'token_count' => 120], ...]
     */
    public function chunkText(string $text, int $chunkSize = 300, int $chunkOverlap = 50): array
    {
        $normalized = trim($text);
        if (empty($normalized)) {
            return [];
        }

        // Split text by paragraphs first to preserve semantic boundaries
        $paragraphs = explode("\n\n", $normalized);
        $chunks = [];
        $currentChunk = [];
        $currentWordCount = 0;
        $chunkIndex = 0;

        foreach ($paragraphs as $para) {
            $words = preg_split('/\s+/u', trim($para));
            $paraWordCount = count($words);

            if ($paraWordCount === 0) {
                continue;
            }

            // If a single paragraph exceeds chunk size, split it by sentences
            if ($paraWordCount > $chunkSize) {
                $sentences = preg_split('/(?<=[.!?\n])\s+/u', $para);
                foreach ($sentences as $sentence) {
                    $sentWords = preg_split('/\s+/u', trim($sentence));
                    $sentWordCount = count($sentWords);

                    if ($currentWordCount + $sentWordCount > $chunkSize && $currentWordCount > 0) {
                        $chunkText = implode(' ', $currentChunk);
                        $chunks[] = $this->buildChunkItem($chunkIndex++, $chunkText);

                        // Keep overlap words from end of current chunk
                        $overlapWords = array_slice($currentChunk, max(0, count($currentChunk) - $chunkOverlap));
                        $currentChunk = array_merge($overlapWords, $sentWords);
                        $currentWordCount = count($currentChunk);
                    } else {
                        $currentChunk = array_merge($currentChunk, $sentWords);
                        $currentWordCount += $sentWordCount;
                    }
                }
            } else {
                if ($currentWordCount + $paraWordCount > $chunkSize && $currentWordCount > 0) {
                    $chunkText = implode(' ', $currentChunk);
                    $chunks[] = $this->buildChunkItem($chunkIndex++, $chunkText);

                    // Keep overlap words
                    $overlapWords = array_slice($currentChunk, max(0, count($currentChunk) - $chunkOverlap));
                    $currentChunk = array_merge($overlapWords, $words);
                    $currentWordCount = count($currentChunk);
                } else {
                    $currentChunk = array_merge($currentChunk, $words);
                    $currentWordCount += $paraWordCount;
                }
            }
        }

        // Push final chunk if not empty
        if (!empty($currentChunk)) {
            $chunkText = implode(' ', $currentChunk);
            if (mb_strlen(trim($chunkText)) > 5) {
                $chunks[] = $this->buildChunkItem($chunkIndex++, $chunkText);
            }
        }

        return $chunks;
    }

    /**
     * Compute SHA-256 hash of entire text content to check idempotency.
     */
    public function computeHash(string $text): string
    {
        return hash('sha256', trim($text));
    }

    private function buildChunkItem(int $index, string $chunkText): array
    {
        $cleanText = trim($chunkText);
        return [
            'index' => $index,
            'text' => $cleanText,
            'hash' => hash('sha256', $cleanText),
            'token_count' => (int) ceil(mb_strlen($cleanText) / 4), // Approximate token estimation
        ];
    }
}
