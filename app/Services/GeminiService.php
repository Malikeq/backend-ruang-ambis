<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\AiCallLog;

/**
 * GeminiService — Multi-key rotation with automatic quota failover.
 *
 * Strategy:
 *  1. On every call, pick the first non-exhausted key from the pool.
 *  2. On HTTP 429 (quota exceeded) → mark key as exhausted for 60 min, rotate immediately.
 *  3. On HTTP 500/503 → retry same key with back-off (5s, 10s, 15s).
 *  4. On network timeout → retry same key with back-off.
 *  5. After trying ALL keys and still failing → throw exception.
 *
 * Keys are stored in GEMINI_API_KEYS (comma-separated) in .env.
 * Exhausted-key state is stored in Laravel cache (file driver) so it persists
 * across requests but resets after 1 hour.
 */
class GeminiService
{
    /** @var string[] */
    private array  $apiKeys;
    private string $apiUrl;
    private string $modelFlash;
    private string $modelPro;

    /** How long (seconds) to skip an exhausted key before trying again */
    private const KEY_COOLDOWN_SECONDS = 3600; // 1 hour

    public function __construct()
    {
        $keys = config('services.gemini.api_keys', []);

        // Fallback to single key if the list is empty
        if (empty($keys)) {
            $single = config('services.gemini.api_key');
            $keys   = $single ? [$single] : [];
        }

        $this->apiKeys    = array_values($keys);
        $this->apiUrl     = config('services.gemini.api_url');
        $this->modelFlash = config('services.gemini.model_flash', 'gemini-1.5-flash');
        $this->modelPro   = config('services.gemini.model_pro',   'gemini-1.5-pro');

        if (empty($this->apiKeys)) {
            throw new \RuntimeException('No Gemini API keys configured. Set GEMINI_API_KEYS in .env.');
        }
    }

    // ─── Public API ────────────────────────────────────────────────────────────

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
            'contents'         => [['parts' => [
                ['inline_data' => ['mime_type' => $mimeType, 'data' => $imageBase64]],
                ['text'        => $prompt],
            ]]],
            'generationConfig' => $this->generationConfig(),
        ];

        return $this->callApiWithRotation($this->modelFlash, $payload, $userId, 'photo_solve');
    }

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

    // ─── Core rotation logic ───────────────────────────────────────────────────

    private function generate(string $prompt, string $model, ?int $userId, string $fitur): array
    {
        $payload = [
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => $this->generationConfig(),
        ];

        return $this->callApiWithRotation($model, $payload, $userId, $fitur);
    }

    /**
     * Try each API key in sequence. On 429, immediately rotate to the next key.
     * On 5xx / timeout, retry the same key up to 2 extra times before rotating.
     */
    private function callApiWithRotation(string $model, array $payload, ?int $userId, string $fitur): array
    {
        $activeKeys   = $this->getActiveKeys();
        $totalKeys    = count($activeKeys);

        if ($totalKeys === 0) {
            // All keys exhausted — reset and try everything again as last resort
            $this->resetExhaustedKeys();
            $activeKeys = $this->apiKeys;
            Log::warning('GeminiService: all keys were exhausted, resetting and retrying.');
        }

        $lastError = null;

        foreach ($activeKeys as $index => $apiKey) {
            $keyLabel = 'key#' . ($index + 1);

            // Retry the same key up to 2 times on server errors / timeouts
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                try {
                    $url = "{$this->apiUrl}/models/{$model}:generateContent?key={$apiKey}";

                    $response = Http::timeout(180)
                        ->withHeaders(['Content-Type' => 'application/json'])
                        ->post($url, $payload);

                    $status = $response->status();

                    // ── Quota exceeded → rotate immediately ──────────────────
                    if ($status === 429) {
                        $this->markKeyExhausted($apiKey);
                        Log::warning("GeminiService [$keyLabel]: 429 quota exceeded — rotating to next key.", [
                            'body' => mb_substr($response->body(), 0, 200),
                        ]);
                        break; // break inner loop, go to next key
                    }

                    // ── Transient server error → retry same key ──────────────
                    if (in_array($status, [500, 503])) {
                        $wait = $attempt * 5;
                        Log::warning("GeminiService [$keyLabel]: HTTP {$status} attempt {$attempt}/2, wait {$wait}s");
                        if ($attempt < 2) { sleep($wait); continue; }
                        $lastError = new \RuntimeException("Gemini {$status} after retries on {$keyLabel}");
                        break; // try next key
                    }

                    // ── Any other non-2xx ────────────────────────────────────
                    if (!$response->successful()) {
                        Log::error("GeminiService [$keyLabel]: HTTP {$status}", ['body' => mb_substr($response->body(), 0, 300)]);
                        $lastError = new \RuntimeException("Gemini HTTP {$status} on {$keyLabel}");
                        break; // try next key
                    }

                    // ── Success ──────────────────────────────────────────────
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

                    Log::info("GeminiService [$keyLabel]: success", [
                        'model'     => $model,
                        'token_in'  => $tokenIn,
                        'token_out' => $tokenOut,
                    ]);

                    return ['text' => $text, 'token_in' => $tokenIn, 'token_out' => $tokenOut];

                } catch (\Illuminate\Http\Client\ConnectionException $e) {
                    $lastError = $e;
                    Log::warning("GeminiService [$keyLabel]: timeout attempt {$attempt}/2", ['msg' => $e->getMessage()]);
                    if ($attempt < 2) { sleep($attempt * 3); }

                } catch (\Exception $e) {
                    $lastError = $e;
                    Log::error("GeminiService [$keyLabel]: exception attempt {$attempt}", ['msg' => $e->getMessage()]);
                    if ($attempt < 2) { sleep($attempt * 2); }
                }
            }
            // Move to next key
        }

        Log::error('GeminiService: all keys and retries exhausted.', [
            'last_error' => $lastError?->getMessage(),
            'total_keys' => $totalKeys,
        ]);

        throw $lastError ?? new \RuntimeException('Gemini API failed — all keys exhausted.');
    }

    // ─── Key pool helpers ──────────────────────────────────────────────────────

    /**
     * Return keys that have not been marked exhausted yet.
     * Exhausted state is stored in Laravel cache per key hash.
     */
    private function getActiveKeys(): array
    {
        return array_values(array_filter($this->apiKeys, function (string $key) {
            return !Cache::has($this->exhaustedCacheKey($key));
        }));
    }

    private function markKeyExhausted(string $key): void
    {
        $cacheKey = $this->exhaustedCacheKey($key);
        Cache::put($cacheKey, true, self::KEY_COOLDOWN_SECONDS);
        Log::warning('GeminiService: key marked exhausted for ' . self::KEY_COOLDOWN_SECONDS . 's', [
            'key_suffix' => substr($key, -6),
        ]);
    }

    private function resetExhaustedKeys(): void
    {
        foreach ($this->apiKeys as $key) {
            Cache::forget($this->exhaustedCacheKey($key));
        }
    }

    private function exhaustedCacheKey(string $apiKey): string
    {
        return 'gemini_key_exhausted_' . md5($apiKey);
    }

    // ─── Config ────────────────────────────────────────────────────────────────

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
