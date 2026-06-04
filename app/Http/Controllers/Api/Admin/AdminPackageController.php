<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckFeature;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Canonical feature list with metadata.
 * Shared with the frontend via GET /admin/features-definition.
 */
class AdminPackageController extends Controller
{
    /**
     * All available feature flags with metadata for the admin UI.
     */
    public static function featuresDefinition(): array
    {
        return [
            // ── AI Features ─────────────────────────────────────────────
            [
                'key'      => 'ai_tutor',
                'label'    => 'AI Tutor (Chat)',
                'desc'     => 'Akses fitur chat AI Tutor untuk tanya jawab bebas',
                'group'    => 'AI',
                'type'     => 'boolean',
                'icon'     => '🤖',
                'default'  => false,
            ],
            [
                'key'      => 'ai_tanya_harian',
                'label'    => 'Batas Tanya AI / Hari',
                'desc'     => 'Jumlah pertanyaan AI Tutor per hari. -1 = unlimited',
                'group'    => 'AI',
                'type'     => 'number',
                'icon'     => '💬',
                'default'  => 0,
                'unit'     => 'pertanyaan/hari',
                'hint'     => '-1 = tidak terbatas',
            ],
            [
                'key'      => 'ai_photo_solve',
                'label'    => 'AI Foto Soal',
                'desc'     => 'Foto soal dan minta AI menjelaskan/menjawab',
                'group'    => 'AI',
                'type'     => 'boolean',
                'icon'     => '📷',
                'default'  => false,
            ],
            [
                'key'      => 'ai_foto_harian',
                'label'    => 'Batas Foto AI / Hari',
                'desc'     => 'Jumlah foto soal per hari. -1 = unlimited',
                'group'    => 'AI',
                'type'     => 'number',
                'icon'     => '🖼️',
                'default'  => 0,
                'unit'     => 'foto/hari',
                'hint'     => '-1 = tidak terbatas',
            ],

            // ── Latihan Features ─────────────────────────────────────────
            [
                'key'      => 'latihan_soal_per_sesi',
                'label'    => 'Batas Soal / Sesi Latihan',
                'desc'     => 'Jumlah maksimal soal per sesi. -1 = unlimited',
                'group'    => 'Latihan',
                'type'     => 'number',
                'icon'     => '📝',
                'default'  => 20,
                'unit'     => 'soal/sesi',
                'hint'     => '-1 = tidak terbatas',
            ],
            [
                'key'      => 'latihan_sesi_per_hari',
                'label'    => 'Batas Sesi Latihan / Hari',
                'desc'     => 'Jumlah sesi latihan per hari. -1 = unlimited',
                'group'    => 'Latihan',
                'type'     => 'number',
                'icon'     => '🔁',
                'default'  => 3,
                'unit'     => 'sesi/hari',
                'hint'     => '-1 = tidak terbatas',
            ],
            [
                'key'      => 'tryout_penuh',
                'label'    => 'Tryout / Ujian Penuh',
                'desc'     => 'Akses mode ujian penuh semua mapel SNBT',
                'group'    => 'Latihan',
                'type'     => 'boolean',
                'icon'     => '🎯',
                'default'  => false,
            ],
            [
                'key'      => 'akses_semua_mapel',
                'label'    => 'Akses Semua Mata Pelajaran',
                'desc'     => 'Latihan semua mapel (bukan hanya PU & PM)',
                'group'    => 'Latihan',
                'type'     => 'boolean',
                'icon'     => '📚',
                'default'  => false,
            ],
            [
                'key'      => 'soal_adaptif',
                'label'    => 'Soal Adaptif (IRT)',
                'desc'     => 'Sistem soal menyesuaikan level kemampuan user',
                'group'    => 'Latihan',
                'type'     => 'boolean',
                'icon'     => '🧠',
                'default'  => false,
            ],

            // ── Analisis & Review ─────────────────────────────────────────
            [
                'key'      => 'review_jawaban',
                'label'    => 'Review Jawaban',
                'desc'     => 'Lihat pembahasan per soal setelah sesi latihan',
                'group'    => 'Analisis',
                'type'     => 'boolean',
                'icon'     => '🔍',
                'default'  => false,
            ],
            [
                'key'      => 'riwayat_latihan',
                'label'    => 'Riwayat Latihan',
                'desc'     => 'Akses history semua sesi latihan yang pernah dikerjakan',
                'group'    => 'Analisis',
                'type'     => 'boolean',
                'icon'     => '📊',
                'default'  => false,
            ],
            [
                'key'      => 'analisis_kelemahan',
                'label'    => 'Analisis Kelemahan',
                'desc'     => 'Laporan sub-materi mana yang paling lemah',
                'group'    => 'Analisis',
                'type'     => 'boolean',
                'icon'     => '⚠️',
                'default'  => false,
            ],
            [
                'key'      => 'export_hasil',
                'label'    => 'Export Hasil (PDF)',
                'desc'     => 'Download laporan hasil latihan dalam format PDF',
                'group'    => 'Analisis',
                'type'     => 'boolean',
                'icon'     => '📄',
                'default'  => false,
            ],

            // ── Gamifikasi ────────────────────────────────────────────────
            [
                'key'      => 'leaderboard',
                'label'    => 'Leaderboard',
                'desc'     => 'Muncul & bersaing di papan peringkat',
                'group'    => 'Lainnya',
                'type'     => 'boolean',
                'icon'     => '🏆',
                'default'  => true,
            ],
            [
                'key'      => 'bonus_poin_streak',
                'label'    => 'Bonus Poin Streak',
                'desc'     => 'Dapatkan poin ekstra untuk streak belajar harian',
                'group'    => 'Lainnya',
                'type'     => 'boolean',
                'icon'     => '🔥',
                'default'  => false,
            ],
        ];
    }

