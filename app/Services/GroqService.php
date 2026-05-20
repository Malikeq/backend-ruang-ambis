<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\AiCallLog;

/**
 * GroqService — Ultra-fast LLM inference via Groq Cloud.
 *
 * Uses the OpenAI-compatible Chat Completions endpoint.
 * Supports JSON mode via response_format: {type: "json_object"}.
 *
 * Docs: https://console.groq.com/docs/openai
 */
class GroqService
{
    private const BASE_URL = 'https://api.groq.com/openai/v1/chat/completions';

    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.groq.api_key');
        $this->model  = config('services.groq.model', 'llama-3.3-70b-versatile');

        if (empty($this->apiKey)) {
            throw new \RuntimeException('GROQ_API_KEY is not configured in .env');
        }
    }

    // ─── Public API ────────────────────────────────────────────────────────────

    public function generateFlash(string $prompt, ?int $userId = null, string $fitur = 'general'): array
    {
        return $this->chat($prompt, $userId, $fitur);
    }

    public function generatePro(string $prompt, ?int $userId = null, string $fitur = 'general'): array
    {
        return $this->chat($prompt, $userId, $fitur);
    }

    public function analyzeImage(string $imageBase64, string $mimeType, string $prompt, ?int $userId = null): array
    {
        // Groq vision via llama-3.2 models
        return $this->chatWithImage($imageBase64, $mimeType, $prompt, $userId);
    }

    public function parseJson(string $text): array
    {
        $clean   = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $clean   = preg_replace('/\s*```$/m', '', $clean ?? $text);
        $decoded = json_decode(trim($clean ?? $text), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('GroqService: invalid JSON — ' . json_last_error_msg());
        }

        return $decoded;
    }

    /** Groq is fast enough to handle 10 per batch reliably. */
    public function recommendedBatchSize(): int
    {
        return 10;
    }

    // ─── Core ──────────────────────────────────────────────────────────────────

    private function chat(string $prompt, ?int $userId, string $fitur): array
    {
        $payload = [
            'model'           => $this->model,
            'temperature'     => 0.7,
            'max_tokens'      => 8192,
            'response_format' => ['type' => 'json_object'], // JSON mode — no markdown
            'messages'        => [
                [
                    'role'    => 'system',
                    'content' => 'You are an expert Indonesian SNBT question generator. Output ONLY valid JSON. No markdown, no prose.',
                ],
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ],
        ];

        return $this->callApi($payload, $userId, $fitur);
    }

    private function chatWithImage(string $imageBase64, string $mimeType, string $prompt, ?int $userId): array
    {
        // Use llama-3.2-11b-vision for image analysis
        $visionModel = 'llama-3.2-11b-vision-preview';

        $payload = [
            'model'       => $visionModel,
            'temperature' => 0.7,
            'max_tokens'  => 4096,
            'messages'    => [
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'      => 'image_url',
                            'image_url' => ['url' => "data:{$mimeType};base64,{$imageBase64}"],
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ],
            ],
        ];

        return $this->callApi($payload, $userId, 'photo_solve');
    }

    private function callApi(array $payload, ?int $userId, string $fitur): array
    {
        $model = $payload['model'];

        Log::info("GroqService → {$model}", ['fitur' => $fitur]);

        $response = Http::timeout(60)
            ->withToken($this->apiKey)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post(self::BASE_URL, $payload);

        if (!$response->successful()) {
            $status = $response->status();
            $body   = $response->body();

            Log::error("GroqService: HTTP {$status}", ['body' => mb_substr($body, 0, 400)]);

            if ($status === 401) {
                throw new \RuntimeException('GroqService: Invalid API key. Check GROQ_API_KEY in .env.');
            }

            if ($status === 429) {
                $retryAfter = $response->header('retry-after') ?? '?';
                throw new \RuntimeException("GroqService: Rate limit hit. Retry after {$retryAfter}s.");
            }

            throw new \RuntimeException("GroqService HTTP {$status}: " . mb_substr($body, 0, 200));
        }

        $data     = $response->json();
        $text     = $data['choices'][0]['message']['content'] ?? '';
        $tokenIn  = $data['usage']['prompt_tokens']     ?? 0;
        $tokenOut = $data['usage']['completion_tokens'] ?? 0;

        if (empty(trim($text))) {
            throw new \RuntimeException('GroqService: model returned empty response.');
        }

        AiCallLog::create([
            'user_id'   => $userId,
            'fitur'     => $fitur,
            'model'     => $model,
            'token_in'  => $tokenIn,
            'token_out' => $tokenOut,
            'cost_idr'  => 0,
            'cached'    => false,
        ]);

        Log::info('GroqService: success', [
            'model'     => $model,
            'token_in'  => $tokenIn,
            'token_out' => $tokenOut,
        ]);

        return ['text' => $text, 'token_in' => $tokenIn, 'token_out' => $tokenOut];
    }
}
