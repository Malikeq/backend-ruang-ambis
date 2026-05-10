<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\AiCallLog;

class GeminiService
{
    private string $apiKey;
    private string $apiUrl;
    private string $modelFlash;
    private string $modelPro;

    public function __construct()
    {
        $this->apiKey    = config('services.gemini.api_key');
        $this->apiUrl    = config('services.gemini.api_url');
        $this->modelFlash = config('services.gemini.model_flash', 'gemini-1.5-flash');
        $this->modelPro   = config('services.gemini.model_pro', 'gemini-1.5-pro');
    }

    public function generateFlash(string $prompt, ?int $userId = null, string $fitur = 'general'): array
    {
        return $this->generate($prompt, $this->modelFlash, $userId, $fitur);
    }

    public function generatePro(string $prompt, ?int $userId = null, string $fitur = 'general'): array
    {
        return $this->generate($prompt, $this->modelPro, $userId, $fitur);
    }

    public function analyzeImage(string $imageBase64, string $mimeType, string $prompt, ?int $userId = null): array
    {
        $payload = [
            'contents' => [[
                'parts' => [
                    ['inline_data' => ['mime_type' => $mimeType, 'data' => $imageBase64]],
                    ['text' => $prompt],
                ],
            ]],
            'generationConfig' => $this->generationConfig(),
        ];

        return $this->callApi($this->modelFlash, $payload, $userId, 'photo_solve');
    }

    private function generate(string $prompt, string $model, ?int $userId, string $fitur): array
    {
        $payload = [
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => $this->generationConfig(),
        ];

        return $this->callApi($model, $payload, $userId, $fitur);
    }

    /**
     * Call Gemini API with automatic retry on 503/429/timeout.
     * Retries up to 3 times with increasing back-off (5s, 10s, 15s).
     */
    private function callApi(string $model, array $payload, ?int $userId, string $fitur): array
    {
        $url      = "{$this->apiUrl}/models/{$model}:generateContent?key={$this->apiKey}";
        $maxTries = 3;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxTries; $attempt++) {
            try {
                $response = Http::timeout(180)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post($url, $payload);

                // Retryable status codes
                if (in_array($response->status(), [429, 500, 503])) {
                    $wait = $attempt * 5;
                    Log::warning("Gemini {$response->status()} attempt {$attempt}/{$maxTries}, wait {$wait}s", [
                        'body' => mb_substr($response->body(), 0, 300),
                    ]);
                    if ($attempt < $maxTries) { sleep($wait); continue; }
                    throw new \RuntimeException("Gemini API error: {$response->status()}");
                }

                if (!$response->successful()) {
                    Log::error('Gemini API error', ['status' => $response->status(), 'body' => $response->body()]);
                    throw new \RuntimeException("Gemini API error: {$response->status()}");
                }

                $data     = $response->json();
                $text     = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
                $tokenIn  = $data['usageMetadata']['promptTokenCount']     ?? 0;
                $tokenOut = $data['usageMetadata']['candidatesTokenCount'] ?? 0;

                AiCallLog::create([
                    'user_id'   => $userId,
                    'fitur'     => $fitur,
                    'model'     => $model,
                    'token_in'  => $tokenIn,
                    'token_out' => $tokenOut,
                    'cost_idr'  => 0,
                    'cached'    => false,
                ]);

                return ['text' => $text, 'token_in' => $tokenIn, 'token_out' => $tokenOut];

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                // Network timeout — retry
                $lastError = $e;
                Log::warning("Gemini connection timeout attempt {$attempt}/{$maxTries}", ['msg' => $e->getMessage()]);
                if ($attempt < $maxTries) { sleep($attempt * 3); }
            } catch (\Exception $e) {
                $lastError = $e;
                Log::error("GeminiService exception attempt {$attempt}", ['message' => $e->getMessage()]);
                if ($attempt < $maxTries) { sleep($attempt * 2); }
            }
        }

        Log::error('GeminiService all retries exhausted', ['message' => $lastError?->getMessage()]);
        throw $lastError ?? new \RuntimeException('Gemini API failed after retries.');
    }

    /**
     * Parse JSON from AI response, stripping markdown fences.
     */
    public function parseJson(string $text): array
    {
        $clean   = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $clean   = preg_replace('/\s*```$/m', '', $clean ?? $text);
        $decoded = json_decode(trim($clean ?? $text), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('AI returned invalid JSON: ' . json_last_error_msg());
        }

        return $decoded;
    }

    private function generationConfig(): array
    {
        return [
            'temperature'     => 0.7,
            'topK'            => 40,
            'topP'            => 0.95,
            'maxOutputTokens' => 8192,
        ];
    }
}
