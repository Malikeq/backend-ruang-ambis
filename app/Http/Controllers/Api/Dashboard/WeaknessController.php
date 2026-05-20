<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\WeaknessReport;
use App\Models\UserAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeaknessController extends Controller
{
    /**
     * GET /weakness — List all flagged weakness reports for the user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $reports = WeaknessReport::with(['sub_materi', 'mapel'])
            ->where('user_id', $user->id)
            ->orderBy('accuracy_rate')
            ->orderBy('attempt_count', 'desc')
            ->limit(20)
            ->get()
            ->map(fn($r) => [
                'id'             => $r->id,
                'sub_materi'     => $r->sub_materi,
                'mapel'          => $r->mapel,
                'attempt_count'  => $r->attempt_count,
                'correct_count'  => $r->correct_count,
                'accuracy_rate'  => round((float) $r->accuracy_rate, 1),
                // Aliases used by the mobile app
                'rata_rata_skor' => round((float) $r->accuracy_rate, 1),
                'total_sesi'     => (int) $r->attempt_count,
                'is_flagged'     => $r->is_flagged,
                'last_seen'      => $r->last_seen,
            ]);

        return response()->json(['success' => true, 'data' => $reports]);
    }

    /**
     * GET /weakness/{id} — Detail kelemahan satu sub-materi + soal terkait.
     */
    public function detail(Request $request, int $id): JsonResponse
    {
        $user   = $request->user();
        $report = WeaknessReport::with(['sub_materi', 'mapel'])
            ->where('user_id', $user->id)
            ->findOrFail($id);

        // Last 5 attempts on this sub-materi
        $recentAttempts = UserAttempt::with(['soal:id,konten', 'jawaban:id,label,konten,is_correct'])
            ->where('user_id', $user->id)
            ->whereHas('soal', fn($q) => $q->where('sub_materi_id', $report->sub_materi_id))
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn($a) => [
                'soal_id'    => $a->soal_id,
                'soal'       => $a->soal?->konten,
                'is_correct' => $a->is_correct,
                'waktu_ms'   => $a->waktu_ms,
                'created_at' => $a->created_at,
            ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'report'         => [
                    'id'            => $report->id,
                    'sub_materi'    => $report->sub_materi,
                    'mapel'         => $report->mapel,
                    'attempt_count' => $report->attempt_count,
                    'correct_count' => $report->correct_count,
                    'accuracy_rate' => round($report->accuracy_rate, 1),
                    'is_flagged'    => $report->is_flagged,
                    'last_seen'     => $report->last_seen,
                ],
                'recent_attempts' => $recentAttempts,
                'rekomendasi'     => $this->getRekomendasi($report->accuracy_rate),
            ],
        ]);
    }

    private function getRekomendasi(float $accuracy): string
    {
        return match (true) {
            $accuracy < 30  => 'Fokus memahami konsep dasar sub-materi ini dari awal.',
            $accuracy < 50  => 'Perbanyak latihan soal dan perhatikan pembahasan AI.',
            $accuracy < 70  => 'Sudah cukup baik, tingkatkan kecepatan dan variasi soal.',
            default         => 'Sub-materi ini sudah dikuasai. Pertahankan!',
        };
    }
}
