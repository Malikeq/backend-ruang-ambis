<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Kampus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AdminKampusController extends Controller
{
    // logo.dev /name/ endpoint: https://img.logo.dev/name/{Name}?token=...&retina=true
    private const LOGO_BASE = 'https://img.logo.dev/name';
    private const MIN_BYTES = 1200;

    /** GET /admin/kampus — paginated list with logo status */
    public function index(Request $request): JsonResponse
    {
        $kampus = Kampus::query()
            ->when($request->search, fn($q, $s) =>
                $q->where('nama', 'like', "%{$s}%")
                  ->orWhere('akronim', 'like', "%{$s}%")
            )
            ->when($request->no_logo === 'true', fn($q) =>
                $q->whereNull('logo_url')->orWhere('logo_url', '')
            )
            ->select(['id', 'nama', 'akronim', 'kota', 'provinsi', 'tipe', 'logo_url'])
            ->orderBy('nama')
            ->paginate(40);

        return response()->json([
            'success' => true,
            'data'    => $kampus,
            'stats'   => [
                'total'     => Kampus::count(),
                'with_logo' => Kampus::whereNotNull('logo_url')->where('logo_url', '!=', '')->count(),
            ],
        ]);
    }

    /**
     * POST /admin/kampus/{kampus}/fetch-logo
     * Immediately attempt to fetch a logo for a single kampus.
     */
    public function fetchLogo(Kampus $kampus): JsonResponse
    {
        $url = $this->findLogoUrl($kampus->nama, $kampus->akronim);

        if ($url) {
            $kampus->update(['logo_url' => $url]);
            return response()->json(['success' => true, 'logo_url' => $url, 'message' => 'Logo ditemukan dan disimpan!']);
        }

        return response()->json(['success' => false, 'message' => 'Tidak ada logo ditemukan di logo.dev untuk kampus ini.'], 404);
    }

    /**
     * POST /admin/kampus/fetch-all-logos
     * Queues the fetch command as a background Artisan call (non-blocking).
     */
    public function fetchAllLogos(Request $request): JsonResponse
    {
        $limit = min((int) ($request->limit ?? 100), 500);
        $force = (bool) ($request->force ?? false);

        // Run artisan in background
        $cmd  = "php artisan kampus:fetch-logos --limit={$limit}";
        if ($force) $cmd .= ' --force';

        // Dispatch via proc_open so it's truly non-blocking
        $cwd = base_path();
        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, $cwd);

        if (is_resource($proc)) {
            proc_close($proc);
        }

        return response()->json([
            'success' => true,
            'message' => "Proses fetch logo untuk {$limit} kampus dimulai di background. Refresh halaman beberapa saat lagi.",
        ]);
    }

    // ── logo.dev helpers (same logic as Artisan commands) ────

    private function findLogoUrl(string $nama, string $akronim): ?string
    {
        $token = config('services.logoDev.token', 'pk_a1dih9BDRCmE0bDH9EgSUg');

        foreach ($this->buildCandidates($nama, $akronim) as $candidate) {
            $url = self::LOGO_BASE . '/' . urlencode($candidate)
                 . '?token=' . $token . '&retina=true';

            if ($this->isRealLogo($url)) {
                return $url;
            }
            usleep(80_000);
        }

        return null;
    }

    private function buildCandidates(string $nama, string $akronim): array
    {
        return array_values(array_unique(array_filter([
            $nama,
            $this->toEnglishName($nama),
            strlen($akronim) >= 2 ? $akronim : null,
            $this->stripCommonPrefixes($nama),
        ])));
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
}
