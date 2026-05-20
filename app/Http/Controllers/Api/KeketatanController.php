<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProdiStatistik;
use App\Models\UserPeluangLolos;
use App\Models\UserKampusTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class KeketatanController extends Controller
{
    /**
     * GET /api/v1/user/peluang-lolos
     */
    public function indexUserPeluang(): JsonResponse
    {
        $user    = Auth::user();
        $targets = UserKampusTarget::with(['kampus', 'jurusan'])
            ->where('user_id', $user->id)
            ->orderBy('priority')
            ->get();

        $skorUser = $this->getSkorUser($user->id);

        $results = $targets->map(fn($t) => $this->hitungPeluang($t, $skorUser));

        return response()->json([
            'skor_user' => $skorUser,
            'data'      => $results,
        ]);
    }

    /**
     * GET /api/v1/prodi/{kode}/statistik
     */
    public function prodiDetail(string $kode): JsonResponse
    {
        $stat = ProdiStatistik::where('kode_prodi', $kode)
                              ->orderByDesc('tahun')
                              ->first();

        if (!$stat) {
            return response()->json(['message' => 'Data tidak ditemukan'], 404);
        }

        return response()->json(['data' => $stat]);
    }

    /**
     * GET /api/v1/prodi/cari?q=teknik+informatika
     */
    public function cariProdi(Request $request): JsonResponse
    {
        $q    = $request->query('q', '');
        $ptn  = $request->query('ptn', '');

        $query = ProdiStatistik::orderByDesc('tahun')->orderBy('keketatan_persen');

        if ($q)   $query->where('nama_prodi', 'LIKE', "%{$q}%");
        if ($ptn) $query->where('nama_ptn',   'LIKE', "%{$ptn}%");

        $data = $query->limit(20)->get([
            'kode_prodi','nama_prodi','nama_ptn','keketatan_persen',
            'kategori_keketatan','skor_aman','skor_kuning',
            'kuota_snbt','peminat_snbt','rerata_skor_diterima','tahun',
        ]);

        return response()->json(['data' => $data]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function hitungPeluang(UserKampusTarget $target, float $skorUser): array
    {
        $stat = ProdiStatistik::where('nama_ptn', 'LIKE', '%' . ($target->kampus?->akronim ?? '') . '%')
            ->where('nama_prodi', 'LIKE', '%' . ($target->jurusan?->nama ?? '') . '%')
            ->orderByDesc('tahun')
            ->first();

        $base = [
            'jurusan_id'       => $target->jurusan_id,
            'kampus_id'        => $target->kampus_id,
            'priority'         => $target->priority,
            'nama_prodi'       => $target->jurusan?->nama ?? '-',
            'nama_ptn'         => $target->kampus?->nama  ?? '-',
            'akronim_ptn'      => $target->kampus?->akronim ?? '-',
            'skor_user'        => $skorUser,
            'skor_diperlukan'  => null,
            'stat'             => null,
            'status_lolos'     => 'BELUM_DIHITUNG',
            'probabilitas'     => null,
            'gap_skor'         => null,
            'catatan'          => 'Data keketatan belum tersedia.',
        ];

        if (!$stat || !$stat->rerata_skor_diterima) return $base;

        // Hitung skor diperlukan dengan formula multi-faktor
        $skorCalc  = $this->hitungSkorAman(
            (float) $stat->rerata_skor_diterima,
            $stat->keketatan_persen ? (float) $stat->keketatan_persen : null,
            (int) $stat->tahun
        );

        $skorAman   = $skorCalc['skor_aman'];
        $skorMasuk  = $skorCalc['skor_masuk'];

        // Jika DB sudah punya skor_aman (dari scraper), prioritaskan itu
        if ($stat->skor_aman > 0) $skorAman  = (float) $stat->skor_aman;
        if ($stat->skor_kuning > 0) $skorMasuk = (float) $stat->skor_kuning;

        $gap  = round($skorUser - $skorAman, 2);
        $k    = 0.08;
        $prob = $skorUser > 0 ? round(100 / (1 + exp(-$k * $gap)), 1) : null;

        $status = match(true) {
            $skorUser <= 0           => 'BELUM_DIHITUNG',
            $skorUser >= $skorAman   => 'AMAN',
            $skorUser >= $skorMasuk  => 'KUNING',
            default                  => 'MERAH',
        };

        $catatan = $this->generateCatatan($status, $gap, $stat);

        try {
            if ($skorUser > 0) {
                UserPeluangLolos::updateOrCreate(
                    ['user_id' => Auth::id(), 'jurusan_id' => $target->jurusan_id],
                    [
                        'skor_user'           => $skorUser,
                        'status_lolos'        => $status,
                        'probabilitas_persen' => $prob,
                        'gap_skor'            => $gap,
                        'kode_prodi'          => $stat->kode_prodi,
                        'catatan_ai'          => $catatan,
                        'dihitung_pada'       => now(),
                    ]
                );
            }
        } catch (\Exception) {}

        return array_merge($base, [
            'skor_diperlukan' => [
                'skor_masuk'       => $skorMasuk,   // batas minimum diterima
                'skor_aman'        => $skorAman,    // zona aman (+buffer)
                'formula_breakdown'=> $skorCalc['breakdown'],
            ],
            'stat' => $stat->only([
                'kode_prodi','keketatan_persen','kategori_keketatan',
                'skor_aman','skor_kuning','rerata_skor_diterima',
                'kuota_snbt','peminat_snbt','tahun',
            ]),
            'status_lolos' => $status,
            'probabilitas' => $prob,
            'gap_skor'     => $gap,
            'catatan'      => $catatan,
        ]);
    }

    private function getSkorUser(int $userId): float
    {
        // Priority 1: avg from sesi latihan (last 30 days)
        $avg = DB::table('sesi_latihans')
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('skor_akhir')
            ->avg('skor_akhir');

        if ($avg) return round((float) $avg, 2);

        // Priority 2: derive from akurasi overall (400 + akurasi% × 6)
        $attempt = DB::table('user_attempts')
            ->where('user_id', $userId)
            ->selectRaw('COUNT(*) as total, SUM(is_correct) as benar')
            ->first();

        if ($attempt && $attempt->total > 0) {
            $akurasi = $attempt->benar / $attempt->total * 100;
            return round(400 + ($akurasi / 100) * 600, 2);
        }

        return 0.0;
    }

    /**
     * Formula multi-faktor skor diperlukan PTN
     * Sama dengan ScrapeSidata::hitungSkorAman()
     *
     * @return array{skor_masuk: float, skor_aman: float, breakdown: array}
     */
    private function hitungSkorAman(float $rerata, ?float $keketatan, int $tahunData): array
    {
        if ($rerata <= 0) return ['skor_masuk' => 0, 'skor_aman' => 0, 'breakdown' => []];

        $tahunSekarang = (int) date('Y');

        // Komponen keketatan multiplier
        [$multiplier, $tierLabel] = match(true) {
            $keketatan === null => [1.05, 'Default'],
            $keketatan < 2     => [1.10, 'Sangat Ketat (<2%)'],
            $keketatan < 5     => [1.06, 'Ketat (2-5%)'],
            $keketatan < 10    => [1.03, 'Sedang (5-10%)'],
            default            => [1.01, 'Longgar (>10%)'],
        };

        // Inflasi tahunan (~7 poin/tahun)
        $inflasi   = max(0, ($tahunSekarang - $tahunData) * 7);
        $skorMasuk = round($rerata * $multiplier + $inflasi, 2);
        $skorAman  = round($skorMasuk + 15, 2);  // +15 safety buffer

        return [
            'skor_masuk' => $skorMasuk,
            'skor_aman'  => $skorAman,
            'breakdown'  => [
                'base_rerata'       => $rerata,
                'multiplier'        => $multiplier,
                'tier_label'        => $tierLabel,
                'inflasi_tahunan'   => $inflasi,
                'safety_buffer'     => 15,
                'formula'           => "({$rerata} × {$multiplier}) + {$inflasi} inflasi + 15 buffer = {$skorAman}",
            ],
        ];
    }

    /**
     * POST /api/v1/user/estimasi-skor
     * Generate manual skor estimasi untuk user baru
     */
    public function estimasiSkor(): JsonResponse
    {
        $userId = Auth::id();
        $skor   = $this->getSkorUser($userId);

        // Determine source
        $hasLatihan = DB::table('sesi_latihans')
            ->where('user_id', $userId)->whereNotNull('skor_akhir')->exists();
        $hasAttempt = DB::table('user_attempts')
            ->where('user_id', $userId)->exists();

        $source = match(true) {
            $hasLatihan => 'sesi_latihan',
            $hasAttempt => 'akurasi_overall',
            default     => 'none',
        };

        $pesan = match($source) {
            'sesi_latihan' => "Skor diambil dari rata-rata {$skor} poin sesi latihan 30 hari terakhir.",
            'akurasi_overall' => "Skor estimasi {$skor} dihitung dari akurasi keseluruhan soal kamu.",
            default => null,
        };

        return response()->json([
            'skor_estimasi' => $skor,
            'source'        => $source,
            'has_data'      => $source !== 'none',
            'pesan'         => $pesan,
            'saran'         => $source === 'none'
                ? 'Kerjakan minimal 10 soal latihan untuk mendapatkan estimasi skor SNBT yang akurat.'
                : null,
        ]);
    }

    private function generateCatatan(string $status, float $gap, ProdiStatistik $stat): string
    {
        $prodi = $stat->nama_prodi;
        $ptn   = $stat->nama_ptn;
        $abs   = abs($gap);
        return match($status) {
            'AMAN'   => "🟢 Skormu sudah {$abs} poin di atas skor aman {$prodi} {$ptn}. Pertahankan konsistensi!",
            'KUNING' => "🟡 Hanya kurang {$abs} poin untuk aman di {$prodi} {$ptn}. Tingkatkan latihan minggu ini!",
            'MERAH'  => "🔴 Butuh peningkatan {$abs} poin untuk aman di {$prodi} {$ptn}. Fokus latihan intensif setiap hari!",
            default  => "Data belum cukup untuk kalkulasi peluang lolos.",
        };
    }
}
