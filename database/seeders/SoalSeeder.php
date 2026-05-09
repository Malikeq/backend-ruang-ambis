<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Mapel;
use App\Models\SubMateri;
use App\Models\Soal;
use App\Models\PilihanJawaban;

class SoalSeeder extends Seeder
{
    public function run(): void
    {
        $samples = [
            'PU' => [
                ['konten' => 'Semua mahasiswa rajin. Budi adalah mahasiswa. Kesimpulan yang tepat adalah...', 'kunci' => 'A', 'pilihan' => ['A' => 'Budi rajin', 'B' => 'Budi malas', 'C' => 'Budi tidak belajar', 'D' => 'Mahasiswa tidak rajin', 'E' => 'Budi bukan mahasiswa']],
                ['konten' => 'Dokter : Pasien = Guru : ...', 'kunci' => 'B', 'pilihan' => ['A' => 'Dokter', 'B' => 'Murid', 'C' => 'Sekolah', 'D' => 'Pelajaran', 'E' => 'Kelas']],
                ['konten' => '2, 4, 8, 16, ... Angka selanjutnya adalah...', 'kunci' => 'C', 'pilihan' => ['A' => '24', 'B' => '28', 'C' => '32', 'D' => '36', 'E' => '40']],
            ],
            'PM' => [
                ['konten' => 'Jika 3x + 6 = 21, maka nilai x adalah...', 'kunci' => 'B', 'pilihan' => ['A' => '4', 'B' => '5', 'C' => '6', 'D' => '7', 'E' => '8']],
                ['konten' => 'Luas persegi dengan sisi 8 cm adalah...', 'kunci' => 'A', 'pilihan' => ['A' => '64 cm²', 'B' => '32 cm²', 'C' => '16 cm²', 'D' => '48 cm²', 'E' => '128 cm²']],
                ['konten' => 'Peluang mendapatkan angka genap saat melempar dadu adalah...', 'kunci' => 'C', 'pilihan' => ['A' => '1/6', 'B' => '1/3', 'C' => '1/2', 'D' => '2/3', 'E' => '5/6']],
            ],
            'LBI' => [
                ['konten' => 'Kata "antusias" dalam kalimat "Siswa antusias mengikuti pelajaran" bermakna...', 'kunci' => 'A', 'pilihan' => ['A' => 'Bersemangat', 'B' => 'Malas', 'C' => 'Ngantuk', 'D' => 'Bingung', 'E' => 'Takut']],
            ],
            'LBE' => [
                ['konten' => 'What is the main idea of a paragraph that discusses climate change effects on polar ice?', 'kunci' => 'B', 'pilihan' => ['A' => 'History of polar regions', 'B' => 'Impact of climate change on polar ice', 'C' => 'Types of polar animals', 'D' => 'Ocean temperature data', 'E' => 'Weather forecasting methods']],
            ],
            'KMBM' => [
                ['konten' => 'Penulisan yang benar sesuai EYD adalah...', 'kunci' => 'C', 'pilihan' => ['A' => 'di mana', 'B' => 'dimana', 'C' => 'Di mana kamu pergi?', 'D' => 'Dimana kamu pergi?', 'E' => 'dimanakah kamu pergi']],
            ],
            'PK' => [
                ['konten' => 'Jika x = 12 dan y = 18, manakah yang lebih besar?', 'kunci' => 'B', 'pilihan' => ['A' => 'x lebih besar', 'B' => 'y lebih besar', 'C' => 'x = y', 'D' => 'Tidak dapat ditentukan', 'E' => 'Keduanya nol']],
            ],
            'PPU' => [
                ['konten' => 'Proklamasi kemerdekaan Indonesia dibacakan pada tanggal...', 'kunci' => 'A', 'pilihan' => ['A' => '17 Agustus 1945', 'B' => '18 Agustus 1945', 'C' => '1 Juni 1945', 'D' => '28 Oktober 1928', 'E' => '20 Mei 1908']],
            ],
        ];

        $count = 0;
        foreach ($samples as $kode => $soalList) {
            $mapel = Mapel::where('kode', $kode)->first();
            if (!$mapel) continue;
            $subMateri = SubMateri::where('mapel_id', $mapel->id)->first();

            foreach ($soalList as $s) {
                $soal = Soal::create([
                    'mapel_id'          => $mapel->id,
                    'sub_materi_id'     => $subMateri->id,
                    'konten'            => $s['konten'],
                    'tipe'              => 'MC',
                    'tingkat_kesulitan' => 'sedang',
                    'is_published'      => true,
                ]);

                foreach ($s['pilihan'] as $label => $text) {
                    PilihanJawaban::create([
                        'soal_id'    => $soal->id,
                        'label'      => $label,
                        'konten'     => $text,
                        'is_correct' => $label === $s['kunci'],
                    ]);
                }
                $count++;
            }
        }

        $this->command->info("✅ {$count} sample soal seeded.");
    }
}
