<?php

namespace App\Http\Controllers\Api\Latihan;

use App\Http\Controllers\Controller;
use App\Models\Soal;
use App\Models\Mapel;
use App\Models\SubMateri;
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
            'tipe'           => ['required', 'in:harian,ujian,diagnostic'],
            'mode'           => ['nullable', 'in:acak,kelemahan,per_bab,tryout'],
            'mapel_ids'      => ['nullable', 'array'],
            'sub_materi_ids' => ['nullable', 'array'],
            'jumlah_soal'    => ['nullable', 'integer', 'min:5', 'max:50'],
            'timer_menit'    => ['nullable', 'integer', 'min:5', 'max:180'],
        ]);

        $user   = $request->user();
        $mode   = $data['mode'] ?? 'acak';
        $limit  = $data['jumlah_soal'] ?? ($user->isFree() ? 20 : 40);
        $query  = Soal::where('is_published', true);

        // Filter by mapel
        if (!empty($data['mapel_ids'])) {
            $query->whereIn('mapel_id', $data['mapel_ids']);
        }

        // Filter by sub-materi (Per Bab mode)
        if (!empty($data['sub_materi_ids'])) {
            $query->whereIn('sub_materi_id', $data['sub_materi_ids']);
        }

        // Fokus Kelemahan: prioritize soal from weak areas
        if ($mode === 'kelemahan') {
            $weakMapelIds = DB::table('weakness_reports')
                ->where('user_id', $user->id)
                ->where('accuracy_rate', '<', 70)
                ->orderByRaw('attempt_count - correct_count DESC')
                ->limit(3)
                ->pluck('mapel_id')
                ->toArray();

            if (!empty($weakMapelIds)) {
                // 70% from weak areas, 30% random
                $weakLimit  = (int) ceil($limit * 0.7);
                $randLimit  = $limit - $weakLimit;

                $weakIds = (clone $query)->whereIn('mapel_id', $weakMapelIds)
                    ->inRandomOrder()->limit($weakLimit)->pluck('id')->toArray();
                $randIds = (clone $query)->whereNotIn('id', $weakIds)
                    ->inRandomOrder()->limit($randLimit)->pluck('id')->toArray();

                $soalIds = collect(array_merge($weakIds, $randIds))->shuffle()->toArray();
            } else {
                $soalIds = $query->inRandomOrder()->limit($limit)->pluck('id')->toArray();
            }
        } else {
            $soalIds = $query->inRandomOrder()->limit($limit)->pluck('id')->toArray();
        }

        if (empty($soalIds)) {
            return response()->json(['success' => false, 'message' => 'Bank soal masih kosong untuk pilihan ini.'], 404);
        }

        // Guard: if we got fewer soal than requested, inform the user instead of silently giving less
        $got      = count($soalIds);
        $wanted   = $limit;
        if ($got < $wanted) {
            return response()->json([
                'success' => false,
                'message' => "Bank soal hanya memiliki {$got} soal untuk pilihan ini, sedangkan kamu memilih {$wanted}. Mohon kurangi jumlah soal atau tambah soal melalui upload materi.",
                'available' => $got,
                'requested' => $wanted,
            ], 422);
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
     * GET /sub-materi — List sub-materi with soal counts.
     * Only returns sub-materi that have at least 1 published soal.
     */
    public function subMateri(Request $request): JsonResponse
    {
        $query = SubMateri::select('sub_materi.id', 'sub_materi.mapel_id', 'sub_materi.nama')
            ->selectRaw('COUNT(soal.id) as soal_count')
            ->join('soal', function ($join) {
                $join->on('soal.sub_materi_id', '=', 'sub_materi.id')
                     ->where('soal.is_published', true);
            })
            ->groupBy('sub_materi.id', 'sub_materi.mapel_id', 'sub_materi.nama')
            ->having('soal_count', '>=', 1)
            ->orderBy('sub_materi.nama');

        if ($request->filled('mapel_id')) {
            $query->where('sub_materi.mapel_id', (int) $request->mapel_id);
        }

        return response()->json([
            'success' => true,
            'data'    => $query->get(),
        ]);
    }


    /**
     * GET /mapel — List all mapel with real IDs (for latihan setup).
     */
    public function mapelList(): JsonResponse
    {
        $mapels = Mapel::select('id', 'kode', 'nama')->orderBy('id')->get();
        return response()->json(['success' => true, 'data' => $mapels]);
    }

    /**
     * GET /latihan/riwayat — Paginated session history for the current user.
     */
    public function riwayat(Request $request): JsonResponse
    {
        $userId  = $request->user()->id;
        $perPage = min((int) ($request->per_page ?? 20), 50);

        // Load completed sessions (selesai IS a timestamp, null = in-progress)
        $paginator = SesiLatihan::where('user_id', $userId)
            ->whereNotNull('selesai')
            ->select(['id', 'tipe', 'soal_ids', 'mulai', 'selesai', 'skor_raw', 'skor_akhir', 'created_at'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Collect all soal IDs needed to resolve mapel names in one query
        $allFirstSoalIds = $paginator->getCollection()
            ->map(fn($s) => is_array($s->soal_ids) ? $s->soal_ids[0] ?? null : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $mapelBySoalId = [];
        if (count($allFirstSoalIds)) {
            $rows = DB::table('soal')
                ->join('mapel', 'mapel.id', '=', 'soal.mapel_id')
                ->whereIn('soal.id', $allFirstSoalIds)
                ->select('soal.id as soal_id', 'mapel.kode', 'mapel.nama')
                ->get();
            foreach ($rows as $r) {
                $mapelBySoalId[$r->soal_id] = ['kode' => $r->kode, 'nama' => $r->nama];
            }
        }

        // Count total_benar per session in a single query
        $sesiIds = $paginator->getCollection()->pluck('id')->all();
        $benarMap = [];
        if (count($sesiIds)) {
            $rows = DB::table('user_attempts')
                ->whereIn('sesi_latihan_id', $sesiIds)
                ->where('is_correct', true)
                ->selectRaw('sesi_latihan_id, COUNT(*) as cnt')
                ->groupBy('sesi_latihan_id')
                ->pluck('cnt', 'sesi_latihan_id');
            $benarMap = $rows->all();
        }

        $items = $paginator->getCollection()->map(function ($s) use ($mapelBySoalId, $benarMap) {
            $firstSoalId = is_array($s->soal_ids) ? ($s->soal_ids[0] ?? null) : null;
            $mapel       = $firstSoalId ? ($mapelBySoalId[$firstSoalId] ?? null) : null;
            $totalSoal   = is_array($s->soal_ids) ? count($s->soal_ids) : 0;
            $totalBenar  = (int) ($benarMap[$s->id] ?? 0);
            $skor        = (int) round($s->skor_raw ?? 0);
            $snbt        = (int) round($s->skor_akhir ?? (400 + ($skor / 100) * 400));
            $durasi      = $s->mulai && $s->selesai
                           ? (int) $s->selesai->diffInSeconds($s->mulai)
                           : null;

            return [
                'id'           => $s->id,
                'mapel_kode'   => $mapel['kode']  ?? '—',
                'mapel_nama'   => $mapel['nama']  ?? 'Campuran',
                'tipe'         => $s->tipe ?? 'harian',
                'skor_raw'     => $skor,
                'skor_akhir'   => $snbt,
                'total_soal'   => $totalSoal,
                'total_benar'  => $totalBenar,
                'durasi_detik' => $durasi,
                'tanggal'      => $s->created_at->toISOString(),
            ];
        });

        return response()->json([
            'success'      => true,
            'data'         => $items,
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'total'        => $paginator->total(),
        ]);
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

        $soal = Soal::with(['mapel', 'sub_materi', 'pilihan_jawaban', 'pembahasan'])
            ->withCount('aiExplanation')
            ->findOrFail($soalIds[$index]);

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
                // Uses withCount eager load — no extra query
                'has_ai_explanation'=> $soal->ai_explanation_count > 0,
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

        // SNBT-scale score: 400 base + up to 400 points from accuracy
        $skorAkhir = round(400 + ($skorRaw / 100) * 400, 2);

        $sesi->update([
            'selesai'    => now(),
            'skor_raw'   => $skorRaw,
            'skor_akhir' => $skorAkhir,
        ]);

        // Reward for completing session
        $request->user()->addPoints(15, 'Menyelesaikan sesi latihan');

        return response()->json([
            'success' => true,
            'data'    => [
                'skor_raw'    => $skorRaw,
                'skor_akhir'  => $skorAkhir,
                'total_benar' => $benar,
                'total_soal'  => $total,
            ],
        ]);
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
