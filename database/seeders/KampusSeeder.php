<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use App\Models\Kampus;
use App\Models\Jurusan;

class KampusSeeder extends Seeder
{
    private string $apiKey = 'fSTxpl7uEQjulT2stQg4gMKIazsmbqRF2UglGTRJHLoKw3VA8H';
    private string $baseUrl = 'https://use.api.co.id/regional/indonesia/universities';

    // Comprehensive jurusan per kampus akronim
    private array $jurusanData = [
        'UI' => [
            ['nama' => 'Kedokteran', 'fakultas' => 'FK', 'passing_grade_estimate' => 95.00],
            ['nama' => 'Hukum', 'fakultas' => 'FH', 'passing_grade_estimate' => 88.00],
            ['nama' => 'Teknik Sipil', 'fakultas' => 'FT', 'passing_grade_estimate' => 87.00],
            ['nama' => 'Teknik Elektro', 'fakultas' => 'FT', 'passing_grade_estimate' => 89.00],
            ['nama' => 'Teknik Komputer', 'fakultas' => 'FT', 'passing_grade_estimate' => 90.00],
            ['nama' => 'Ilmu Komputer', 'fakultas' => 'FASILKOM', 'passing_grade_estimate' => 93.00],
            ['nama' => 'Ekonomi', 'fakultas' => 'FEB', 'passing_grade_estimate' => 87.00],
            ['nama' => 'Akuntansi', 'fakultas' => 'FEB', 'passing_grade_estimate' => 86.00],
            ['nama' => 'Psikologi', 'fakultas' => 'FPSI', 'passing_grade_estimate' => 88.00],
            ['nama' => 'Farmasi', 'fakultas' => 'FF', 'passing_grade_estimate' => 89.00],
        ],
        'ITB' => [
            ['nama' => 'Teknik Informatika', 'fakultas' => 'STEI', 'passing_grade_estimate' => 95.00],
            ['nama' => 'Teknik Elektro', 'fakultas' => 'STEI', 'passing_grade_estimate' => 93.00],
            ['nama' => 'Teknik Mesin', 'fakultas' => 'FTMD', 'passing_grade_estimate' => 90.00],
            ['nama' => 'Teknik Sipil', 'fakultas' => 'FTSL', 'passing_grade_estimate' => 89.00],
            ['nama' => 'Teknik Kimia', 'fakultas' => 'FTKI', 'passing_grade_estimate' => 88.00],
            ['nama' => 'Matematika', 'fakultas' => 'FMIPA', 'passing_grade_estimate' => 87.00],
            ['nama' => 'Fisika', 'fakultas' => 'FMIPA', 'passing_grade_estimate' => 85.00],
            ['nama' => 'Astronomi', 'fakultas' => 'FMIPA', 'passing_grade_estimate' => 84.00],
            ['nama' => 'Arsitektur', 'fakultas' => 'SAPPK', 'passing_grade_estimate' => 88.00],
            ['nama' => 'Desain Produk', 'fakultas' => 'FSRD', 'passing_grade_estimate' => 86.00],
        ],
        'UGM' => [
            ['nama' => 'Kedokteran', 'fakultas' => 'FK', 'passing_grade_estimate' => 94.00],
            ['nama' => 'Teknik Informatika', 'fakultas' => 'FT', 'passing_grade_estimate' => 90.00],
            ['nama' => 'Ilmu Hukum', 'fakultas' => 'FH', 'passing_grade_estimate' => 87.00],
            ['nama' => 'Akuntansi', 'fakultas' => 'FEB', 'passing_grade_estimate' => 86.00],
            ['nama' => 'Manajemen', 'fakultas' => 'FEB', 'passing_grade_estimate' => 85.00],
            ['nama' => 'Psikologi', 'fakultas' => 'FPSI', 'passing_grade_estimate' => 87.00],
            ['nama' => 'Farmasi', 'fakultas' => 'FF', 'passing_grade_estimate' => 88.00],
            ['nama' => 'Kedokteran Gigi', 'fakultas' => 'FKG', 'passing_grade_estimate' => 91.00],
            ['nama' => 'Teknik Sipil', 'fakultas' => 'FT', 'passing_grade_estimate' => 86.00],
            ['nama' => 'Hubungan Internasional', 'fakultas' => 'FISIPOL', 'passing_grade_estimate' => 85.00],
        ],
        'IPB' => [
            ['nama' => 'Teknik Informatika', 'fakultas' => 'FASILKOM-MTI', 'passing_grade_estimate' => 87.00],
            ['nama' => 'Manajemen', 'fakultas' => 'FEM', 'passing_grade_estimate' => 82.00],
            ['nama' => 'Statistika', 'fakultas' => 'FMIPA', 'passing_grade_estimate' => 85.00],
            ['nama' => 'Gizi Masyarakat', 'fakultas' => 'FEMA', 'passing_grade_estimate' => 83.00],
            ['nama' => 'Agribisnis', 'fakultas' => 'FEM', 'passing_grade_estimate' => 81.00],
            ['nama' => 'Biologi', 'fakultas' => 'FMIPA', 'passing_grade_estimate' => 82.00],
            ['nama' => 'Kedokteran Hewan', 'fakultas' => 'FKH', 'passing_grade_estimate' => 84.00],
            ['nama' => 'Ilmu Komputer', 'fakultas' => 'FASILKOM-MTI', 'passing_grade_estimate' => 86.00],
        ],
        'UNAIR' => [
            ['nama' => 'Kedokteran', 'fakultas' => 'FK', 'passing_grade_estimate' => 92.00],
            ['nama' => 'Hukum', 'fakultas' => 'FH', 'passing_grade_estimate' => 82.00],
            ['nama' => 'Ekonomi Islam', 'fakultas' => 'FEB', 'passing_grade_estimate' => 79.00],
            ['nama' => 'Psikologi', 'fakultas' => 'FPSI', 'passing_grade_estimate' => 83.00],
            ['nama' => 'Farmasi', 'fakultas' => 'FF', 'passing_grade_estimate' => 86.00],
            ['nama' => 'Kedokteran Gigi', 'fakultas' => 'FKG', 'passing_grade_estimate' => 88.00],
        ],
        'UNDIP' => [
            ['nama' => 'Kedokteran', 'fakultas' => 'FK', 'passing_grade_estimate' => 88.00],
            ['nama' => 'Teknik Informatika', 'fakultas' => 'FSM', 'passing_grade_estimate' => 83.00],
            ['nama' => 'Hukum', 'fakultas' => 'FH', 'passing_grade_estimate' => 79.00],
            ['nama' => 'Teknik Sipil', 'fakultas' => 'FT', 'passing_grade_estimate' => 80.00],
            ['nama' => 'Akuntansi', 'fakultas' => 'FEB', 'passing_grade_estimate' => 80.00],
        ],
        'UB' => [
            ['nama' => 'Kedokteran', 'fakultas' => 'FK', 'passing_grade_estimate' => 87.00],
            ['nama' => 'Ilmu Komputer', 'fakultas' => 'FILKOM', 'passing_grade_estimate' => 82.00],
            ['nama' => 'Hukum', 'fakultas' => 'FH', 'passing_grade_estimate' => 78.00],
            ['nama' => 'Manajemen', 'fakultas' => 'FEB', 'passing_grade_estimate' => 79.00],
            ['nama' => 'Teknik Sipil', 'fakultas' => 'FT', 'passing_grade_estimate' => 78.00],
        ],
        'UNPAD' => [
            ['nama' => 'Kedokteran', 'fakultas' => 'FK', 'passing_grade_estimate' => 89.00],
            ['nama' => 'Hukum', 'fakultas' => 'FH', 'passing_grade_estimate' => 80.00],
            ['nama' => 'Informatika', 'fakultas' => 'FMIPA', 'passing_grade_estimate' => 82.00],
            ['nama' => 'Farmasi', 'fakultas' => 'FF', 'passing_grade_estimate' => 85.00],
            ['nama' => 'Psikologi', 'fakultas' => 'FPSI', 'passing_grade_estimate' => 82.00],
        ],
        'ITS' => [
            ['nama' => 'Teknik Informatika', 'fakultas' => 'FTIK', 'passing_grade_estimate' => 91.00],
            ['nama' => 'Teknik Elektro', 'fakultas' => 'FTEE', 'passing_grade_estimate' => 88.00],
            ['nama' => 'Teknik Mesin', 'fakultas' => 'FTI', 'passing_grade_estimate' => 86.00],
            ['nama' => 'Matematika', 'fakultas' => 'FMIPA-ITS', 'passing_grade_estimate' => 83.00],
            ['nama' => 'Statistika', 'fakultas' => 'FMIPA-ITS', 'passing_grade_estimate' => 82.00],
        ],
        'UNHAS' => [
            ['nama' => 'Kedokteran', 'fakultas' => 'FK', 'passing_grade_estimate' => 86.00],
            ['nama' => 'Hukum', 'fakultas' => 'FH', 'passing_grade_estimate' => 76.00],
            ['nama' => 'Teknik Informatika', 'fakultas' => 'FT', 'passing_grade_estimate' => 79.00],
            ['nama' => 'Ilmu Keperawatan', 'fakultas' => 'FKep', 'passing_grade_estimate' => 75.00],
        ],
        'USU' => [
            ['nama' => 'Kedokteran', 'fakultas' => 'FK', 'passing_grade_estimate' => 85.00],
            ['nama' => 'Hukum', 'fakultas' => 'FH', 'passing_grade_estimate' => 74.00],
            ['nama' => 'Ilmu Komputer', 'fakultas' => 'FASILKOM-TI', 'passing_grade_estimate' => 77.00],
            ['nama' => 'Teknik Sipil', 'fakultas' => 'FT', 'passing_grade_estimate' => 75.00],
        ],
        'UNS' => [
            ['nama' => 'Kedokteran', 'fakultas' => 'FK', 'passing_grade_estimate' => 86.00],
            ['nama' => 'Hukum', 'fakultas' => 'FH', 'passing_grade_estimate' => 76.00],
            ['nama' => 'Informatika', 'fakultas' => 'FMIPA', 'passing_grade_estimate' => 80.00],
            ['nama' => 'Manajemen', 'fakultas' => 'FEB', 'passing_grade_estimate' => 77.00],
        ],
        'UNILA' => [
            ['nama' => 'Kedokteran', 'fakultas' => 'FK', 'passing_grade_estimate' => 80.00],
            ['nama' => 'Hukum', 'fakultas' => 'FH', 'passing_grade_estimate' => 72.00],
            ['nama' => 'Ilmu Komputer', 'fakultas' => 'FMIPA', 'passing_grade_estimate' => 74.00],
        ],
        'UNSRI' => [
            ['nama' => 'Kedokteran', 'fakultas' => 'FK', 'passing_grade_estimate' => 81.00],
            ['nama' => 'Hukum', 'fakultas' => 'FH', 'passing_grade_estimate' => 72.00],
            ['nama' => 'Teknik Informatika', 'fakultas' => 'FT', 'passing_grade_estimate' => 75.00],
        ],
        'UIN JAKARTA' => [
            ['nama' => 'Kedokteran dan Ilmu Kesehatan', 'fakultas' => 'FKIK', 'passing_grade_estimate' => 80.00],
            ['nama' => 'Sistem Informasi', 'fakultas' => 'FST', 'passing_grade_estimate' => 73.00],
            ['nama' => 'Hukum', 'fakultas' => 'FSH', 'passing_grade_estimate' => 71.00],
        ],
    ];

