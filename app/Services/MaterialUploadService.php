<?php

namespace App\Services;

use App\Models\MaterialUpload;
use App\Models\AiDraftSoal;
use App\Models\Mapel;
use App\Models\SubMateri;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// smalot/pdfparser is optional — install via: composer require smalot/pdfparser
// If absent, falls back to Gemini Vision which natively supports PDF inline data.

class MaterialUploadService
{
    public function __construct(private GeminiService $gemini) {}

    /**
     * Store the file and dispatch all processing (extraction + AI) after the HTTP response.
     * This way nothing heavy blocks the user's browser.
     */
    public function process(UploadedFile $file, array $mapelIds, int $jumlahSoal, int $adminId): MaterialUpload
    {
        // Store file in local disk — the path survives after the HTTP response
        $path     = $file->store('uploads/materials', 'local');
        $fileType = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';

        $upload = MaterialUpload::create([
            'admin_id'           => $adminId,
            'filename'           => $file->getClientOriginalName(),
            'file_type'          => $fileType,
            'file_url'           => $path,
            'status'             => 'processing',
            'target_mapel_ids'   => $mapelIds,
            'jumlah_soal_target' => $jumlahSoal,
        ]);

        // Capture scalars only — no objects that may not survive serialisation
        $uploadId      = $upload->id;
        $storedPath    = $path;          // local storage path, readable after response
        $capturedType  = $fileType;
        $capturedMime  = $mimeType;
        $capturedIds   = $mapelIds;
        $capturedCount = $jumlahSoal;
        $serviceClass  = static::class;

        dispatch(function () use (
            $uploadId, $storedPath, $capturedType, $capturedMime,
            $capturedIds, $capturedCount, $serviceClass
        ) {
            /** @var MaterialUpload $upload */
            $upload = MaterialUpload::find($uploadId);
            if (!$upload) return;

            /** @var self $service */
            $service = app($serviceClass);

            // Read from the stored path (temp UploadedFile is gone by now)
            $absolutePath = Storage::disk('local')->path($storedPath);
            $text = $service->extractTextFromPath($absolutePath, $capturedType, $capturedMime, $uploadId);

            $service->generateSoalFromText($upload, $text, $capturedIds, $capturedCount);
        })->afterResponse();

        return $upload;
    }

    /**
     * Extract text from a stored absolute file path.
     * Public so the afterResponse closure can call it via app().
     */
    public function extractTextFromPath(string $path, string $ext, string $mime, int $uploadId): string
    {
        Log::info("MaterialUpload[$uploadId]: extracting text", ['ext' => $ext, 'path' => $path]);

        try {
            return match($ext) {
                'pdf'              => $this->extractPdfFromPath($path, $mime),
                'txt', 'md'        => file_get_contents($path),
                'docx'             => $this->extractDocxFromPath($path),
                'jpg', 'jpeg', 'png', 'webp' => $this->extractImageFromPath($path, $mime),
                default            => file_get_contents($path) ?: '',
            };
        } catch (\Exception $e) {
            Log::warning("MaterialUpload[$uploadId]: text extraction failed", ['error' => $e->getMessage()]);
            return '';
        }
    }

