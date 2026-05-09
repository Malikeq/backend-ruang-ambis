<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\WeaknessReport;
use App\Models\SesiLatihan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user()->load('kampusTargets.kampus', 'kampusTargets.jurusan');

        // Streak
        $streak = $user->streak_days;

        // Total attempts & accuracy
        $attempts    = $user->attempts()->selectRaw('COUNT(*) as total, SUM(is_correct) as benar')->first();
        $totalSoal   = $attempts->total ?? 0;
        $akurasiAll  = $totalSoal > 0 ? round(($attempts->benar / $totalSoal) * 100) : 0;

        // Sessions today
        $sesiHariIni = $user->sesiLatihan()->whereDate('created_at', today())->count();

        // Kelemahan kritis
        $kelemahan = WeaknessReport::with(['sub_materi', 'mapel'])
            ->where('user_id', $user->id)
            ->where('is_flagged', true)
            ->orderBy('accuracy_rate')
            ->limit(5)
            ->get();

        // Progress per mapel
        $progressPerMapel = WeaknessReport::with('mapel')
            ->where('user_id', $user->id)
            ->selectRaw('mapel_id, AVG(accuracy_rate) as akurasi, SUM(attempt_count) as attempt_count')
            ->groupBy('mapel_id')
            ->get()
            ->map(fn($r) => [
                'mapel'         => $r->mapel,
                'akurasi'       => round($r->akurasi, 1),
                'attempt_count' => $r->attempt_count,
            ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'user'                    => $user,
                'streak'                  => $streak,
                'points'                  => $user->points,
                'total_soal_dikerjakan'   => $totalSoal,
                'akurasi_overall'         => $akurasiAll,
                'sesi_hari_ini'           => $sesiHariIni,
                'target_harian_tercapai'  => $sesiHariIni >= 1,
                'kelemahan_kritis'        => $kelemahan,
                'progres_per_mapel'       => $progressPerMapel,
            ],
        ]);
    }

    public function streak(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['streak' => $request->user()->streak_days]]);
    }

    public function progress(Request $request): JsonResponse
    {
        $progress = WeaknessReport::with('mapel')
            ->where('user_id', $request->user()->id)
            ->get();

        return response()->json(['success' => true, 'data' => $progress]);
    }
}

// ─── WeaknessController ───────────────────────────────────────

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\WeaknessReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeaknessController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $reports = WeaknessReport::with(['sub_materi', 'mapel'])
            ->where('user_id', $request->user()->id)
            ->orderBy('accuracy_rate')
            ->get();

        return response()->json(['success' => true, 'data' => $reports]);
    }

    public function detail(Request $request, int $subMateriId): JsonResponse
    {
        $report = WeaknessReport::with(['sub_materi.mapel'])
            ->where('user_id', $request->user()->id)
            ->where('sub_materi_id', $subMateriId)
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $report]);
    }
}