    /** logo.dev /name/ endpoint: https://img.logo.dev/name/{Name}?token=...&retina=true */
    private const LOGO_BASE  = 'https://img.logo.dev/name';
    private const MIN_BYTES  = 1200;

    public function run(): void
    {
        $this->command->info('📡 Fetching PTN universities from api.co.id...');

        $imported = $this->fetchFromApi();

        if ($imported === 0) {
            $this->command->warn('⚠️  API unavailable, using fallback data...');
            $this->seedFallback();
        } else {
            $this->command->info("✅ {$imported} kampus imported from API.");
        }

        $this->seedJurusan();
        $this->fetchLogos();
    }

    /**
     * Auto-fetch logos for all campuses that don't have one yet.
     * Non-blocking: errors are silently skipped.
     */
    private function fetchLogos(): void
    {
        $token     = config('services.logoDev.token', 'pk_a1dih9BDRCmE0bDH9EgSUg');
        $noLogo    = Kampus::whereNull('logo_url')->orWhere('logo_url', '')->get();
        $saved     = 0;

        $this->command->info("🖼️  Fetching logos for {$noLogo->count()} kampus via logo.dev...");

        foreach ($noLogo as $kampus) {
            $url = $this->logoUrl($kampus->nama, $kampus->akronim, $token);
            if ($url) {
                $kampus->update(['logo_url' => $url]);
                $saved++;
            }
            usleep(100_000); // 100ms rate-limit
        }

        $this->command->info("✅ {$saved} logos saved.");
    }

