<?php

namespace App\Console\Commands;

use App\Models\Kampus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * php artisan kampus:fetch-logos [--force] [--dry-run] [--limit=50] [--id=*]
 *
 * Fetches logos via logo.dev /name/ endpoint:
 *   https://img.logo.dev/name/{CompanyName}?token={token}&retina=true
 *
 * Tries multiple name variants per campus, saves the first that returns a real logo.
 */
class FetchKampusLogos extends Command
{
    protected $signature = 'kampus:fetch-logos
                            {--force    : Re-fetch even if logo_url already set}
                            {--dry-run  : Show what would be saved without writing}
                            {--limit=0  : Limit to N kampus (0 = all)}
                            {--id=*     : Only update specific kampus IDs}';

    protected $description = 'Fetch campus logos from logo.dev /name/ endpoint and save to kampus.logo_url';

    private const LOGO_BASE  = 'https://img.logo.dev/name';
    private const TOKEN_KEY  = 'services.logoDev.token';
    private const TOKEN_DEF  = 'pk_a1dih9BDRCmE0bDH9EgSUg';

    /**
     * Minimum body size (bytes) to consider a logo "real".
     * logo.dev returns a tiny ~800-byte placeholder SVG when nothing found.
     */
    private const MIN_BYTES = 1200;

    public function handle(): int
    {
        $force  = $this->option('force');
        $dryRun = $this->option('dry-run');
        $limit  = (int) $this->option('limit');
        $ids    = $this->option('id');
        $token  = config(self::TOKEN_KEY, self::TOKEN_DEF);

        $query = Kampus::query();

        if (!empty($ids)) {
            $query->whereIn('id', $ids);
        } elseif (!$force) {
            $query->where(fn($q) => $q->whereNull('logo_url')->orWhere('logo_url', ''));
        }

        if ($limit > 0) $query->limit($limit);

        $list  = $query->orderBy('id')->get();
        $total = $list->count();

        if ($total === 0) {
            $this->info('✅ No campuses to update. Use --force to re-fetch existing logos.');
            return self::SUCCESS;
        }

        $this->info("🔍 Processing {$total} campus(es)" . ($dryRun ? ' [DRY-RUN]' : '') . '...');
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $saved = $skipped = 0;

        foreach ($list as $kampus) {
            $url = $this->findLogoUrl($kampus->nama, $kampus->akronim, $token);

            if ($url) {
                if (!$dryRun) {
                    $kampus->update(['logo_url' => $url]);
                }
                $saved++;
                $bar->advance();
                $this->newLine();
                $this->line("  ✅ [{$kampus->id}] {$kampus->akronim} → {$url}");
            } else {
                $skipped++;
                $bar->advance();
            }

            usleep(150_000); // 150ms — stay within logo.dev rate limits
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done! Saved: {$saved} | No logo found: {$skipped}" . ($dryRun ? ' [DRY-RUN]' : ''));

        return self::SUCCESS;
    }

    /**
     * Build ordered candidates and return first URL that has a real logo.
     * Uses logo.dev /name/ endpoint: https://img.logo.dev/name/{Name}?token=...
     */
    private function findLogoUrl(string $nama, string $akronim, string $token): ?string
    {
        foreach ($this->buildCandidates($nama, $akronim) as $name) {
            $url = self::LOGO_BASE . '/' . urlencode($name)
                 . '?token=' . $token . '&retina=true';

            if ($this->isRealLogo($url)) {
                return $url;
            }

            usleep(100_000); // 100ms between checks
        }

        return null;
    }

    /**
     * Name variants to try against logo.dev /name/ endpoint.
     * Order: full Indonesian name → English translation → acronym → stripped prefix.
     */
    private function buildCandidates(string $nama, string $akronim): array
    {
        $list = [];

        // 1. Full Indonesian name  e.g. "Universitas Indonesia"
        $list[] = $nama;

        // 2. English equivalent  e.g. "University of Indonesia"
        $english = $this->toEnglishName($nama);
        if ($english !== $nama) $list[] = $english;

        // 3. Acronym alone  e.g. "UI", "ITB", "UGM"
        if (strlen($akronim) >= 2) $list[] = $akronim;

        // 4. Name without prefix  e.g. "Indonesia" from "Universitas Indonesia"
        $stripped = $this->stripPrefix($nama);
        if ($stripped && $stripped !== $nama) $list[] = $stripped;

        return array_values(array_unique(array_filter($list)));
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
            if (Str::startsWith($nama, $id . ' ')) {
                return $en . ' ' . Str::after($nama, $id . ' ');
            }
        }
        return $nama;
    }

    private function stripPrefix(string $nama): string
    {
        foreach (['Universitas ', 'Institut ', 'Sekolah Tinggi ', 'Politeknik ', 'Akademi '] as $p) {
            if (Str::startsWith($nama, $p)) return Str::after($nama, $p);
        }
        return $nama;
    }

    /**
     * Returns true if logo.dev returned a real raster logo for this URL.
     *
     * Why GET instead of HEAD:
     *  - CDNs commonly strip Content-Length on HEAD, making size detection impossible.
     *
     * What we check:
     *  1. HTTP 404 → logo.dev found nothing for this name (skip quickly)
     *  2. Content-Type must be a raster image (png/webp/jpeg)
     *     SVG placeholder → "image/svg+xml" → skip
     *  3. Body size >= MIN_BYTES to filter out tiny 1-pixel placeholder PNGs
     */
    private function isRealLogo(string $url): bool
    {
        try {
            $response = Http::timeout(12)->get($url);

            if ($response->status() === 404) return false;
            if (!$response->successful())    return false;

            $contentType = strtolower($response->header('Content-Type') ?? '');

            // Must be a raster image — skip SVG placeholders
            $isRaster = str_contains($contentType, 'image/png')
                     || str_contains($contentType, 'image/webp')
                     || str_contains($contentType, 'image/jpeg')
                     || str_contains($contentType, 'image/jpg');

            if (!$isRaster) return false;

            return strlen($response->body()) >= self::MIN_BYTES;

        } catch (\Exception) {
            return false;
        }
    }
}
