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

    /**
     * Generate content using Gemini Flash (cheaper / free tier).
     */
    public function generateFlash(string $prompt, ?int $userId = null, string $fitur = 'general'): array
    {
        return $this->generate($prompt, $this->modelFlash, $userId, $fitur);
    }

    /**
     * Generate content using Gemini Pro (higher quality).
     */
    public function generatePro(string $prompt, ?int $userId = null, string $fitur = 'general'): array
    {
        return $this->generate($prompt, $this->modelPro, $userId, $fitur);
    }

    /**
     * Analyze an image with Gemini Vision.
     */
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

    /**
     * Core API call with logging and error handling.
     */
    private function generate(string $prompt, string $model, ?int $userId, string $fitur): array
    {
        $payload = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => $this->generationConfig(),
        ];

        return $this->callApi($model, $payload, $userId, $fitur);
    }

    private function callApi(string $model, array $payload, ?int $userId, string $fitur): array
    {
        $url = "{$this->apiUrl}/models/{$model}:generateContent?key={$this->apiKey}";

        try {
            $response = Http::timeout(60)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, $payload);

            if (!$response->successful()) {
                Log::error('Gemini API error', ['status' => $response->status(), 'body' => $response->body()]);
                throw new \RuntimeException('Gemini API error: ' . $response->status());
            }

            $data = $response->json();
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $tokenIn  = $data['usageMetadata']['promptTokenCount'] ?? 0;
            $tokenOut = $data['usageMetadata']['candidatesTokenCount'] ?? 0;

            // Log AI call
            AiCallLog::create([
                'user_id'   => $userId,
                'fitur'     => $fitur,
                'model'     => $model,
                'token_in'  => $tokenIn,
                'token_out' => $tokenOut,
                'cost_idr'  => 0, // Free tier
                'cached'    => false,
            ]);

            return ['text' => $text, 'token_in' => $tokenIn, 'token_out' => $tokenOut];

        } catch (\Exception $e) {
            Log::error('GeminiService exception', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Parse JSON from AI response text, stripping markdown fences if present.
     */
    public function parseJson(string $text): array
    {
        // Strip ```json ... ``` fences
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $clean = preg_replace('/\s*```$/m', '', $clean ?? $text);

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
            'maxOutputTokens' => 4096,
        ];
    }
}