    private function logoUrl(string $nama, string $akronim, string $token): ?string
    {
        $candidates = array_unique(array_filter([
            $nama,
            $this->toEnglish($nama),
            strlen($akronim) >= 2 ? $akronim : null,
            $this->stripPrefix($nama),
        ]));

        foreach ($candidates as $c) {
            $url = self::LOGO_BASE . '/' . urlencode($c) . '?token=' . $token . '&retina=true';
            try {
                $response = Http::timeout(12)->get($url);
                if ($response->status() === 404) continue;
                if (!$response->successful())    continue;

                $ct = strtolower($response->header('Content-Type') ?? '');
                $isRaster = str_contains($ct, 'image/png')
                         || str_contains($ct, 'image/webp')
                         || str_contains($ct, 'image/jpeg');

                if ($isRaster && strlen($response->body()) >= self::MIN_BYTES) return $url;
            } catch (\Exception) {
                continue;
            }
            usleep(80_000);
        }
        return null;
    }

    private function toEnglish(string $nama): string
    {
        $map = [
            'Institut Teknologi' => 'Institute of Technology',
            'Universitas'        => 'University of',
            'Institut'           => 'Institute of',
            'Sekolah Tinggi'     => 'College of',
            'Politeknik'         => 'Polytechnic',
        ];
        foreach ($map as $id => $en) {
            if (str_starts_with($nama, $id)) {
                return $en . ' ' . substr($nama, strlen($id) + 1);
            }
        }
        return $nama;
    }

