<?php

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    public function run(): void
    {
        // ── Premium Package ─────────────────────────────────────────────────
        Package::updateOrCreate(
            ['tier' => 'premium'],
            [
                'nama'        => 'Premium',
                'harga_idr'   => 49000,
                'durasi_hari' => 30,
                'tier'        => 'premium',
                'is_active'   => true,
                'fitur_json'  => [
                    'ai_tutor'              => true,
                    'ai_tanya_harian'       => -1,
                    'ai_photo_solve'        => true,
                    'ai_foto_harian'        => -1,
                    'latihan_soal_per_sesi' => -1,
                    'latihan_sesi_per_hari' => -1,
                    'review_jawaban'        => true,
                    'riwayat_latihan'       => true,
                    'leaderboard'           => true,
                    'analisis_kelemahan'    => true,
                    'soal_adaptif'          => true,
                    'tryout_penuh'          => true,
                    'akses_semua_mapel'     => true,
                    'export_hasil'          => true,
                    'bonus_poin_streak'     => true,
                ],
            ]
        );

        // ── Day Pass Package ────────────────────────────────────────────────
        Package::updateOrCreate(
            ['tier' => 'daily_pass'],
            [
                'nama'        => 'Day Pass',
                'harga_idr'   => 5000,
                'durasi_hari' => 1,
                'tier'        => 'daily_pass',
                'is_active'   => true,
                'fitur_json'  => [
                    'ai_tutor'              => true,
                    'ai_tanya_harian'       => -1,
                    'ai_photo_solve'        => true,
                    'ai_foto_harian'        => -1,
                    'latihan_soal_per_sesi' => -1,
                    'latihan_sesi_per_hari' => -1,
                    'review_jawaban'        => true,
                    'riwayat_latihan'       => true,
                    'leaderboard'           => true,
                    'analisis_kelemahan'    => true,
                    'soal_adaptif'          => false,
                    'tryout_penuh'          => true,
                    'akses_semua_mapel'     => true,
                    'export_hasil'          => false,
                    'bonus_poin_streak'     => false,
                ],
            ]
        );

        $this->command->info('Packages seeded: Premium + Day Pass');
    }
}
