<?php

namespace App\Services;

use App\Models\Soal;
use App\Models\AiExplanation;
use App\Models\WeaknessReport;
use Illuminate\Support\Facades\DB;

class AiExplanationService
{
    public function __construct(private AiService $gemini) {}

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

        // Generate fresh (fallback if pre-generate missed)
        return $this->generateAndCache($soal, $userId);
    }

    /**
     * Pre-generate DSCEF at approve time — called by AdminAiController.
     * Skips if already cached (idempotent).
     */
    public function preGenerate(Soal $soal, int $adminId): void
    {
        if (AiExplanation::where('soal_id', $soal->id)->exists()) return;
        $this->generateAndCache($soal, $adminId);
    }

    /**
     * Core generate + cache logic, shared by getExplanation and preGenerate.
     */
    private function generateAndCache(Soal $soal, int $userId): array
    {
        $prompt   = $this->buildDCSEFPrompt($soal);
        $result   = $this->gemini->generateFlash($prompt, $userId, 'ai_explanation');
        $analysis = $this->gemini->parseJson($result['text']);

        AiExplanation::create([
            'soal_id'       => $soal->id,
            'konten_cached' => json_encode($analysis),
            'model_used'    => 'gemini-1.5-flash',
            'token_used'    => $result['token_in'] + $result['token_out'],
            'generated_by'  => $userId,
            'hit_count'     => 0,
        ]);

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
        $pilihan    = $soal->pilihan_jawaban->map(fn($p) => "{$p->label}) {$p->konten}")->implode("\n");
        $mapelKode  = $soal->mapel->kode  ?? '';
        $mapelNama  = $soal->mapel->nama  ?? 'SNBT';
        $subMateri  = $soal->sub_materi->nama ?? '';

        // Mapel-specific guidance for better analysis
        $mapelContext = match (strtoupper($mapelKode)) {
            'LBI', 'LBE' =>
                "Soal ini berbasis literasi/wacana. Fokus pada: pemahaman bacaan, inferensi, makna kata dalam konteks, ide pokok, struktur teks. " .
                "Untuk 'strategi.konsep_utama' gunakan: ide_pokok / inferensi / kosa_kata_kontekstual / struktur_teks / kelemahan_argumen.",
            'KMBM' =>
                "Soal kemampuan memahami bacaan dan menulis. Fokus pada: EYD, koherensi paragraf, ejaan, tanda baca, kata baku.",
            'PM', 'PK' =>
                "Soal matematika/kuantitatif. Tulis langkah perhitungan eksplisit di 'eksekusi.langkah'. Sertakan rumus yang dipakai.",
            'PU' =>
                "Soal penalaran umum. Fokus pada: silogisme, analogi, deret, hubungan antar variabel, penalaran logis.",
            'PPU' =>
                "Soal pengetahuan umum. Sertakan konteks historis/sains relevan di pembahasan.",
            default => "Soal SNBT umum.",
        };

        return <<<PROMPT
[SYSTEM]
Kamu adalah tutor SNBT expert Indonesia. Analisa soal menggunakan kerangka DSCEF.
Output HARUS JSON valid. Setiap field wajib diisi — jangan biarkan kosong atau null.

[MAPEL CONTEXT]
Mapel: {$mapelNama} ({$mapelKode})
Sub-materi: {$subMateri}
Panduan analisa: {$mapelContext}

[SOAL]
{$soal->konten}

Pilihan jawaban:
{$pilihan}

[INSTRUKSI DSCEF]
D = Distractor: Identifikasi MENGAPA setiap pilihan salah menarik perhatian (jebakan logis).
S = Stem: Dekonstruksi pertanyaan — apa yang diketahui, apa yang ditanya, kata kunci.
C = Context: Konsep/teori SNBT yang relevan untuk menjawab soal ini.
E = Execution: Langkah eksekusi solusi secara sistematis.
F = Framework: Tag framework SNBT yang diuji.

Hasilkan JSON dengan struktur PERSIS berikut (isi semua field):
{
  "classifier": {
    "mapel": "{$mapelNama}",
    "sub_materi": "{$subMateri}",
    "tipe": "pilihan ganda",
    "estimasi_kesulitan": "sedang",
    "jebakan_terdeteksi": ["deskripsi jebakan di pilihan X", "..."]
  },
  "dekonstruksi": {
    "diketahui": ["fakta 1 dari soal", "fakta 2"],
    "ditanya": "apa yang harus dicari/dijawab",
    "jebakan": ["kenapa pilihan X menarik tapi salah", "..."],
    "kata_kunci": ["kata1", "kata2"]
  },
  "strategi": {
    "konsep_utama": "nama konsep SNBT yang diuji",
    "rumus": "rumus/aturan jika ada, atau '-' jika tidak",
    "kapan_pakai": "kondisi/ciri soal yang membutuhkan konsep ini",
    "bedakan_dengan": "konsep lain yang sering tertukar",
    "tips_cepat": "cara kilat 30 detik untuk menjawab soal ini"
  },
  "eksekusi": {
    "langkah": [
      {"no": 1, "aksi": "langkah pertama", "hasil": "hasilnya"},
      {"no": 2, "aksi": "langkah kedua", "hasil": "hasilnya"}
    ]
  },
  "output": {
    "jawaban_akhir": "penjelasan mengapa jawaban ini benar",
    "opsi_benar": "A",
    "cara_cepat": "shortcut/eliminasi yang bisa dipakai",
    "waktu_ideal_detik": 90
  },
  "weakness_tags": ["tag1", "tag2"]
}

[SELF-VERIFY]
1. Pastikan opsi_benar merujuk ke pilihan yang memang benar berdasarkan konten soal.
2. Pastikan semua string dalam JSON tidak mengandung karakter kontrol atau newline — ganti dengan spasi.
3. Output HANYA JSON, tanpa teks lain.
PROMPT;
    }
}