    private function stripPrefix(string $nama): string
    {
        foreach (['Universitas ', 'Institut ', 'Sekolah Tinggi ', 'Politeknik ', 'Akademi '] as $p) {
            if (str_starts_with($nama, $p)) return substr($nama, strlen($p));
        }
        return $nama;
    }


    private function fetchFromApi(): int
    {
        $total = 0;
        $page  = 1;

        try {
            do {
                $res = Http::timeout(15)
                    ->withHeaders(['x-api-co-id' => $this->apiKey])
                    ->get($this->baseUrl, ['group' => 'PTN', 'page' => $page, 'size' => 500]);

                if (!$res->successful()) break;

                $body      = $res->json();
                $unis      = $body['data'] ?? [];
                $totalPage = $body['paging']['total_page'] ?? 1;

                foreach ($unis as $u) {
                    Kampus::updateOrCreate(
                        ['api_id' => $u['id']],
                        [
                            'nama'     => $u['name'],
                            'akronim'  => $u['short_name'] ?? $this->makeAkronim($u['name']),
                            'kota'     => $u['regency_name'] ?? null,
                            'provinsi' => $u['province_name'] ?? null,
                            'tipe'     => 'PTN',
                            'group'    => 'PTN',
                            'alamat'   => $u['address'] ?? null,
                            'lat'      => $u['latitude'] ?? null,
                            'lng'      => $u['longitude'] ?? null,
                        ]
                    );
                    $total++;
                }

                $page++;
                usleep(200000);
            } while ($page <= $totalPage);
        } catch (\Exception $e) {
            $this->command->warn('API error: ' . $e->getMessage());
        }

        return $total;
    }

