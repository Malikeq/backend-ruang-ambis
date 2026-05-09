<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Package;

class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            [
                'nama' => 'Free', 'harga_idr' => 0, 'durasi_hari' => 36500,
                'fitur_json' => ['20 soal/hari', 'AI Pembahasan (cached)', 'Leaderboard', 'Dashboard Progress'],
                'is_active' => true,
            ],
            [
                'nama' => 'Daily Pass', 'harga_idr' => 5000, 'durasi_hari' => 1,
                'fitur_json' => ['Soal Unlimited 1 Hari', 'Tanya AI (5x)', 'Foto ke Soal (3x)', 'Mode Ujian Simulasi'],
                'is_active' => true,
            ],
            [
                'nama' => 'Premium Bulanan', 'harga_idr' => 49000, 'durasi_hari' => 30,
                'fitur_json' => ['Soal Unlimited', 'Tanya AI (30x/hari)', 'Foto ke Soal (10x/hari)', 'Mode Ujian Simulasi', 'Analisis Kelemahan Detail', 'Prioritas Support'],
                'is_active' => true,
            ],
            [
                'nama' => 'Premium 3 Bulan', 'harga_idr' => 129000, 'durasi_hari' => 90,
                'fitur_json' => ['Soal Unlimited', 'Tanya AI (30x/hari)', 'Foto ke Soal (10x/hari)', 'Mode Ujian Simulasi', 'Analisis Kelemahan Detail', 'Prioritas Support', 'Hemat 12%'],
                'is_active' => true,
            ],
        ];

        foreach ($packages as $p) {
            Package::firstOrCreate(['nama' => $p['nama']], $p);
        }

        $this->command->info('✅ Packages seeded.');
    }
}
