<?php

namespace App\Http\Controllers\Api\Pengamat;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Sekolah;
use App\Models\PengamatSekolah;
use App\Models\WeaknessReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PengamatController extends Controller
{
    // ── Helper: get sekolah_id milik pengamat yang login ──────────────────────
    private function getSekolahId(Request $request): int
    {
        return $request->user()->pengamatSekolah->sekolah_id;
    }

    // ── Helper: base query siswa untuk sekolah ini ────────────────────────────
    private function siswaQuery(int $sekolahId)
    {
        return User::where('sekolah_id', $sekolahId)
                   ->where('role', 'user');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /pengamat/me
    // ─────────────────────────────────────────────────────────────────────────
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('sekolah', 'pengamatSekolah.sekolah');
        return response()->json(['success' => true, 'data' => $user]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /pengamat/overview — ringkasan statistik sekolah
    // ─────────────────────────────────────────────────────────────────────────
    public function overview(Request $request): JsonResponse
    {
        $sekolahId = $this->getSekolahId($request);
        $siswaIds  = $this->siswaQuery($sekolahId)->pluck('id');

        $totalSiswa   = $siswaIds->count();
        $aktifHariIni = User::whereIn('id', $siswaIds)
            ->whereDate('last_active', today())->count();
        $aktifMingguIni = User::whereIn('id', $siswaIds)
            ->where('last_active', '>=', now()->startOfWeek())->count();
        $tidakAktif7 = User::whereIn('id', $siswaIds)
            ->where(fn($q) => $q->whereNull('last_active')
                ->orWhere('last_active', '<', now()->subDays(7)))
            ->count();

        // Avg SNBT estimasi dari sesi terakhir tiap siswa
        $avgSnbt = DB::table('sesi_latihan')
            ->whereIn('user_id', $siswaIds)
            ->whereNotNull('skor_akhir')
            ->whereNotNull('selesai')
            ->select('user_id', DB::raw('AVG(skor_akhir) as avg_snbt'))
            ->groupBy('user_id')
            ->get()
            ->avg('avg_snbt');

        // Total sesi minggu ini
        $sesiMingguIni = DB::table('sesi_latihan')
            ->whereIn('user_id', $siswaIds)
            ->where('created_at', '>=', now()->startOfWeek())
            ->whereNotNull('selesai')
            ->count();

        // Siswa streak ≥ 7
        $siswaStreakBaik = User::whereIn('id', $siswaIds)
            ->where('streak_days', '>=', 7)->count();

        // Premium vs free
        $tierDistrib = User::whereIn('id', $siswaIds)
            ->select('tier', DB::raw('COUNT(*) as jumlah'))
            ->groupBy('tier')
            ->pluck('jumlah', 'tier');

        $sekolah = Sekolah::find($sekolahId);

        return response()->json([
            'success' => true,
            'data'    => [
                'sekolah'          => $sekolah,
                'total_siswa'      => $totalSiswa,
                'aktif_hari_ini'   => $aktifHariIni,
                'aktif_minggu_ini' => $aktifMingguIni,
                'tidak_aktif_7d'   => $tidakAktif7,
                'avg_snbt'         => round($avgSnbt ?? 0, 1),
                'sesi_minggu_ini'  => $sesiMingguIni,
                'streak_bagus'     => $siswaStreakBaik,
                'tier_distribusi'  => $tierDistrib,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /pengamat/siswa — list semua siswa (paginated + search)
    // ─────────────────────────────────────────────────────────────────────────
    public function siswa(Request $request): JsonResponse
    {
        $sekolahId = $this->getSekolahId($request);
        $search    = $request->search;
        $sortBy    = $request->get('sort_by', 'last_active');   // last_active|streak|points
        $perPage   = min((int) $request->get('per_page', 20), 50);

        $query = $this->siswaQuery($sekolahId)
            ->when($search, fn($q) => $q->where('name', 'like', "%{$search}%")
                                        ->orWhere('email', 'like', "%{$search}%"))
            ->select([
                'id', 'name', 'email', 'tier', 'streak_days',
                'points', 'last_active', 'sekolah_id', 'avatar_url',
            ]);

        match ($sortBy) {
            'streak' => $query->orderByDesc('streak_days'),
            'points' => $query->orderByDesc('points'),
            default  => $query->orderByDesc('last_active'),
        };

        $siswa = $query->paginate($perPage);

        // Tambah stats agregat per siswa
        $ids   = $siswa->pluck('id');
        $stats = DB::table('sesi_latihan')
            ->whereIn('user_id', $ids)
            ->whereNotNull('selesai')
            ->select(
                'user_id',
                DB::raw('COUNT(*) as total_sesi'),
                DB::raw('AVG(skor_akhir) as avg_snbt'),
                DB::raw('SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as sesi_7d'),
            )
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $siswa->getCollection()->transform(function ($s) use ($stats) {
            $stat = $stats[$s->id] ?? null;
            $s->total_sesi = $stat?->total_sesi  ?? 0;
            $s->avg_snbt   = round($stat?->avg_snbt ?? 0, 1);
            $s->sesi_7d    = $stat?->sesi_7d      ?? 0;
            return $s;
        });

        return response()->json(['success' => true, 'data' => $siswa]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /pengamat/siswa/{id} — detail 1 siswa
    // ─────────────────────────────────────────────────────────────────────────
    public function siswaDetail(Request $request, int $siswaId): JsonResponse
    {
        $sekolahId = $this->getSekolahId($request);

        $siswa = $this->siswaQuery($sekolahId)
            ->with('kampusTargets.kampus', 'kampusTargets.jurusan')
            ->findOrFail($siswaId);

        // Riwayat sesi 30 hari
        $sesiList = DB::table('sesi_latihan')
            ->where('user_id', $siswaId)
            ->whereNotNull('selesai')
            ->where('created_at', '>=', now()->subDays(30))
            ->select('id', 'tipe', 'skor_akhir', 'skor_raw', 'created_at')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        // Progres per mapel
        $progresMapel = DB::table('user_attempts as ua')
            ->join('soal as s', 's.id', '=', 'ua.soal_id')
            ->join('mapel as m', 'm.id', '=', 's.mapel_id')
            ->where('ua.user_id', $siswaId)
            ->select(
                'm.nama as mapel',
                'm.kode',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(ua.is_correct) as benar'),
                DB::raw('ROUND(SUM(ua.is_correct)*100/COUNT(*),1) as akurasi'),
            )
            ->groupBy('m.id', 'm.nama', 'm.kode')
            ->get();

        // Kelemahan
        $kelemahan = DB::table('weakness_reports as wr')
            ->join('sub_materi as sm', 'sm.id', '=', 'wr.sub_materi_id')
            ->join('mapel as m', 'm.id', '=', 'wr.mapel_id')
            ->where('wr.user_id', $siswaId)
            ->where('wr.accuracy_rate', '<', 60)
            ->select('sm.nama as sub_materi', 'm.nama as mapel', 'wr.accuracy_rate', 'wr.attempt_count')
            ->orderBy('wr.accuracy_rate')
            ->limit(5)
            ->get();

        // ── Stats agregat siswa ini ──────────────────────────────────────────
        $stats = DB::table('sesi_latihan')
            ->where('user_id', $siswaId)
            ->whereNotNull('selesai')
            ->selectRaw('
                COUNT(*) as total_sesi,
                AVG(skor_akhir) as avg_snbt,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as sesi_7d
            ')
            ->first();

        $siswa->total_sesi = (int)   ($stats->total_sesi ?? 0);
        $siswa->avg_snbt   = round(   $stats->avg_snbt   ?? 0, 1);
        $siswa->sesi_7d    = (int)   ($stats->sesi_7d    ?? 0);
        // streak_days sudah ada di model, pastikan ter-expose
        $siswa->streak_days = (int) $siswa->streak_days;

        return response()->json([
            'success' => true,
            'data'    => [
                'siswa'         => $siswa,
                'sesi_list'     => $sesiList,
                'progres_mapel' => $progresMapel,
                'kelemahan'     => $kelemahan,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /pengamat/ranking — ranking siswa
    // ─────────────────────────────────────────────────────────────────────────
    public function ranking(Request $request): JsonResponse
    {
        $sekolahId = $this->getSekolahId($request);
        $periode   = $request->get('periode', 'minggu'); // minggu|bulan|all
        $siswaIds  = $this->siswaQuery($sekolahId)->pluck('id');

        $since = match ($periode) {
            'minggu' => now()->startOfWeek(),
            'bulan'  => now()->startOfMonth(),
            default  => null,
        };

        $statsQuery = DB::table('sesi_latihan')
            ->whereIn('user_id', $siswaIds)
            ->whereNotNull('selesai')
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->select(
                'user_id',
                DB::raw('COUNT(*) as total_sesi'),
                DB::raw('AVG(skor_akhir) as avg_snbt'),
                DB::raw('SUM(CASE WHEN skor_akhir IS NOT NULL THEN 1 ELSE 0 END) as sesi_ber_skor'),
            )
            ->groupBy('user_id');

        $stats = $statsQuery->get()->keyBy('user_id');

        $siswa = User::whereIn('id', $siswaIds)
            ->select('id', 'name', 'tier', 'streak_days', 'points', 'avatar_url', 'last_active')
            ->get()
            ->map(function ($s) use ($stats) {
                $st = $stats[$s->id] ?? null;
                $s->total_sesi = $st?->total_sesi ?? 0;
                $s->avg_snbt   = round($st?->avg_snbt ?? 0, 1);
                return $s;
            })
            ->sortByDesc('avg_snbt')
            ->values()
            ->map(function ($s, $i) {
                $s->rank = $i + 1;
                return $s;
            });

        return response()->json(['success' => true, 'data' => $siswa, 'periode' => $periode]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /pengamat/aktivitas-harian — chart data 7/30 hari
    // ─────────────────────────────────────────────────────────────────────────
    public function aktivitasHarian(Request $request): JsonResponse
    {
        $sekolahId = $this->getSekolahId($request);
        $hari      = (int) $request->get('hari', 7);
        $hari      = in_array($hari, [7, 14, 30]) ? $hari : 7;
        $siswaIds  = $this->siswaQuery($sekolahId)->pluck('id');

        $rows = DB::table('sesi_latihan')
            ->whereIn('user_id', $siswaIds)
            ->whereNotNull('selesai')
            ->where('created_at', '>=', now()->subDays($hari))
            ->select(
                DB::raw('DATE(created_at) as tanggal'),
                DB::raw('COUNT(*) as total_sesi'),
                DB::raw('COUNT(DISTINCT user_id) as siswa_aktif'),
                DB::raw('AVG(skor_akhir) as avg_snbt'),
            )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('tanggal')
            ->get();

        // Fill missing dates
        $filled = [];
        for ($i = $hari - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $row  = $rows->firstWhere('tanggal', $date);
            $filled[] = [
                'tanggal'      => $date,
                'total_sesi'   => $row?->total_sesi   ?? 0,
                'siswa_aktif'  => $row?->siswa_aktif  ?? 0,
                'avg_snbt'     => round($row?->avg_snbt ?? 0, 1),
            ];
        }

        return response()->json(['success' => true, 'data' => $filled]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /pengamat/kelemahan-kelas — agregat weakness seluruh siswa
    // ─────────────────────────────────────────────────────────────────────────
    public function kelemahanKelas(Request $request): JsonResponse
    {
        $sekolahId = $this->getSekolahId($request);
        $siswaIds  = $this->siswaQuery($sekolahId)->pluck('id');
        $total     = $siswaIds->count();

        $data = DB::table('weakness_reports as wr')
            ->join('sub_materi as sm', 'sm.id', '=', 'wr.sub_materi_id')
            ->join('mapel as m', 'm.id', '=', 'wr.mapel_id')
            ->whereIn('wr.user_id', $siswaIds)
            ->select(
                'sm.id as sub_materi_id',
                'sm.nama as sub_materi',
                'm.nama as mapel',
                'm.kode as kode_mapel',
                DB::raw('COUNT(DISTINCT wr.user_id) as jumlah_siswa'),
                DB::raw('AVG(wr.accuracy_rate) as avg_akurasi'),
                DB::raw('SUM(wr.attempt_count) as total_attempt'),
                DB::raw('SUM(CASE WHEN wr.is_flagged THEN 1 ELSE 0 END) as flagged_count'),
            )
            ->groupBy('sm.id', 'sm.nama', 'm.nama', 'm.kode')
            ->orderBy('avg_akurasi')
            ->limit(15)
            ->get()
            ->map(function ($r) use ($total) {
                $r->persen_siswa = $total > 0 ? round($r->jumlah_siswa / $total * 100, 1) : 0;
                $r->avg_akurasi  = round($r->avg_akurasi, 1);
                return $r;
            });

        return response()->json(['success' => true, 'data' => $data, 'total_siswa' => $total]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /pengamat/at-risk — siswa berisiko
    // ─────────────────────────────────────────────────────────────────────────
    public function atRisk(Request $request): JsonResponse
    {
        $sekolahId = $this->getSekolahId($request);

        // Tidak aktif > 7 hari
        $tidakAktif = $this->siswaQuery($sekolahId)
            ->where(fn($q) => $q->whereNull('last_active')
                ->orWhere('last_active', '<', now()->subDays(7)))
            ->select('id', 'name', 'email', 'tier', 'last_active', 'streak_days', 'avatar_url')
            ->orderBy('last_active')
            ->limit(20)
            ->get()
            ->map(fn($s) => array_merge($s->toArray(), ['risk_type' => 'tidak_aktif']));

        // Akurasi rendah < 40% (dari weakness_reports)
        $akurasiRendah = $this->siswaQuery($sekolahId)
            ->join(DB::raw('(
                SELECT user_id, AVG(accuracy_rate) as avg_akurasi
                FROM weakness_reports
                GROUP BY user_id
                HAVING avg_akurasi < 40
            ) as wr_agg'), 'users.id', '=', 'wr_agg.user_id')
            ->select('users.id', 'users.name', 'users.email', 'users.tier', 'users.last_active', 'users.avatar_url', 'wr_agg.avg_akurasi')
            ->whereNotIn('users.id', $tidakAktif->pluck('id'))
            ->limit(20)
            ->get()
            ->map(fn($s) => array_merge($s->toArray(), ['risk_type' => 'akurasi_rendah']));

        // Belum pernah latihan
        $belumLatihan = $this->siswaQuery($sekolahId)
            ->whereDoesntHave('sesiLatihan', fn($q) => $q->whereNotNull('selesai'))
            ->select('id', 'name', 'email', 'tier', 'created_at', 'avatar_url')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn($s) => array_merge($s->toArray(), ['risk_type' => 'belum_latihan']));

        return response()->json([
            'success' => true,
            'data'    => [
                'tidak_aktif'   => $tidakAktif,
                'akurasi_rendah'=> $akurasiRendah,
                'belum_latihan' => $belumLatihan,
            ],
            'summary' => [
                'tidak_aktif'   => $tidakAktif->count(),
                'akurasi_rendah'=> $akurasiRendah->count(),
                'belum_latihan' => $belumLatihan->count(),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /pengamat/sekolah/list — search sekolah saat register
    // ─────────────────────────────────────────────────────────────────────────
    public function sekolahList(Request $request): JsonResponse
    {
        $q = $request->get('q', '');
        $sekolahs = Sekolah::when($q, fn($query) => $query->where('nama', 'like', "%{$q}%"))
            ->orderBy('nama')
            ->limit(30)
            ->get(['id', 'nama', 'kota', 'provinsi']);

        return response()->json(['success' => true, 'data' => $sekolahs]);
    }
}
