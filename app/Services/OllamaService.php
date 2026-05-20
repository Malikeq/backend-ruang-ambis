<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\AiCallLog;

/**
 * OllamaService — Local LLM via Ollama native API.
 *
 * Endpoint : POST /api/chat  (works on ALL Ollama versions)
 * JSON mode: "format":"json" forces valid JSON output — no markdown, no prose
 * No HTTP timeout — local models can take several minutes; PHP timeout is
 * already disabled via set_time_limit(0) in the afterResponse closures.
 */
class OllamaService
{
    private string $baseUrl;
    private string $model;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.ollama.url', 'http://localhost:11434'), '/');
        $this->model   = config('services.ollama.model', 'gemma4:latest');
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
        // Ollama multimodal (llava/gemma4) — send image inline
        return $this->chat($prompt, $userId, 'photo_solve', $imageBase64, $mimeType);
    }

    public function parseJson(string $text): array
    {
        // With format:json, output should already be clean JSON — strip fences just in case
        $clean   = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $clean   = preg_replace('/\s*```$/m', '', $clean ?? $text);
        $decoded = json_decode(trim($clean ?? $text), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('OllamaService: invalid JSON — ' . json_last_error_msg());
        }

        return $decoded;
    }

    /** Local models work best with smaller JSON arrays per call. */
    public function recommendedBatchSize(): int
    {
        return 5;
    }

    // ─── Core ──────────────────────────────────────────────────────────────────

    private function chat(
        string  $prompt,
        ?int    $userId,
        string  $fitur,
        ?string $imageBase64 = null,
        ?string $mimeType    = null
    ): array {
        $url = "{$this->baseUrl}/api/chat";

        // Build the user message — include image if provided (for vision models)
        $userMessage = ['role' => 'user', 'content' => $prompt];
        if ($imageBase64 && $mimeType) {
            $userMessage['images'] = [$imageBase64];
        }

        $payload = [
            'model'    => $this->model,
            'stream'   => false,
            'format'   => 'json',   // Forces model to output valid JSON only
            'messages' => [
                [
                    'role'    => 'system',
                    'content' => 'You are a JSON generator. Output ONLY valid JSON. No text before or after.',
                ],
                $userMessage,
            ],
            'options' => [
                'temperature' => 0.7,
                'num_predict' => 4096,
            ],
        ];

        Log::info("OllamaService → {$this->model}", [
            'url'   => $url,
            'fitur' => $fitur,
        ]);

        try {
            // timeout(0) = no timeout — model can take as long as it needs
            $response = Http::timeout(0)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, $payload);


            if (!$response->successful()) {
                $this->handleError($response->status(), $response->body());
            }

            $data     = $response->json();
            $text     = $data['message']['content'] ?? '';
            $tokenIn  = $data['prompt_eval_count']  ?? 0;
            $tokenOut = $data['eval_count']          ?? 0;

            if (empty(trim($text))) {
                throw new \RuntimeException('OllamaService: model returned empty content.');
            }

            AiCallLog::create([
                'user_id'   => $userId,
                'fitur'     => $fitur,
                'model'     => $this->model,
                'token_in'  => $tokenIn,
                'token_out' => $tokenOut,
                'cost_idr'  => 0,
                'cached'    => false,
            ]);

            Log::info("OllamaService: success", [
                'model'     => $this->model,
                'token_in'  => $tokenIn,
                'token_out' => $tokenOut,
            ]);

            return ['text' => $text, 'token_in' => $tokenIn, 'token_out' => $tokenOut];

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('OllamaService: connection failed', [
                'url'   => $url,
                'error' => $e->getMessage(),
                'hint'  => "Is Ollama running? → ollama serve  |  OLLAMA_HOST=0.0.0.0:11434",
            ]);
            throw $e;
        }
    }

    private function handleError(int $status, string $body): void
    {
        Log::error("OllamaService: HTTP {$status}", ['body' => mb_substr($body, 0, 400)]);

        if ($status === 404) {
            if (str_contains(strtolower($body), 'model') || str_contains($body, 'not found')) {
                throw new \RuntimeException(
                    "Model '{$this->model}' not found. Pull it: ollama pull {$this->model}\n" .
                    "Available models: {$this->baseUrl}/api/tags"
                );
            }
            throw new \RuntimeException(
                "Ollama 404 — wrong URL or model not installed.\n" .
                "URL: {$this->baseUrl}\nModel: {$this->model}"
            );
        }

        throw new \RuntimeException("OllamaService HTTP {$status}: " . mb_substr($body, 0, 200));
    }
}
