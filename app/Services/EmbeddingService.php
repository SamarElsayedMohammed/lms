<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChatbotVectorChunk;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbeddingService
{
    private const SEARCH_CANDIDATE_LIMIT = 200;

    /**
     * Generate vector embedding array for a string of text.
     */
    public function generateEmbedding(string $text): array
    {
        $text = trim($text);
        if (empty($text)) {
            return [];
        }

        $provider = (string) config('services.ai.provider', 'gemini');

        // OpenAI embedding
        if ($provider === 'openai') {
            $apiKey = \App\Services\CachingService::getSystemSettings('openai_api_key') ?: config('services.openai.api_key');
            if (!empty($apiKey)) {
                try {
                    $response = Http::withToken($apiKey)->timeout(15)->post('https://api.openai.com/v1/embeddings', [
                        'model' => 'text-embedding-3-small',
                        'input' => $text,
                    ]);

                    if ($response->successful()) {
                        $data = $response->json();
                        return $data['data'][0]['embedding'] ?? [];
                    }
                } catch (\Throwable $e) {
                    Log::warning('OpenAI Embedding Error: ' . $e->getMessage());
                }
            }
        }

        // Gemini embedding
        $geminiKey = config('services.gemini.api_key');
        if (!empty($geminiKey)) {
            try {
                $url = "https://generativelanguage.googleapis.com/v1beta/models/text-embedding-004:embedContent?key={$geminiKey}";
                $response = Http::timeout(15)->post($url, [
                    'model' => 'models/text-embedding-004',
                    'content' => [
                        'parts' => [
                            ['text' => $text],
                        ],
                    ],
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    return $data['embedding']['values'] ?? [];
                }
            } catch (\Throwable $e) {
                Log::warning('Gemini Embedding Error: ' . $e->getMessage());
            }
        }

        // Fallback pseudo-vector for local/test mode without API keys
        return $this->generateFallbackVector($text);
    }

    /**
     * Search relevant vector chunks using Cosine Similarity with MANDATORY scope isolation.
     *
     * @param string $query Query string
     * @param string $botType 'visitor', 'subscriber', 'course'
     * @param int|null $courseId Course ID required when botType is 'course'
     * @param int $topK Max chunks to return
     * @return array Matches array of ['chunk' => ChatbotVectorChunk, 'score' => float]
     */
    public function searchSimilarChunks(string $query, string $botType = 'course', ?int $courseId = null, int $topK = 5): array
    {
        $queryEmbedding = $this->generateEmbedding($query);

        // Strict scope isolation filter query
        $chunksQuery = ChatbotVectorChunk::active()
            ->where('bot_type', $botType);

        if ($botType === 'course') {
            if (!$courseId) {
                return []; // Never run course retrieval without course_id!
            }
            $chunksQuery->where('course_id', $courseId);
        } else {
            $chunksQuery->whereNull('course_id');
        }

        $keywords = array_values(array_filter(
            preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [],
            static fn (string $word): bool => mb_strlen($word) > 2
        ));
        $unfilteredQuery = clone $chunksQuery;
        if ($keywords !== []) {
            $chunksQuery->where(function ($filter) use ($keywords): void {
                foreach (array_slice($keywords, 0, 5) as $keyword) {
                    $filter->orWhere('chunk_text', 'like', '%'.addcslashes($keyword, '%_\\').'%');
                }
            });
        }

        $select = ['id', 'title', 'chunk_text', 'embedding'];
        $chunks = $chunksQuery->select($select)->limit(self::SEARCH_CANDIDATE_LIMIT)->get();
        if ($chunks->isEmpty() && $keywords !== []) {
            $chunks = $unfilteredQuery->select($select)->limit(self::SEARCH_CANDIDATE_LIMIT)->get();
        }
        if ($chunks->isEmpty()) {
            return [];
        }

        $results = [];

        foreach ($chunks as $chunk) {
            $vector = $chunk->embedding;
            $score = 0.0;

            if (is_array($vector) && !empty($vector) && !empty($queryEmbedding)) {
                $score = $this->cosineSimilarity($queryEmbedding, $vector);
            } else {
                // Keyword relevance fallback matching when vector embeddings are null
                $score = $this->textMatchScore($query, $chunk->chunk_text);
            }

            if ($score > 0.1) {
                $results[] = [
                    'chunk' => $chunk,
                    'score' => $score,
                    'title' => $chunk->title,
                    'text' => $chunk->chunk_text,
                ];
            }
        }

        // Sort descending by similarity score
        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $topK);
    }

    /**
     * Calculate cosine similarity between two vector arrays.
     */
    public function cosineSimilarity(array $vecA, array $vecB): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $minLen = min(count($vecA), count($vecB));

        if ($minLen === 0) {
            return 0.0;
        }

        for ($i = 0; $i < $minLen; $i++) {
            $dotProduct += $vecA[$i] * $vecB[$i];
            $normA += $vecA[$i] * $vecA[$i];
            $normB += $vecB[$i] * $vecB[$i];
        }

        if ($normA <= 0 || $normB <= 0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Text keyword matching fallback for score calculation.
     */
    private function textMatchScore(string $query, string $text): float
    {
        $queryWords = array_unique(array_filter(preg_split('/\s+/u', mb_strtolower($query))));
        if (empty($queryWords)) {
            return 0.0;
        }

        $textLower = mb_strtolower($text);
        $matched = 0;

        foreach ($queryWords as $word) {
            if (mb_strlen($word) > 2 && str_contains($textLower, $word)) {
                $matched++;
            }
        }

        return $matched / count($queryWords);
    }

    /**
     * Generate fallback feature vector for offline environments.
     */
    private function generateFallbackVector(string $text): array
    {
        $hash = md5($text);
        $vector = [];
        for ($i = 0; $i < 32; $i++) {
            $vector[] = (hexdec(substr($hash, $i % 32, 1)) / 15.0) - 0.5;
        }
        return $vector;
    }
}