    private function seedFallback(): void
    {
        $fallback = [
            ['nama' => 'Universitas Indonesia', 'akronim' => 'UI', 'kota' => 'Kota Depok', 'provinsi' => 'Jawa Barat'],
            ['nama' => 'Institut Teknologi Bandung', 'akronim' => 'ITB', 'kota' => 'Kota Bandung', 'provinsi' => 'Jawa Barat'],
            ['nama' => 'Universitas Gadjah Mada', 'akronim' => 'UGM', 'kota' => 'Kota Yogyakarta', 'provinsi' => 'D.I. Yogyakarta'],
            ['nama' => 'Institut Pertanian Bogor', 'akronim' => 'IPB', 'kota' => 'Kota Bogor', 'provinsi' => 'Jawa Barat'],
            ['nama' => 'Universitas Airlangga', 'akronim' => 'UNAIR', 'kota' => 'Kota Surabaya', 'provinsi' => 'Jawa Timur'],
            ['nama' => 'Institut Teknologi Sepuluh Nopember', 'akronim' => 'ITS', 'kota' => 'Kota Surabaya', 'provinsi' => 'Jawa Timur'],
            ['nama' => 'Universitas Diponegoro', 'akronim' => 'UNDIP', 'kota' => 'Kota Semarang', 'provinsi' => 'Jawa Tengah'],
            ['nama' => 'Universitas Brawijaya', 'akronim' => 'UB', 'kota' => 'Kota Malang', 'provinsi' => 'Jawa Timur'],
            ['nama' => 'Universitas Padjadjaran', 'akronim' => 'UNPAD', 'kota' => 'Kota Bandung', 'provinsi' => 'Jawa Barat'],
            ['nama' => 'Universitas Sebelas Maret', 'akronim' => 'UNS', 'kota' => 'Kota Surakarta', 'provinsi' => 'Jawa Tengah'],
            ['nama' => 'Universitas Sumatera Utara', 'akronim' => 'USU', 'kota' => 'Kota Medan', 'provinsi' => 'Sumatera Utara'],
            ['nama' => 'Universitas Hasanuddin', 'akronim' => 'UNHAS', 'kota' => 'Kota Makassar', 'provinsi' => 'Sulawesi Selatan'],
            ['nama' => 'Universitas Lampung', 'akronim' => 'UNILA', 'kota' => 'Kota Bandar Lampung', 'provinsi' => 'Lampung'],
            ['nama' => 'Universitas Sriwijaya', 'akronim' => 'UNSRI', 'kota' => 'Kota Palembang', 'provinsi' => 'Sumatera Selatan'],
            ['nama' => 'UIN Syarif Hidayatullah Jakarta', 'akronim' => 'UIN JAKARTA', 'kota' => 'Kota Tangerang Selatan', 'provinsi' => 'Banten'],
            ['nama' => 'Universitas Negeri Jakarta', 'akronim' => 'UNJ', 'kota' => 'Kota Jakarta Timur', 'provinsi' => 'D.K.I. Jakarta'],
            ['nama' => 'Universitas Negeri Yogyakarta', 'akronim' => 'UNY', 'kota' => 'Kota Yogyakarta', 'provinsi' => 'D.I. Yogyakarta'],
            ['nama' => 'Universitas Negeri Malang', 'akronim' => 'UM', 'kota' => 'Kota Malang', 'provinsi' => 'Jawa Timur'],
            ['nama' => 'Universitas Negeri Surabaya', 'akronim' => 'UNESA', 'kota' => 'Kota Surabaya', 'provinsi' => 'Jawa Timur'],
            ['nama' => 'Universitas Negeri Semarang', 'akronim' => 'UNNES', 'kota' => 'Kota Semarang', 'provinsi' => 'Jawa Tengah'],
            ['nama' => 'Universitas Negeri Makassar', 'akronim' => 'UNM', 'kota' => 'Kota Makassar', 'provinsi' => 'Sulawesi Selatan'],
            ['nama' => 'Universitas Negeri Padang', 'akronim' => 'UNP', 'kota' => 'Kota Padang', 'provinsi' => 'Sumatera Barat'],
            ['nama' => 'Universitas Andalas', 'akronim' => 'UNAND', 'kota' => 'Kota Padang', 'provinsi' => 'Sumatera Barat'],
            ['nama' => 'Universitas Riau', 'akronim' => 'UNRI', 'kota' => 'Kota Pekanbaru', 'provinsi' => 'Riau'],
            ['nama' => 'Universitas Mulawarman', 'akronim' => 'UNMUL', 'kota' => 'Kota Samarinda', 'provinsi' => 'Kalimantan Timur'],
            ['nama' => 'Universitas Tanjungpura', 'akronim' => 'UNTAN', 'kota' => 'Kota Pontianak', 'provinsi' => 'Kalimantan Barat'],
            ['nama' => 'Universitas Lambung Mangkurat', 'akronim' => 'ULM', 'kota' => 'Kota Banjarmasin', 'provinsi' => 'Kalimantan Selatan'],
            ['nama' => 'Universitas Sam Ratulangi', 'akronim' => 'UNSRAT', 'kota' => 'Kota Manado', 'provinsi' => 'Sulawesi Utara'],
            ['nama' => 'Universitas Pattimura', 'akronim' => 'UNPATTI', 'kota' => 'Kota Ambon', 'provinsi' => 'Maluku'],
            ['nama' => 'Universitas Cenderawasih', 'akronim' => 'UNCEN', 'kota' => 'Kota Jayapura', 'provinsi' => 'Papua'],
            ['nama' => 'Universitas Syiah Kuala', 'akronim' => 'USK', 'kota' => 'Kota Banda Aceh', 'provinsi' => 'Aceh'],
            ['nama' => 'Universitas Bengkulu', 'akronim' => 'UNIB', 'kota' => 'Kota Bengkulu', 'provinsi' => 'Bengkulu'],
            ['nama' => 'Universitas Jember', 'akronim' => 'UNEJ', 'kota' => 'Kota Jember', 'provinsi' => 'Jawa Timur'],
            ['nama' => 'Universitas Trunojoyo Madura', 'akronim' => 'UTM', 'kota' => 'Bangkalan', 'provinsi' => 'Jawa Timur'],
            ['nama' => 'Universitas Mataram', 'akronim' => 'UNRAM', 'kota' => 'Kota Mataram', 'provinsi' => 'Nusa Tenggara Barat'],
            ['nama' => 'Universitas Nusa Cendana', 'akronim' => 'UNDANA', 'kota' => 'Kota Kupang', 'provinsi' => 'Nusa Tenggara Timur'],
            ['nama' => 'Universitas Tadulako', 'akronim' => 'UNTAD', 'kota' => 'Kota Palu', 'provinsi' => 'Sulawesi Tengah'],
            ['nama' => 'Universitas Haluoleo', 'akronim' => 'UHO', 'kota' => 'Kota Kendari', 'provinsi' => 'Sulawesi Tenggara'],
            ['nama' => 'Universitas Palangka Raya', 'akronim' => 'UPR', 'kota' => 'Kota Palangka Raya', 'provinsi' => 'Kalimantan Tengah'],
            ['nama' => 'Institut Teknologi Kalimantan', 'akronim' => 'ITK', 'kota' => 'Kota Balikpapan', 'provinsi' => 'Kalimantan Timur'],
            ['nama' => 'Institut Teknologi Sumatera', 'akronim' => 'ITERA', 'kota' => 'Kota Bandar Lampung', 'provinsi' => 'Lampung'],
        ];

        foreach ($fallback as $k) {
            Kampus::firstOrCreate(
                ['akronim' => $k['akronim']],
                array_merge($k, ['tipe' => 'PTN', 'group' => 'PTN'])
            );
        }
    }

