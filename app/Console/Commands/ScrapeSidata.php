<?php

namespace App\Console\Commands;

use App\Models\ProdiStatistik;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScrapeSidata extends Command
{
    protected $signature   = 'snbt:scrape-sidata {tahun=2025} {--force}';
    protected $description = 'Scrape keketatan & kuota dari SNPMB sidata';

    public function handle(): int
    {
        $tahun = (int) $this->argument('tahun');
        $this->info("🔍 Mengambil data SNBT tahun {$tahun}...");

        $data = $this->tryFetchApi($tahun) ?? $this->useSeedFallback($tahun);

        $this->info("📊 Ditemukan " . count($data) . " prodi. Menyimpan...");
        $bar = $this->output->createProgressBar(count($data));

        $saved = 0;
        foreach ($data as $item) {
            try {
                $kuota   = (int)   ($item['kuota_snbt']             ?? $item['kuota']         ?? 0);
                $peminat = (int)   ($item['peminat_snbt']           ?? $item['peminat']       ?? 0);
                $rerata  = (float) ($item['rerata_skor_diterima']   ?? $item['rerata_nilai']  ?? 0);

                $keketatan = $peminat > 0 ? round($kuota / $peminat * 100, 2) : null;
                $kategori  = $this->kategorikan($keketatan);
                [$skorAman, $skorMasuk] = $this->hitungSkorAman($rerata, $keketatan, $tahun);

                ProdiStatistik::updateOrCreate(
                    ['kode_prodi' => $item['kode_prodi'], 'tahun' => $tahun],
                    [
                        'kode_ptn'               => $item['kode_ptn']   ?? '',
                        'nama_ptn'               => $item['nama_ptn']   ?? '',
                        'nama_prodi'             => $item['nama_prodi'] ?? '',
                        'kelompok_ujian'         => $item['kelompok']   ?? 'SAINTEK',
                        'kuota_snbt'             => $kuota,
                        'peminat_snbt'           => $peminat,
                        'rerata_skor_diterima'   => $rerata ?: null,
                        'skor_minimum_diterima'  => $item['skor_min']   ?? null,
                        'skor_maksimum_diterima' => $item['skor_max']   ?? null,
                        'keketatan_persen'       => $keketatan,
                        'kategori_keketatan'     => $kategori,
                        'skor_aman'              => $skorAman,
                        'skor_kuning'            => $skorMasuk,   // batas kuning = skor masuk minimum
                        'kuota_snbp'             => (int) ($item['kuota_snbp']   ?? 0),
                        'peminat_snbp'           => (int) ($item['peminat_snbp'] ?? 0),
                    ]
                );
                $saved++;
            } catch (\Exception $e) {
                Log::warning("ScrapeSidata skip: " . $e->getMessage());
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("✅ {$saved} prodi berhasil disimpan.");
        return Command::SUCCESS;
    }

    private function tryFetchApi(int $tahun): ?array
    {
        try {
            $res = Http::withHeaders(['User-Agent' => 'Mozilla/5.0', 'Accept' => 'application/json'])
                       ->withoutVerifying()  // SNPMB SSL cert is expired
                       ->timeout(20)
                       ->get("https://sidata-ptn-snpmb.bppp.kemdikbud.go.id/api/v1/snbt/prodi", ['tahun' => $tahun]);
            if ($res->ok()) {
                $json = $res->json();
                return $json['data'] ?? (is_array($json) ? $json : null);
            }
        } catch (\Exception $e) {
            $this->warn("API gagal: " . $e->getMessage() . ". Pakai fallback.");
        }
        return null;
    }

    private function useSeedFallback(int $tahun): array
    {
        $this->warn('⚠️  Menggunakan data seed fallback (7 prodi sample)...');
        return [
            ['kode_ptn'=>'001','nama_ptn'=>'UI',    'kode_prodi'=>'30101','nama_prodi'=>'Teknik Sipil',       'kelompok'=>'SAINTEK','kuota_snbt'=>60, 'peminat_snbt'=>2100,'rerata_skor_diterima'=>720.5],
            ['kode_ptn'=>'001','nama_ptn'=>'UI',    'kode_prodi'=>'30201','nama_prodi'=>'Ilmu Komputer',      'kelompok'=>'SAINTEK','kuota_snbt'=>50, 'peminat_snbt'=>3200,'rerata_skor_diterima'=>745.3],
            ['kode_ptn'=>'002','nama_ptn'=>'UGM',   'kode_prodi'=>'40101','nama_prodi'=>'Teknik Informatika', 'kelompok'=>'SAINTEK','kuota_snbt'=>55, 'peminat_snbt'=>4100,'rerata_skor_diterima'=>738.2],
            ['kode_ptn'=>'002','nama_ptn'=>'UGM',   'kode_prodi'=>'40201','nama_prodi'=>'Kedokteran',         'kelompok'=>'SAINTEK','kuota_snbt'=>30, 'peminat_snbt'=>5800,'rerata_skor_diterima'=>792.0],
            ['kode_ptn'=>'003','nama_ptn'=>'ITB',   'kode_prodi'=>'50101','nama_prodi'=>'Teknik Informatika', 'kelompok'=>'SAINTEK','kuota_snbt'=>45, 'peminat_snbt'=>3900,'rerata_skor_diterima'=>752.1],
            ['kode_ptn'=>'004','nama_ptn'=>'UNPAD', 'kode_prodi'=>'60201','nama_prodi'=>'Hukum',              'kelompok'=>'SOSHUM', 'kuota_snbt'=>80, 'peminat_snbt'=>1800,'rerata_skor_diterima'=>668.4],
            ['kode_ptn'=>'005','nama_ptn'=>'UNDIP', 'kode_prodi'=>'70101','nama_prodi'=>'Teknik Elektro',     'kelompok'=>'SAINTEK','kuota_snbt'=>65, 'peminat_snbt'=>1200,'rerata_skor_diterima'=>695.8],
        ];
    }

    /**
     * Formula multi-faktor untuk skor aman PTN
     *
     * Komponen:
     *  1. Base: rerata skor diterima (historis SNPMB)
     *  2. Keketatan multiplier: makin ketat → makin tinggi persyaratan
     *     - Sangat ketat (<2%)  → ×1.10
     *     - Ketat     (2-5%)   → ×1.06
     *     - Sedang    (5-10%)  → ×1.03
     *     - Longgar   (>10%)   → ×1.01
     *  3. Inflasi skor: ~7 poin/tahun (tren kenaikan soal UTBK)
     *  4. Safety buffer: +15 poin (zona aman di atas batas masuk)
     *
     * @return array [skor_aman, skor_masuk_minimum]
     */
    private function hitungSkorAman(float $rerata, ?float $keketatan, int $tahunData): array
    {
        if ($rerata <= 0) return [null, null];

        $tahunSekarang = (int) date('Y');

        // Komponen 2: keketatan multiplier
        $multiplier = match(true) {
            $keketatan === null => 1.05,
            $keketatan < 2     => 1.10,
            $keketatan < 5     => 1.06,
            $keketatan < 10    => 1.03,
            default            => 1.01,
        };

        // Komponen 3: inflasi skor tahunan (~7 poin/tahun)
        $inflasi = max(0, ($tahunSekarang - $tahunData) * 7);

        // Skor masuk minimum = base × multiplier + inflasi
        $skorMasuk = round($rerata * $multiplier + $inflasi, 2);

        // Skor aman = skor masuk + 15 poin safety buffer
        $skorAman  = round($skorMasuk + 15, 2);

        return [$skorAman, $skorMasuk];
    }

    private function kategorikan(?float $k): ?string

    {
        if ($k === null) return null;
        return match(true) {
            $k > 10  => 'LONGGAR',
            $k > 5   => 'SEDANG',
            $k > 2   => 'KETAT',
            default  => 'SANGAT_KETAT',
        };
    }
}
