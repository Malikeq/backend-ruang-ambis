<?php

namespace App\Http\Controllers\Api\AI;

use App\Http\Controllers\Controller;
use App\Services\AiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiPhotoController extends Controller
{
    public function __construct(private AiService $gemini) {}

    public function solve(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $file     = $request->file('image');
        $base64   = base64_encode(file_get_contents($file->getRealPath()));
        $mimeType = $file->getMimeType() ?? 'image/jpeg';

        $prompt = <<<PROMPT
[SYSTEM]
Kamu adalah tutor SNBT Indonesia expert. Seorang siswa mengirim foto soal.

[INSTRUKSI]
1. Identifikasi soal dari gambar (transkripsi teks jika perlu).
2. Tentukan mapel SNBT yang relevan.
3. Selesaikan soal menggunakan kerangka DCSEF (Dekonstruksi, Strategi, Eksekusi, Output).
4. Berikan cara cepat (shortcut) jika ada.
5. Output sebagai JSON valid.

Format output:
{
  "soal_terdeteksi": "teks soal yang terdeteksi dari foto",
  "mapel": "...",
  "dekonstruksi": {"diketahui": [], "ditanya": "..."},
  "strategi": {"konsep": "...", "rumus": "...", "tips_cepat": "..."},
  "eksekusi": {"langkah": [{"no": 1, "aksi": "...", "hasil": "..."}]},
  "output": {"jawaban_akhir": "...", "cara_cepat": "..."}
}
PROMPT;

        try {
            $result = $this->gemini->analyzeImage($base64, $mimeType, $prompt, $request->user()->id);
            $analysis = $this->gemini->parseJson($result['text']);

            return response()->json(['success' => true, 'data' => $analysis]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menganalisis gambar. Pastikan foto jelas dan coba lagi.',
            ], 503);
        }
    }
}
