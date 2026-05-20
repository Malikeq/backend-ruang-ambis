<?php

namespace App\Services;

/**
 * AiService — Provider factory.
 *
 * Returns either GeminiService or OllamaService based on AI_PROVIDER in .env.
 * All methods proxy to the active provider, so the rest of the app
 * never needs to know which one is running.
 *
 * Usage in other services:
 *   public function __construct(private AiService $ai) {}
 *   $result = $this->ai->generateFlash($prompt, $userId, 'material_upload');
 *
 * Switch provider:
 *   AI_PROVIDER=gemini   → uses GeminiService (multi-key rotation)
 *   AI_PROVIDER=ollama   → uses OllamaService (local Ollama instance)
 */
class AiService
{
    private GeminiService|OllamaService|GroqService $provider;
    private string $providerName;

    public function __construct()
    {
        $this->providerName = strtolower(config('services.ai.provider', 'gemini'));

        $this->provider = match ($this->providerName) {
            'ollama' => app(OllamaService::class),
            'groq'   => app(GroqService::class),
            default  => app(GeminiService::class),
        };
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    public function generateFlash(string $prompt, ?int $userId = null, string $fitur = 'general'): array
    {
        return $this->provider->generateFlash($prompt, $userId, $fitur);
    }

    public function generatePro(string $prompt, ?int $userId = null, string $fitur = 'general'): array
    {
        return $this->provider->generatePro($prompt, $userId, $fitur);
    }

    public function analyzeImage(string $imageBase64, string $mimeType, string $prompt, ?int $userId = null): array
    {
        return $this->provider->analyzeImage($imageBase64, $mimeType, $prompt, $userId);
    }

    public function parseJson(string $text): array
    {
        return $this->provider->parseJson($text);
    }

    /** How many soal per AI call this provider can reliably handle. */
    public function recommendedBatchSize(): int
    {
        return method_exists($this->provider, 'recommendedBatchSize')
            ? $this->provider->recommendedBatchSize()
            : 10;
    }
}
