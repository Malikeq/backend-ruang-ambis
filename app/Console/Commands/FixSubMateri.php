<?php

namespace App\Console\Commands;

use App\Models\AiDraftSoal;
use App\Models\Soal;
use App\Models\SubMateri;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixSubMateri extends Command
{
    protected $signature   = 'soal:fix-sub-materi {--dry-run : Preview changes without saving}';
    protected $description = 'Retroactively assign correct sub-materi to soal that were auto-created with generic "Umum".';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info($dryRun ? '[DRY RUN] No changes will be saved.' : 'Fixing sub-materi assignments...');

        // Find approved drafts that have a specific sub_materi field
        $drafts = AiDraftSoal::where('status', 'approved')
            ->whereNotNull('draft->sub_materi')
            ->get();

        $fixed = 0;
        $skipped = 0;

        foreach ($drafts as $draft) {
            $data = $draft->draft ?? [];
            $subMateriNama = trim($data['sub_materi'] ?? '');
            $mapelId       = $data['_mapel_id'] ?? null;

            if (!$subMateriNama || $subMateriNama === 'Umum' || !$mapelId) {
                $skipped++;
                continue;
            }

            // Find the approved soal that came from this draft
            // Match by mapel + konten (pertanyaan)
            $soal = Soal::where('mapel_id', $mapelId)
                ->where('konten', $data['pertanyaan'] ?? '')
                ->where('is_ai_generated', true)
                ->first();

            if (!$soal) {
                $skipped++;
                continue;
            }

            // Check if sub-materi is currently "Umum"
            $currentSub = SubMateri::find($soal->sub_materi_id);
            if ($currentSub && $currentSub->nama !== 'Umum') {
                $skipped++; // already has specific sub-materi
                continue;
            }

            // Create or find the correct sub-materi
            if (!$dryRun) {
                $newSub = SubMateri::firstOrCreate(
                    ['mapel_id' => $mapelId, 'nama' => $subMateriNama],
                    ['deskripsi' => "Sub-materi dari AI — {$subMateriNama}"]
                );
                $soal->update(['sub_materi_id' => $newSub->id]);
            }

            $this->line("  ✓ Soal #{$soal->id} → \"{$subMateriNama}\"" . ($dryRun ? ' (dry run)' : ''));
            $fixed++;
        }

        $this->info("Done. Fixed: {$fixed}, Skipped: {$skipped}");
        return self::SUCCESS;
    }
}
