<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Mapel;
use App\Models\SubMateri;

class MapelSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['kode' => 'PU',   'nama' => 'Penalaran Umum',                      'subs' => ['Silogisme', 'Analogi Verbal', 'Deret Angka', 'Penalaran Spasial', 'Logika Deduktif']],
            ['kode' => 'PM',   'nama' => 'Penalaran Matematika',                 'subs' => ['Aljabar Linear', 'Geometri', 'Statistika', 'Peluang', 'Logika Numerik', 'Barisan & Deret']],
            ['kode' => 'LBI',  'nama' => 'Literasi dalam Bahasa Indonesia',      'subs' => ['Ide Pokok', 'Inferensi', 'Makna Kata', 'Struktur Teks', 'Fakta & Opini']],
            ['kode' => 'LBE',  'nama' => 'Literasi dalam Bahasa Inggris',        'subs' => ['Main Idea', 'Inference', 'Vocabulary in Context', "Author's Purpose", 'Detail Questions']],
            ['kode' => 'KMBM', 'nama' => 'Kemampuan Memahami Bacaan dan Menulis','subs' => ['EYD & PUEBI', 'Kepaduan Paragraf', 'Efektivitas Kalimat', 'Pilihan Kata', 'Ejaan']],
            ['kode' => 'PK',   'nama' => 'Pengetahuan Kuantitatif',              'subs' => ['Perbandingan Kuantitas', 'Data Sufficiency', 'Rasio & Proporsi', 'Interpretasi Data']],
            ['kode' => 'PPU',  'nama' => 'Pengetahuan dan Pemahaman Umum',       'subs' => ['Sejarah Indonesia', 'Geografi Nusantara', 'Sains Dasar', 'Ekonomi Dasar', 'Kewarganegaraan']],
        ];

        foreach ($data as $m) {
            $mapel = Mapel::firstOrCreate(['kode' => $m['kode']], ['nama' => $m['nama'], 'snbt_weight' => 1.0]);
            foreach ($m['subs'] as $sub) {
                SubMateri::firstOrCreate(['mapel_id' => $mapel->id, 'nama' => $sub]);
            }
        }

        $this->command->info('✅ Mapel & sub-materi seeded.');
    }
}