    // ── Controller actions ─────────────────────────────────────────────────────

    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => Package::all()]);
    }

    public function featuresDefinitionEndpoint(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => self::featuresDefinition()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nama'        => ['required', 'string', 'max:100'],
            'harga_idr'   => ['required', 'integer', 'min:0'],
            'durasi_hari' => ['required', 'integer', 'min:1'],
            'tier'        => ['required', 'in:premium,daily_pass'],
            'fitur_json'  => ['required', 'array'],
            'is_active'   => ['boolean'],
        ]);

        $data['fitur_json'] = $this->sanitizeFeatures($data['fitur_json']);
        $pkg = Package::create($data + ['is_active' => $data['is_active'] ?? true]);

        return response()->json(['success' => true, 'data' => $pkg], 201);
    }

    public function update(Request $request, Package $pkg): JsonResponse
    {
        $data = $request->validate([
            'nama'        => ['sometimes', 'string', 'max:100'],
            'harga_idr'   => ['sometimes', 'integer', 'min:0'],
            'durasi_hari' => ['sometimes', 'integer', 'min:1'],
            'tier'        => ['sometimes', 'in:premium,daily_pass'],
            'fitur_json'  => ['sometimes', 'array'],
            'is_active'   => ['sometimes', 'boolean'],
        ]);

        if (isset($data['fitur_json'])) {
            $data['fitur_json'] = $this->sanitizeFeatures($data['fitur_json']);
        }

        $pkg->update($data);
        return response()->json(['success' => true, 'data' => $pkg->fresh()]);
    }

    public function destroy(Package $pkg): JsonResponse
    {
        $pkg->update(['is_active' => false]);
        return response()->json(['success' => true, 'message' => 'Paket dinonaktifkan.']);
    }

    /**
     * Sanitize feature values — booleans stay booleans, numbers stay ints.
     */
    private function sanitizeFeatures(array $raw): array
    {
        $defs    = self::featuresDefinition();
        $typeMap = array_column($defs, 'type', 'key');
        $out     = [];

        foreach ($defs as $def) {
            $k = $def['key'];
            if (!array_key_exists($k, $raw)) {
                $out[$k] = $def['default'];
                continue;
            }
            $out[$k] = $def['type'] === 'boolean'
                ? (bool) $raw[$k]
                : (int) $raw[$k];
        }

        return $out;
    }
}