    private function extractPdfFromPath(string $path, string $mime): string
    {
        // 1. Try smalot/pdfparser for text-based PDFs (fastest, cheapest)
        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $text   = $parser->parseFile($path)->getText();
                if (!empty(trim($text))) {
                    Log::info('PDF text extracted via smalot/pdfparser');
                    return $text;
                }
            } catch (\Exception $e) {
                Log::warning('smalot/pdfparser failed, trying Gemini Vision', ['error' => $e->getMessage()]);
            }
        }

        // 2. Fallback: Gemini natively supports application/pdf inline data
        Log::info('Extracting PDF text via Gemini Vision (inline PDF)');
        return $this->extractImageFromPath($path, 'application/pdf');
    }

    private function extractDocxFromPath(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            return strip_tags(str_replace(['</w:p>', '</w:tr>'], "\n", $xml ?? ''));
        }
        return '';
    }

    private function extractImageFromPath(string $path, string $mime): string
    {
        $base64 = base64_encode(file_get_contents($path));
        $prompt = $mime === 'application/pdf'
            ? 'Ekstrak semua teks dari dokumen PDF ini. Pertahankan format asli. Output teks saja, tanpa penjelasan.'
            : 'Ekstrak semua teks dari gambar ini. Pertahankan format asli. Output teks saja, tanpa penjelasan.';

        $result = $this->gemini->analyzeImage($base64, $mime, $prompt);
        return $result['text'] ?? '';
    }

    /**
     * Generate draft soal from already-extracted text.
     * Called from within the afterResponse closure.
     */
    public function generateSoalFromText(MaterialUpload $upload, string $text, array $mapelIds, int $jumlahSoal): void
    {
        $uid = $upload->id;
        Log::info("MaterialUpload[$uid]: starting soal generation", [
            'text_length' => strlen($text),
            'mapel_ids'   => $mapelIds,
            'target'      => $jumlahSoal,
        ]);

        try {
            if (empty(trim($text))) {
                Log::warning("MaterialUpload[$uid]: extracted text is empty — marking failed");
                $upload->update(['status' => 'failed']);
                return;
            }

            $mapels = Mapel::whereIn('id', $mapelIds)->get();
            if ($mapels->isEmpty()) {
                Log::warning("MaterialUpload[$uid]: no valid mapel found", ['ids' => $mapelIds]);
                $upload->update(['status' => 'failed']);
                return;
            }

            $totalCreated   = 0;
            $soalPerMapel   = max(1, (int) ceil($jumlahSoal / $mapels->count()));
            // Use max 3000 chars of text to stay in token budget
            $textChunk      = mb_substr($text, 0, 6000);

            foreach ($mapels as $mapel) {
                // Ensure default sub_materi exists for this mapel
                $subMateri = SubMateri::where('mapel_id', $mapel->id)->first()
                    ?? SubMateri::firstOrCreate(
                        ['mapel_id' => $mapel->id, 'nama' => 'Umum'],
                        ['deskripsi' => 'Sub-materi umum (auto-created)']
                    );

                $prompt = $this->buildGeneratePrompt($textChunk, $mapel->nama, $soalPerMapel);

                try {
                    $result = $this->gemini->generateFlash($prompt, $upload->admin_id, 'material_upload');
                    $parsed = $this->parseAiSoalResponse($result['text']);

                    Log::info("MaterialUpload[$uid]: AI returned " . count($parsed) . " drafts for {$mapel->nama}");

                    foreach ($parsed as $draftData) {
                        if (!is_array($draftData) || empty($draftData['pertanyaan'] ?? '')) {
                            continue;
                        }
                        // Embed resolved IDs so approveDraft can use them directly
                        $draftData['_sub_materi_id'] = $subMateri->id;
                        $draftData['_mapel_id']       = $mapel->id;

                        AiDraftSoal::create([
                            'upload_id' => $upload->id,
                            'draft'     => $draftData,
                            'status'    => 'pending',
                        ]);
                        $totalCreated++;
                    }
                } catch (\Exception $e) {
                    Log::warning("MaterialUpload[$uid]: generation failed for {$mapel->nama}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $final = $totalCreated > 0 ? 'done' : 'failed';
            $upload->update(['status' => $final]);
            Log::info("MaterialUpload[$uid]: complete — $totalCreated drafts, status=$final");

        } catch (\Exception $e) {
            Log::error("MaterialUpload[$uid]: generateSoalFromText threw", ['error' => $e->getMessage()]);
            $upload->update(['status' => 'failed']);
        }
    }

    /**
     * Parse AI response — handles:
     * - JSON array or single object
     * - Markdown code fences
     * - Control characters inside JSON strings (AI bug)
     */
    public function parseAiSoalResponse(string $text): array
    {
        // Strip markdown fences
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $clean = preg_replace('/\s*```$/m', '', $clean ?? $text);
        $clean = trim($clean ?? $text);

        // Pass 1: direct decode
        $decoded = json_decode($clean, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $this->normaliseAiSoalArray($decoded);
        }

        // Pass 2: strip ASCII control characters (0x00-0x1F except tab/LF/CR)
        // This fixes "Control character error" — AI embeds raw newlines in strings
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean);
        // Also replace literal (unescaped) newlines inside quoted strings
        $sanitized = preg_replace_callback('/"((?:[^"\\\\]|\\\\.)*)"/s', function ($m) {
            // Replace actual newlines inside string literals with \n
            return '"' . str_replace(["\n", "\r"], ['\\n', '\\r'], $m[1]) . '"';
        }, $sanitized ?? $clean);

        $decoded = json_decode($sanitized, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            Log::info('parseAiSoalResponse: fixed control characters in JSON');
            return $this->normaliseAiSoalArray($decoded);
        }

        // Pass 3: extract the outermost [...] array and try again
        if (preg_match('/\[[\s\S]*\]/m', $sanitized ?? $clean, $m)) {
            $decoded = json_decode($m[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                Log::info('parseAiSoalResponse: extracted JSON via regex');
                return $this->normaliseAiSoalArray($decoded);
            }
        }

        Log::warning('AI returned invalid JSON after all repair attempts', [
            'error'   => json_last_error_msg(),
            'preview' => mb_substr($clean, 0, 500),
        ]);
        return [];
    }

    private function normaliseAiSoalArray(mixed $decoded): array
    {
        if (isset($decoded['pertanyaan'])) return [$decoded]; // single object
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function buildGeneratePrompt(string $text, string $mapelNama, int $jumlah): string
    {
        return <<<PROMPT
[SYSTEM]
Kamu adalah pembuat soal SNBT profesional Indonesia.
Buat soal HANYA berdasarkan konteks teks yang diberikan.
Output HANYA JSON array yang valid. JANGAN tambahkan teks apapun di luar JSON.

ATURAN JSON WAJIB:
- Seluruh output adalah satu JSON array
- Jangan gunakan markdown (```)
- Jangan ada baris baru (newline) di dalam nilai string JSON — ganti dengan spasi
- Semua tanda kutip dalam string harus di-escape dengan \"

[CONTEXT]
{$text}

[INSTRUCTION]
Buat tepat {$jumlah} soal pilihan ganda untuk mapel {$mapelNama} SNBT tingkat sedang.
Format JSON array (TANPA ```, TANPA newline dalam string):
[{"pertanyaan":"...","pilihan":{"A":"...","B":"...","C":"...","D":"...","E":"..."},"kunci":"B","pembahasan":"...","mapel":"{$mapelNama}","sub_materi":"...","tingkat_kesulitan":"sedang","tipe_soal":"MC"}]

Jumlah soal harus tepat {$jumlah}. Pastikan output valid JSON sebelum mengirim.
PROMPT;
    }
}