    private function seedJurusan(): void
    {
        $defaultJurusan = [
            ['nama' => 'Teknik Informatika', 'fakultas' => 'Teknik', 'passing_grade_estimate' => 78.00],
            ['nama' => 'Manajemen', 'fakultas' => 'Ekonomi dan Bisnis', 'passing_grade_estimate' => 73.00],
            ['nama' => 'Hukum', 'fakultas' => 'Hukum', 'passing_grade_estimate' => 71.00],
            ['nama' => 'Pendidikan Matematika', 'fakultas' => 'FMIPA', 'passing_grade_estimate' => 68.00],
            ['nama' => 'Ilmu Komunikasi', 'fakultas' => 'FISIP', 'passing_grade_estimate' => 70.00],
        ];

        $count = 0;
        Kampus::all()->each(function (Kampus $kampus) use ($defaultJurusan, &$count) {
            $jurusanList = $this->jurusanData[$kampus->akronim] ?? $defaultJurusan;

            foreach ($jurusanList as $j) {
                Jurusan::firstOrCreate(
                    ['kampus_id' => $kampus->id, 'nama' => $j['nama']],
                    [
                        'fakultas'                => $j['fakultas'] ?? null,
                        'passing_grade_estimate'  => $j['passing_grade_estimate'] ?? 70.00,
                        'peminat_tahun_lalu'      => rand(200, 5000),
                    ]
                );
                $count++;
            }
        });

        $this->command->info("✅ {$count} jurusan seeded.");
    }

    private function makeAkronim(string $name): string
    {
        $words = explode(' ', $name);
        if (count($words) === 1) return strtoupper(substr($name, 0, 6));
        return strtoupper(implode('', array_map(fn($w) => $w[0] ?? '', $words)));
    }
}
