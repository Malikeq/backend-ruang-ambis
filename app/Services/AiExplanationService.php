<?php

namespace App\Services;

use App\Models\Soal;
use App\Models\AiExplanation;
use App\Models\WeaknessReport;
use Illuminate\Support\Facades\DB;

class AiExplanationService
{
    public function __construct(private GeminiService $gemini) {}

    /**
     * Get DCSEF explanation for a soal — returns cache if exists, generates otherwise.
     */
    public function getExplanation(Soal $soal, int $userId): array
    {
        // Check cache first
        $cached = AiExplanation::where('soal_id', $soal->id)->first();

        if ($cached) {
            $cached->increment('hit_count');
            $data = json_decode($cached->konten_cached, true);
            $data['from_cache'] = true;
            return $data;
        }

        // Generate fresh explanation
        $prompt   = $this->buildDCSEFPrompt($soal);
        $result   = $this->gemini->generateFlash($prompt, $userId, 'ai_explanation');
        $analysis = $this->gemini->parseJson($result['text']);

        // Persist to cache
        AiExplanation::create([
            'soal_id'       => $soal->id,
            'konten_cached' => json_encode($analysis),
            'model_used'    => 'gemini-1.5-flash',
            'token_used'    => $result['token_in'] + $result['token_out'],
            'generated_by'  => $userId,
            'hit_count'     => 0,
        ]);

        // Update weakness report
        $this->updateWeakness($soal, $userId, false);

        $analysis['from_cache'] = false;
        return $analysis;
    }

    /**
     * Handle "Tanya AI" — free-form follow-up question on a soal.
     */
    public function tanya(Soal $soal, string $pertanyaan, int $userId): string
    {
        $mapelNama = $soal->mapel->nama ?? 'SNBT';
        $prompt = <<<PROMPT
Kamu adalah tutor SNBT ahli. Berikut soal dan pertanyaan lanjutan dari siswa.

SOAL: {$soal->konten}

PERTANYAAN SISWA: {$pertanyaan}

Berikan penjelasan singkat, jelas, dan langsung menjawab pertanyaan siswa. Gunakan bahasa Indonesia. Maksimal 200 kata.
PROMPT;

        $result = $this->gemini->generateFlash($prompt, $userId, 'tanya_ai');
        return $result['text'];
    }

    /**
     * Update or create weakness_reports after a soal is answered.
     */
    public function updateWeakness(Soal $soal, int $userId, bool $isCorrect): void
    {
        $report = WeaknessReport::firstOrCreate(
            ['user_id' => $userId, 'sub_materi_id' => $soal->sub_materi_id],
            ['mapel_id' => $soal->mapel_id, 'attempt_count' => 0, 'correct_count' => 0, 'accuracy_rate' => 0]
        );

        $report->attempt_count++;
        if ($isCorrect) $report->correct_count++;
        $report->accuracy_rate = ($report->correct_count / $report->attempt_count) * 100;
        $report->is_flagged    = $report->accuracy_rate < 60;
        $report->last_seen     = now();
        $report->save();
    }

    private function buildDCSEFPrompt(Soal $soal): string
    {
        $pilihan = $soal->pilihan_jawaban->map(fn($p) => "{$p->label}) {$p->konten}")->implode("\n");
        $mapelNama  = $soal->mapel->nama ?? 'SNBT';
        $subMateri  = $soal->sub_materi->nama ?? '';

        return <<<PROMPT
[SYSTEM]
Kamu adalah tutor SNBT expert Indonesia. Analisa soal berikut menggunakan kerangka DCSEF.
Output HARUS JSON valid. Jangan skip tahap apapun.

[MAPEL CONTEXT]
Mapel: {$mapelNama}
Sub-materi: {$subMateri}

[SOAL]
{$soal->konten}

Pilihan:
{$pilihan}

[INSTRUKSI]
Hasilkan analisa JSON dengan struktur PERSIS berikut:
{
  "classifier": {
    "mapel": "{$mapelNama}",
    "sub_materi": "{$subMateri}",
    "tipe": "pilihan ganda",
    "estimasi_kesulitan": "sedang",
    "jebakan_terdeteksi": ["..."]
  },
  "dekonstruksi": {
    "diketahui": ["..."],
    "ditanya": "...",
    "jebakan": ["..."],
    "kata_kunci": ["..."]
  },
  "strategi": {
    "konsep_utama": "...",
    "rumus": "...",
    "kapan_pakai": "...",
    "bedakan_dengan": "...",
    "tips_cepat": "..."
  },
  "eksekusi": {
    "langkah": [
      {"no": 1, "aksi": "...", "hasil": null}
    ]
  },
  "output": {
    "jawaban_akhir": "...",
    "opsi_benar": "A",
    "cara_cepat": "...",
    "waktu_ideal_detik": 90
  },
  "weakness_tags": []
}

[SELF-VERIFY]
Sebelum output final: hitung ulang jawaban. Jika hasilnya berbeda, koreksi langkah eksekusi.
PROMPT;
    }
}
