<?php

namespace App\Http\Controllers\Api\AI;

use App\Http\Controllers\Controller;
use App\Models\Soal;
use App\Services\AiExplanationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'soal_id'    => ['required', 'integer', 'exists:soal,id'],
            'pertanyaan' => ['required', 'string', 'max:500'],
        ]);

        $soal = Soal::with(['mapel', 'pilihan_jawaban'])->find($data['soal_id']);

        try {
            $jawaban = $this->service->tanya($soal, $data['pertanyaan'], $request->user()->id);
            return response()->json(['success' => true, 'data' => ['jawaban' => $jawaban]]);
        } catch (\Exception $e) {
            $isQuota = str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), 'RESOURCE_EXHAUSTED');
            return response()->json([
                'success' => false,
                'message' => $isQuota
                    ? 'Kuota AI habis sementara. Coba lagi dalam 1 menit.'
                    : 'AI sedang sibuk. Coba lagi.',
                'retry_after' => $isQuota ? 60 : null,
            ], $isQuota ? 429 : 503);
        }
    }
}
