<?php

namespace App\Console\Commands;

use App\Models\Kampus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ImportKampus extends Command
{
    protected $signature   = 'kampus:import
                              {--group=PTN  : PTN, PTS, or ALL}
                              {--size=500   : results per page}
                              {--skip-logos : Skip auto-fetch of logos from logo.dev (faster)}';
    protected $description = 'Import Indonesian universities from api.co.id, then auto-fetch logos via logo.dev';

    private string $baseUrl = 'https://use.api.co.id/regional/indonesia/universities';

    // ── logo.dev ─────────────────────────────────────────────
    // Correct endpoint: https://img.logo.dev/name/{Name}?token=...&retina=true
    private const LOGO_BASE  = 'https://img.logo.dev/name';
    private const MIN_BYTES  = 1200;

    public function handle(): int
    {
        $apiKey = config('services.apicoId.key');
        if (!$apiKey) {
            $this->error('APICOIND_KEY not set in .env');
            return self::FAILURE;
        }

        $filterGroup = strtoupper($this->option('group'));
        $size        = min((int) $this->option('size'), 1000);
        $skipLogos   = $this->option('skip-logos');
        $page        = 1;
        $total       = 0;
        $totalPages  = 1;

        $this->info("📡 Fetching universities from api.co.id (filter: {$filterGroup})...");
        $bar = null;

        do {
            try {
                $response = Http::timeout(20)
                    ->withHeaders(['x-api-co-id' => $apiKey])
                    ->get($this->baseUrl, ['page' => $page, 'size' => $size]);
            } catch (\Exception $e) {
                $this->error("Request failed page {$page}: " . $e->getMessage());
                break;
            }

            if (!$response->successful()) {
                $this->error("API error {$response->status()} on page {$page}");
                break;
            }

            $body = $response->json();

            if (!($body['is_success'] ?? true)) {
                $this->error('API returned is_success=false: ' . ($body['message'] ?? 'unknown'));
                break;
            }

            $universities = $body['data'] ?? [];

            $paging     = $body['paging'] ?? [];
            $totalItems = $paging['total_item'] ?? $paging['total_items'] ?? count($universities);
            $totalPages = $paging['total_page'] ?? $paging['total_pages'] ?? ceil($totalItems / $size);
            $totalPages = max(1, (int) $totalPages);

            if ($page === 1) {
                $this->line("  Total items: {$totalItems}, Pages: {$totalPages}");
                $bar = $this->output->createProgressBar($totalItems);
                $bar->start();
            }

            foreach ($universities as $u) {
                if (!isset($u['id']) && !isset($u['name'])) continue;

                $apiId  = $u['id']   ?? null;
                $name   = $u['name'] ?? ($u['nama'] ?? 'Unknown');
                $group  = strtoupper($u['group'] ?? ($u['type'] ?? 'PTN'));

                if ($filterGroup !== 'ALL' && $group !== $filterGroup) {
                    if ($bar) $bar->advance();
                    continue;
                }

                $akronim = $u['short_name'] ?? ($u['akronim'] ?? $this->makeAkronim($name));

                $data = [
                    'nama'     => $name,
                    'akronim'  => $akronim ?: $this->makeAkronim($name),
                    'kota'     => $u['regency_name']  ?? ($u['kota']     ?? null),
                    'provinsi' => $u['province_name'] ?? ($u['provinsi'] ?? null),
                    'tipe'     => $this->mapTipe($u['type'] ?? ''),
                    'group'    => $group,
                    'alamat'   => $u['address'] ?? null,
                    'lat'      => $u['latitude']  ?? null,
                    'lng'      => $u['longitude'] ?? null,
                ];

                /** @var Kampus $kampusRecord */
                if ($apiId) {
                    $kampusRecord = Kampus::updateOrCreate(['api_id' => $apiId], $data);
                } else {
                    $kampusRecord = Kampus::firstOrCreate(['nama' => $name], $data);
                }

                // ── Auto-fetch logo if missing ────────────────────
                if (!$skipLogos && empty($kampusRecord->logo_url)) {
                    $logoUrl = $this->findLogoUrl($name, $kampusRecord->akronim);
                    if ($logoUrl) {
                        $kampusRecord->update(['logo_url' => $logoUrl]);
                    }
                    usleep(80_000); // 80ms pause per logo request
                }
                // ──────────────────────────────────────────────────

                $total++;
                if ($bar) $bar->advance();
            }

            $page++;
            usleep(250_000); // 250ms — respect API rate limit

        } while ($page <= $totalPages);

        if ($bar) { $bar->finish(); $this->newLine(); }

        $this->info("✅ Done! {$total} kampus imported/updated.");
        return self::SUCCESS;
    }

    // ── logo.dev helpers ─────────────────────────────────────

    /**
     * Try multiple name variants against logo.dev.
     * Returns the first URL that resolves to a real (non-placeholder) logo.
     */
    private function findLogoUrl(string $nama, string $akronim): ?string
    {
        $token = config('services.logoDev.token', 'pk_a1dih9BDRCmE0bDH9EgSUg');
        foreach ($this->buildCandidates($nama, $akronim) as $candidate) {
            $url = self::LOGO_BASE . '/' . urlencode($candidate)
                 . '?token=' . $token . '&retina=true';

            if ($this->isRealLogo($url)) {
                return $url;
            }
        }
        return null;
    }

    private function buildCandidates(string $nama, string $akronim): array
    {
        $candidates = [];

        // 1. Full name as-is
        $candidates[] = $nama;

        // 2. English equivalent
        $english = $this->toEnglishName($nama);
        if ($english !== $nama) $candidates[] = $english;

        // 3. Acronym alone (UI, ITB, UGM etc.)
        if (strlen($akronim) >= 2) $candidates[] = $akronim;

        // 4. Stripped prefix
        $stripped = $this->stripCommonPrefixes($nama);
        if ($stripped && $stripped !== $nama) $candidates[] = $stripped;

        return array_values(array_unique(array_filter($candidates)));
    }

    private function toEnglishName(string $nama): string
    {
        $map = [
            'Institut Teknologi' => 'Institute of Technology',
            'Universitas'        => 'University of',
            'Institut'           => 'Institute of',
            'Sekolah Tinggi'     => 'College of',
            'Politeknik'         => 'Polytechnic',
            'Akademi'            => 'Academy of',
        ];
        foreach ($map as $id => $en) {
            if (Str::startsWith($nama, $id)) {
                return $en . ' ' . Str::after($nama, $id . ' ');
            }
        }
        return $nama;
    }

    private function stripCommonPrefixes(string $nama): string
    {
        foreach (['Universitas ', 'Institut ', 'Sekolah Tinggi ', 'Politeknik ', 'Akademi '] as $p) {
            if (Str::startsWith($nama, $p)) return Str::after($nama, $p);
        }
        return $nama;
    }

    /**
     * Returns true if logo.dev returned a real raster logo (not a placeholder SVG).
     * Uses GET + Content-Type check — HEAD is unreliable on CDNs.
     */
    private function isRealLogo(string $url): bool
    {
        try {
            $response = Http::timeout(12)->get($url);
            if ($response->status() === 404) return false;
            if (!$response->successful())    return false;

            $ct = strtolower($response->header('Content-Type') ?? '');
            $isRaster = str_contains($ct, 'image/png')
                     || str_contains($ct, 'image/webp')
                     || str_contains($ct, 'image/jpeg');

            return $isRaster && strlen($response->body()) >= self::MIN_BYTES;
        } catch (\Exception) {
            return false;
        }
    }

    // ── Existing helpers ──────────────────────────────────────

    private function makeAkronim(string $name): string
    {
        $stop  = ['universitas', 'institut', 'sekolah', 'tinggi', 'politeknik', 'akademi', 'negeri', 'swasta', 'indonesia'];
        $words = array_filter(
            explode(' ', strtolower($name)),
            fn($w) => !in_array($w, $stop) && strlen($w) > 1
        );
        if (empty($words)) return strtoupper(substr($name, 0, 5));
        return strtoupper(implode('', array_map(fn($w) => $w[0], array_values($words))));
    }

    private function mapTipe(string $type): string
    {
        return match(strtolower(trim($type))) {
            'politeknik' => 'POLITEKNIK',
            'akademi'    => 'AKADEMI',
            'institut'   => 'INSTITUT',
            default      => 'UNIVERSITAS',
        };
    }
}
