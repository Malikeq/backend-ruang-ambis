<?php

namespace App\Http\Controllers\Api\Latihan;

use App\Http\Controllers\Controller;
use App\Models\Soal;
use App\Models\SesiLatihan;
use App\Models\UserAttempt;
use App\Services\AiExplanationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LatihanController extends Controller
{
    public function __construct(private AiExplanationService $aiService) {}

    /**
     * POST /latihan/mulai — Start a new practice session.
     */
    public function mulai(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipe'      => ['required', 'in:harian,ujian,diagnostic'],
            'mapel_ids' => ['nullable', 'array'],
        ]);

        $user     = $request->user();
        $limit    = $user->isFree() ? 20 : 40;
        $query    = Soal::where('is_published', true);

        if (!empty($data['mapel_ids'])) {
            $query->whereIn('mapel_id', $data['mapel_ids']);
        }

        $soalIds = $query->inRandomOrder()->limit($limit)->pluck('id')->toArray();

        if (empty($soalIds)) {
            return response()->json(['success' => false, 'message' => 'Bank soal masih kosong.'], 404);
        }

        $sesi = SesiLatihan::create([
            'user_id'  => $user->id,
            'tipe'     => $data['tipe'],
            'soal_ids' => $soalIds,
            'mulai'    => now(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => ['id' => $sesi->id, 'total_soal' => count($soalIds), 'soal_ids' => $soalIds],
        ], 201);
    }

    /**
     * GET /latihan/{sesi}/soal/{index} — Get soal at index.
     */
    public function getSoal(Request $request, int $sesiId, int $index): JsonResponse
    {
        $sesi = SesiLatihan::where('id', $sesiId)->where('user_id', $request->user()->id)->firstOrFail();

        $soalIds = $sesi->soal_ids;
        if ($index < 0 || $index >= count($soalIds)) {
            return response()->json(['success' => false, 'message' => 'Index soal tidak valid.'], 404);
        }

        $soal = Soal::with(['mapel', 'sub_materi', 'pilihan_jawaban', 'pembahasan'])->findOrFail($soalIds[$index]);

        // Shuffle options, hide is_correct
        $options = $soal->pilihan_jawaban->map(fn($p) => [
            'id'     => $p->id,
            'label'  => $p->label,
            'konten' => $p->konten,
        ]);

        // Include pembahasan text if exists (don't reveal correct answer through it pre-answer)
        $pembahasanTeks = $soal->pembahasan?->konten ?? null;

        return response()->json([
            'success' => true,
            'data'    => [
                'id'                => $soal->id,
                'konten'            => $soal->konten,
                'tipe'              => $soal->tipe,
                'tingkat_kesulitan' => $soal->tingkat_kesulitan,
                'is_ai_generated'   => $soal->is_ai_generated,
                'mapel'             => $soal->mapel,
                'sub_materi'        => $soal->sub_materi,
                'pilihan_jawaban'   => $options,
                'pembahasan'        => $pembahasanTeks,
                'has_ai_explanation'=> $soal->aiExplanation()->exists(),
            ],
        ]);
    }

    /**
     * POST /latihan/{sesi}/jawab — Submit an answer.
     */
    public function jawab(Request $request, int $sesiId): JsonResponse
    {
        $sesi = SesiLatihan::where('id', $sesiId)->where('user_id', $request->user()->id)->firstOrFail();

        $data = $request->validate([
            'soal_id'    => ['required', 'integer', 'exists:soal,id'],
            'jawaban_id' => ['nullable', 'integer'],
            'waktu_ms'   => ['required', 'integer', 'min:0'],
        ]);

        $soal      = Soal::with('pilihan_jawaban')->findOrFail($data['soal_id']);
        $isCorrect = false;
        $correctId = null;

        if ($data['jawaban_id']) {
            $pilihan   = $soal->pilihan_jawaban->find($data['jawaban_id']);
            $isCorrect = $pilihan?->is_correct ?? false;
        }

        $correctId = $soal->pilihan_jawaban->firstWhere('is_correct', true)?->id;

        // Record attempt
        UserAttempt::create([
            'user_id'          => $request->user()->id,
            'soal_id'          => $data['soal_id'],
            'sesi_latihan_id'  => $sesiId,
            'jawaban_id'       => $data['jawaban_id'],
            'is_correct'       => $isCorrect,
            'waktu_ms'         => $data['waktu_ms'],
        ]);

        // Update weakness
        $this->aiService->updateWeakness($soal, $request->user()->id, $isCorrect);

        // Award points
        if ($isCorrect) {
            $request->user()->addPoints(10, 'Jawaban benar');
        }

        return response()->json([
            'success'    => true,
            'data'       => [
                'is_correct' => $isCorrect,
                'correct_id' => $correctId,
                'pilihan_jawaban' => $soal->pilihan_jawaban->map(fn($p) => [
                    'id' => $p->id, 'label' => $p->label, 'konten' => $p->konten, 'is_correct' => $p->is_correct,
                ]),
            ],
        ]);
    }

    /**
     * POST /latihan/{sesi}/selesai — Mark session as finished.
     */
    public function selesai(Request $request, int $sesiId): JsonResponse
    {
        $sesi = SesiLatihan::where('id', $sesiId)->where('user_id', $request->user()->id)->firstOrFail();

        $attempts  = UserAttempt::where('sesi_latihan_id', $sesiId)->get();
        $total     = count($sesi->soal_ids);
        $benar     = $attempts->where('is_correct', true)->count();
        $skorRaw   = $total > 0 ? ($benar / $total) * 100 : 0;

        $sesi->update(['selesai' => now(), 'skor_raw' => $skorRaw]);

        // Reward for completing session
        $request->user()->addPoints(15, 'Menyelesaikan sesi latihan');

        return response()->json(['success' => true, 'data' => ['skor_raw' => $skorRaw]]);
    }

    /**
     * GET /latihan/{sesi}/hasil — Get session results.
     */
    public function hasil(Request $request, int $sesiId): JsonResponse
    {
        $sesi     = SesiLatihan::where('id', $sesiId)->where('user_id', $request->user()->id)->firstOrFail();
        $attempts = UserAttempt::with(['soal.mapel'])->where('sesi_latihan_id', $sesiId)->get();

        $total = count($sesi->soal_ids);
        $benar = $attempts->where('is_correct', true)->count();

        // Group by mapel
        $perMapel = $attempts->groupBy('soal.mapel_id')->map(function ($group) {
            $mapel = $group->first()->soal->mapel;
            $tot   = $group->count();
            $bnr   = $group->where('is_correct', true)->count();
            return [
                'mapel'   => $mapel,
                'benar'   => $bnr,
                'total'   => $tot,
                'akurasi' => $tot > 0 ? round(($bnr / $tot) * 100) : 0,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'sesi'        => $sesi,
                'skor_raw'    => $sesi->skor_raw,
                'total_benar' => $benar,
                'total_soal'  => $total,
                'per_mapel'   => $perMapel,
            ],
        ]);
    }
}
