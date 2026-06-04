<?php

namespace App\Http\Controllers\Api\AI;

use App\Http\Controllers\Controller;
use App\Models\Soal;
use App\Services\AiExplanationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AiExplanationController extends Controller
{
    public function __construct(private AiExplanationService $service) {}

    /**
     * GET /ai/explanation/{soalId}
     * Returns cached or fresh DCSEF explanation.
     */
    public function getExplanation(Request $request, int $soalId): JsonResponse
    {
        $soal = Soal::with(['mapel', 'sub_materi', 'pilihan_jawaban'])->findOrFail($soalId);

        try {
            $analysis = $this->service->getExplanation($soal, $request->user()->id);
            return response()->json(['success' => true, 'data' => $analysis]);
        } catch (\RuntimeException $e) {
            $isQuota = str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), 'RESOURCE_EXHAUSTED');
            return response()->json([
                'success' => false,
                'message' => $isQuota
                    ? 'Kuota AI habis sementara. Coba lagi dalam 1 menit.'
                    : 'Gagal mengambil penjelasan AI. Coba lagi.',
                'retry_after' => $isQuota ? 60 : null,
            ], $isQuota ? 429 : 503);
        }
    }

    public function tanya(Request $request): JsonResponse
    {
        $data = $request->validate([
            'soal_id'    => ['nullable', 'integer'],
            'pertanyaan' => ['required', 'string', 'max:1000'],
        ]);

        // Load soal for context if a valid ID was provided (> 0)
        $soal = null;
        if (!empty($data['soal_id']) && $data['soal_id'] > 0) {
            $soal = Soal::with(['mapel', 'pilihan_jawaban'])->find($data['soal_id']);
        }

        try {
            $jawaban = $this->service->tanya($soal, $data['pertanyaan'], $request->user()->id);
            return response()->json(['success' => true, 'data' => ['jawaban' => $jawaban]]);
        } catch (\Exception $e) {
            $isQuota = str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), 'RESOURCE_EXHAUSTED');
            return response()->json([
                'success'     => false,
                'message'     => $isQuota
                    ? 'Kuota AI habis sementara. Coba lagi dalam 1 menit.'
                    : 'AI sedang sibuk. Coba lagi.',
                'retry_after' => $isQuota ? 60 : null,
            ], $isQuota ? 429 : 503);
        }
    }

    /**
     * GET /ai/quota?feature=tanya_ai
     * Returns daily AI usage for the authenticated user.
     */
    public function quota(Request $request): JsonResponse
    {
        $feature = $request->query('feature', 'tanya_ai');
        if (!in_array($feature, ['tanya_ai', 'photo_solve'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Feature tidak valid.',
            ], 422);
        }

        $user  = $request->user();
        $limit = $this->dailyLimit($user->tier, $feature);
        $key   = "ai:{$feature}:{$user->id}:" . now()->format('Y-m-d');
        $used  = RateLimiter::attempts($key);

        return response()->json([
            'success' => true,
            'data'    => [
                'feature'    => $feature,
                'tier'       => $user->tier,
                'has_access' => in_array($user->tier, ['premium', 'daily_pass'], true),
                'limit'      => $limit,
                'used'       => $used,
                'remaining'  => max(0, $limit - $used),
            ],
        ]);
    }

    private function dailyLimit(string $tier, string $feature): int
    {
        return match ($feature) {
            'tanya_ai'    => match ($tier) { 'premium' => 30, 'daily_pass' => 5, default => 0 },
            'photo_solve' => match ($tier) { 'premium' => 10, 'daily_pass' => 3, default => 0 },
            default       => 0,
        };
    }
}
