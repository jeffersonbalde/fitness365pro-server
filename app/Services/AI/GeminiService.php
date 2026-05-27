<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private string $apiKey;
    private string $model;
    private int $timeoutSeconds;

    public function __construct()
    {
        $this->apiKey = (string) config('services.gemini.api_key');
        
        // FORCE to use gemini-1.5-flash (base name) for v1 API
        // The v1 API does NOT support version suffixes like -001 or -latest
        $this->model = 'gemini-1.5-flash'; // Hardcoded to ensure it's always correct
        
        $this->timeoutSeconds = (int) config('services.gemini.timeout', 120); // Increased to 120 seconds

        if ($this->apiKey === '') {
            throw new \RuntimeException('Gemini API key not configured. Set GEMINI_API_KEY in your .env');
        }
        
        Log::info('GeminiService initialized', [
            'model' => $this->model,
            'timeout' => $this->timeoutSeconds,
        ]);
    }

    /**
     * Generate JSON output from Gemini.
     *
     * NOTE: We pass a strong instruction to return ONLY valid JSON.
     */
    public function generateJson(string $prompt, float $temperature = 0.7, int $maxOutputTokens = 2048): array
    {
        $text = $this->generateText($prompt, $temperature, $maxOutputTokens);
        return $this->parseJson($text);
    }

    /**
     * Calls Google Generative Language API (Gemini).
     *
     * Endpoint (v1):
     * POST https://generativelanguage.googleapis.com/v1/models/{model}:generateContent?key=API_KEY
     */
    public function generateText(string $prompt, float $temperature = 0.7, int $maxOutputTokens = 2048): string
    {
        return $this->generateTextViaUrl($prompt, $temperature, $maxOutputTokens);
    }

    private function generateTextViaUrl(string $prompt, float $temperature, int $maxOutputTokens, bool $isRetry = false): string
    {
        // HARDCODE to gemini-1.5-flash (v1 API requires base name without suffix)
        // DO NOT use any version suffix (-001, -latest, -002, etc.)
        $modelToUse = 'gemini-1.5-flash';
        
        // Double-check: remove any accidental suffix
        $modelToUse = preg_replace('/-(latest|001|002|003|004|005)$/', '', $modelToUse);
        if ($modelToUse !== 'gemini-1.5-flash') {
            $modelToUse = 'gemini-1.5-flash'; // Force it
        }
        
        // Use v1 API endpoint (not v1beta)
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1/models/%s:generateContent?key=%s',
            rawurlencode($modelToUse),
            rawurlencode($this->apiKey)
        );
        
        // Log the exact URL being used (without API key)
        Log::info('Gemini API URL constructed', [
            'model' => $modelToUse,
            'url_model_part' => rawurlencode($modelToUse),
            'full_url' => str_replace($this->apiKey, '***', $url),
        ]);

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'text' => $this->wrapJsonOnlyInstruction($prompt),
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => $temperature,
                'max_output_tokens' => $maxOutputTokens,
            ],
        ];

        Log::info('Calling Gemini API', [
            'url' => str_replace($this->apiKey, '***', $url),
            'model' => $modelToUse,
        ]);

        $response = Http::timeout($this->timeoutSeconds)
            ->acceptJson()
            ->asJson()
            ->post($url, $payload);

        if ($response->failed()) {
            $errorBody = $response->body();
            
            Log::error('Gemini API failed', [
                'status' => $response->status(),
                'error' => $errorBody,
                'model' => $modelToUse,
            ]);
            
            // If model not found, try alternative model names
            if ($response->status() === 404 && str_contains($errorBody, 'not found') && !$isRetry) {
                $alternativeModels = ['gemini-pro', 'gemini-1.5-pro', 'gemini-1.5-flash'];
                
                foreach ($alternativeModels as $altModel) {
                    if ($altModel !== $modelToUse) {
                        Log::info('Trying alternative model', ['fallback' => $altModel, 'original' => $modelToUse]);
                        $this->model = $altModel;
                        try {
                            return $this->generateTextViaUrl($prompt, $temperature, $maxOutputTokens, true);
                        } catch (\Exception $e) {
                            // Continue to next alternative
                            Log::warning('Alternative model failed', ['model' => $altModel, 'error' => $e->getMessage()]);
                            continue;
                        }
                    }
                }
            }
            
            throw new \RuntimeException('Gemini API error: ' . $errorBody);
        }

        $data = $response->json();
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!is_string($text) || $text === '') {
            throw new \RuntimeException('Gemini API returned empty content');
        }

        return $text;
    }

    private function wrapJsonOnlyInstruction(string $prompt): string
    {
        return "Return ONLY valid JSON. Do not include markdown fences, explanations, or extra text.\n\n" . $prompt;
    }

    private function parseJson(string $text): array
    {
        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Fallback: try to extract JSON from a code block / surrounding text
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $m)) {
            $decoded = json_decode($m[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        throw new \RuntimeException('Gemini response was not valid JSON');
    }
}


