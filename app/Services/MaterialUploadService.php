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
    public function __construct(private AiService $gemini) {}


    /**
     * Store the file and dispatch all processing (extraction + AI) after the HTTP response.
     * This way nothing heavy blocks the user's browser.
     */
    /**
     * @param array $mapelIds          Target mapel IDs
     * @param array $targetSubMateri   Map of mapel_id => [sub_materi_name, ...]
     */
    public function process(
        UploadedFile $file,
        array $mapelIds,
        int   $jumlahSoal,
        int   $adminId,
        array $targetSubMateri = []
    ): MaterialUpload {
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

        // Pre-create sub_materi rows so they exist before AI runs
        foreach ($targetSubMateri as $mapelId => $subNames) {
            foreach ((array) $subNames as $name) {
                $trimmed = trim((string) $name);
                if ($trimmed === '') continue;
                SubMateri::firstOrCreate(
                    ['mapel_id' => (int) $mapelId, 'nama' => $trimmed],
                    ['deskripsi' => "Target sub-materi dari upload #{$upload->id}"]
                );
            }
        }

        $uploadId     = $upload->id;
        $storedPath   = $path;
        $capturedType = $fileType;
        $capturedMime = $mimeType;
        $capturedIds  = $mapelIds;
        $capturedCount = $jumlahSoal;
        $capturedSub  = $targetSubMateri; // mapel_id => [names]
        $serviceClass = static::class;

        dispatch(function () use (
            $uploadId, $storedPath, $capturedType, $capturedMime,
            $capturedIds, $capturedCount, $capturedSub, $serviceClass
        ) {
            // Remove the time limit — this runs after the HTTP response is already sent,
            // so there is no risk of blocking the user. AI generation can take several minutes.
            set_time_limit(0);
            ini_set('memory_limit', '256M');

            $upload = MaterialUpload::find($uploadId);
            if (!$upload) return;

            /** @var self $service */
            $service      = app($serviceClass);
            $absolutePath = Storage::disk('local')->path($storedPath);
            $text         = $service->extractTextFromPath($absolutePath, $capturedType, $capturedMime, $uploadId);

            $service->generateSoalFromText($upload, $text, $capturedIds, $capturedCount, $capturedSub);
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
    /**
     * @param array $targetSubMateri  mapel_id => [sub_materi_name, ...]
     */
    public function generateSoalFromText(
        MaterialUpload $upload,
        string $text,
        array  $mapelIds,
        int    $jumlahSoal,
        array  $targetSubMateri = []
    ): void {
        $uid = $upload->id;
        Log::info("MaterialUpload[$uid]: starting soal generation", [
            'text_length'      => strlen($text),
            'mapel_ids'        => $mapelIds,
            'target'           => $jumlahSoal,
            'target_sub_materi'=> $targetSubMateri,
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

            $totalCreated = 0;
            $soalPerMapel = max(1, (int) ceil($jumlahSoal / $mapels->count()));

            // Batch size: ask the provider how many soal it can handle per call.
            // Gemini → 10 (fast, reliable at 10)
            // Ollama → 5  (local models struggle with large JSON arrays)
            $batchSize = method_exists($this->gemini, 'recommendedBatchSize')
                ? $this->gemini->recommendedBatchSize()
                : 10;

            Log::info("MaterialUpload[$uid]: using batch_size={$batchSize} per AI call");

            $cleanText = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $text);
            $cleanText = preg_replace('/[\x{0080}-\x{009F}]/u', '', $cleanText ?? $text);
            $cleanText = preg_replace('/\s+/', ' ', $cleanText ?? $text);
            // 6000 chars gives ~1500 tokens — enough context per batch
            $textChunk = mb_substr(trim($cleanText), 0, 6000);

            foreach ($mapels as $mapel) {
                $subNames = array_values(array_filter(
                    array_map('trim', (array) ($targetSubMateri[$mapel->id] ?? []))
                ));

                $isWacanaMapel = in_array(strtoupper($mapel->kode), ['LBI', 'LBE', 'KMBM']);

                // ── Batched generation ──────────────────────────────────────────
                // We call AI in batches of $batchSize (max 10) until we reach $soalPerMapel.
                // Each batch retries up to 2 times if AI returns fewer than requested.
                // For wacana mapels a fresh wacana is generated each batch for variety.
                // ────────────────────────────────────────────────────────────────
                try {
                    $collectedDrafts    = [];
                    $collectedQuestions = []; // for deduplication
                    $stillNeeded        = $soalPerMapel;
                    $batchNum           = 0;

                    while ($stillNeeded > 0) {
                        $batchNum++;
                        $thisBatch = min($batchSize, $stillNeeded);

                        Log::info("MaterialUpload[$uid]: batch $batchNum — requesting $thisBatch soal for {$mapel->nama} ($stillNeeded remaining)");

                        // For wacana mapels: generate a new passage for each batch
                        if ($isWacanaMapel) {
                            $wacanaPrompt = $this->buildWacanaPrompt($textChunk, $mapel->nama, $mapel->kode);
                            try {
                                $wacanaResult = $this->gemini->generateFlash($wacanaPrompt, $upload->admin_id, 'material_upload');
                                $wacanaText   = trim($wacanaResult['text']);
                                if (str_starts_with($wacanaText, '{')) {
                                    $dec = json_decode($wacanaText, true);
                                    $wacanaText = $dec['wacana'] ?? $wacanaText;
                                }
                                Log::info("MaterialUpload[$uid]: batch $batchNum wacana generated (" . strlen($wacanaText) . " chars)");
                            } catch (\Exception $e) {
                                Log::warning("MaterialUpload[$uid]: batch $batchNum wacana failed, using text chunk", ['error' => $e->getMessage()]);
                                $wacanaText = mb_substr($textChunk, 0, 1500); // shorter fallback
                            }
                        }

                        // Inner retry: try up to 2 times to fill this batch
                        $batchGot     = [];
                        $batchRetries = 0;
                        $batchNeed    = $thisBatch;

                        while ($batchNeed > 0 && $batchRetries < 2) {
                            $batchRetries++;

                            if ($isWacanaMapel) {
                                $prompt = $this->buildWacanaSoalPrompt($wacanaText, $mapel->nama, $mapel->kode, $batchNeed, $subNames);
                            } else {
                                $prompt = $this->buildGeneratePrompt($textChunk, $mapel->nama, $batchNeed, $subNames);
                            }

                            if ($batchRetries > 1) {
                                $prompt .= "\n\n[CRITICAL] Output EXACTLY {$batchNeed} JSON objects. Previous attempt had too few. Do not stop early.";
                            }

                            try {
                                $result    = $this->gemini->generateFlash($prompt, $upload->admin_id, 'material_upload');
                                $newParsed = $this->parseAiSoalResponse($result['text']);

                                Log::info("MaterialUpload[$uid]: batch $batchNum retry $batchRetries → " . count($newParsed) . " soal (need $batchNeed)");

                                foreach ($newParsed as $item) {
                                    if (!is_array($item) || empty($item['pertanyaan'] ?? '')) continue;
                                    // Global dedup
                                    if (in_array($item['pertanyaan'], $collectedQuestions)) continue;
                                    $batchGot[]            = $item;
                                    $collectedQuestions[]  = $item['pertanyaan'];
                                    $batchNeed--;
                                    if ($batchNeed <= 0) break;
                                }
                            } catch (\Exception $e) {
                                Log::warning("MaterialUpload[$uid]: batch $batchNum retry $batchRetries failed", ['error' => $e->getMessage()]);
                            }
                        }

                        // Merge batch results into main collection
                        foreach ($batchGot as $item) {
                            $collectedDrafts[] = $item;
                            $stillNeeded--;
                            if ($stillNeeded <= 0) break;
                        }

                        Log::info("MaterialUpload[$uid]: after batch $batchNum — collected " . count($collectedDrafts) . "/{$soalPerMapel} for {$mapel->nama}");

                        // Safety: if a batch returned nothing at all (empty PDF, API error), stop
                        if (empty($batchGot)) {
                            Log::warning("MaterialUpload[$uid]: batch $batchNum returned 0 soal — stopping early for {$mapel->nama}");
                            break;
                        }
                    }

                    // Persist all collected drafts for this mapel
                    foreach ($collectedDrafts as $draftData) {
                        if (!is_array($draftData) || empty($draftData['pertanyaan'] ?? '')) continue;

                        $aiSubName = trim($draftData['sub_materi'] ?? '');

                        if (!empty($subNames)) {
                            $matched = collect($subNames)->first(
                                fn($n) => strtolower($n) === strtolower($aiSubName)
                            ) ?? $subNames[0];
                            $subMateriNama = $matched;
                        } else {
                            $subMateriNama = $aiSubName ?: 'Umum';
                        }

                        $subMateri = SubMateri::firstOrCreate(
                            ['mapel_id' => $mapel->id, 'nama' => $subMateriNama],
                            ['deskripsi' => "Auto dari AI — {$mapel->nama}: {$subMateriNama}"]
                        );

                        $draftData['sub_materi']     = $subMateriNama;
                        $draftData['_sub_materi_id'] = $subMateri->id;
                        $draftData['_mapel_id']      = $mapel->id;

                        AiDraftSoal::create([
                            'upload_id' => $upload->id,
                            'draft'     => $draftData,
                            'status'    => 'pending',
                        ]);
                        $totalCreated++;
                    }

                    Log::info("MaterialUpload[$uid]: {$mapel->nama} complete — {$totalCreated} total drafts after $batchNum batch(es)");

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
     * - ASCII and Unicode control characters inside JSON strings (AI bug)
     * - Unescaped newlines/tabs within string values
     * - Per-object extraction as last resort
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

        // Pass 2a: strip ASCII control chars (0x00–0x1F except \n, \r, \t)
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean);

        // Pass 2b: strip Unicode control chars that PHP's json_decode chokes on
        // This covers U+0080–U+009F (C1 controls) encoded as UTF-8
        $sanitized = preg_replace('/[\x{0080}-\x{009F}]/u', '', $sanitized ?? $clean);

        // Pass 2c: escape raw newlines/tabs ONLY inside JSON string literals
        $sanitized = $this->escapeNewlinesInJsonStrings($sanitized ?? $clean);

        $decoded = json_decode($sanitized, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            Log::info('parseAiSoalResponse: fixed after control-char sanitisation');
            return $this->normaliseAiSoalArray($decoded);
        }

        // Pass 3: flatten ALL whitespace then try
        $flat = preg_replace('/\s+/', ' ', $sanitized ?? $clean);
        $decoded = json_decode($flat, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            Log::info('parseAiSoalResponse: fixed by flattening whitespace');
            return $this->normaliseAiSoalArray($decoded);
        }

        // Pass 4: extract outermost [...] then try
        if (preg_match('/\[[\s\S]*\]/m', $flat ?? $sanitized, $m)) {
            $decoded = json_decode($m[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                Log::info('parseAiSoalResponse: extracted JSON array via regex');
                return $this->normaliseAiSoalArray($decoded);
            }
        }

        // Pass 5 (last resort): extract individual {...} objects one by one
        // This handles when one broken item corrupts the whole array
        $results = $this->extractObjectsOneByOne($flat ?? $sanitized ?? $clean);
        if (!empty($results)) {
            Log::info('parseAiSoalResponse: recovered ' . count($results) . ' objects individually');
            return $results;
        }

        Log::warning('AI returned invalid JSON after all repair attempts', [
            'error'   => json_last_error_msg(),
            'preview' => mb_substr($clean, 0, 500),
        ]);
        return [];
    }

    /**
     * Try to extract valid JSON objects one at a time from a broken array string.
     * Useful when a single item has a bad character that breaks the whole batch.
     */
    private function extractObjectsOneByOne(string $text): array
    {
        $results = [];
        $depth   = 0;
        $start   = null;
        $len     = strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($ch === '{') {
                if ($depth === 0) $start = $i;
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0 && $start !== null) {
                    $chunk = substr($text, $start, $i - $start + 1);
                    // Sanitize this chunk individually
                    $chunk = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $chunk);
                    $chunk = preg_replace('/[\x{0080}-\x{009F}]/u', '', $chunk);
                    $chunk = $this->escapeNewlinesInJsonStrings($chunk);
                    $obj   = json_decode($chunk, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($obj) && isset($obj['pertanyaan'])) {
                        $results[] = $obj;
                    }
                    $start = null;
                }
            }
        }

        return $results;
    }


    /**
     * Walk through JSON text character-by-character, escaping raw newlines
     * that appear inside quoted string values (a common LLM output bug).
     */
    private function escapeNewlinesInJsonStrings(string $json): string
    {
        $len      = strlen($json);
        $result   = '';
        $inString = false;
        $escaped  = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $json[$i];

            if ($escaped) {
                // Previous char was backslash — this char is escaped, pass through
                $result .= $ch;
                $escaped = false;
                continue;
            }

            if ($ch === '\\' && $inString) {
                $result .= $ch;
                $escaped = true;
                continue;
            }

            if ($ch === '"') {
                $inString = !$inString;
                $result .= $ch;
                continue;
            }

            // Replace raw newlines inside strings with escaped versions
            if ($inString && ($ch === "\n" || $ch === "\r")) {
                if ($ch === "\r" && $i + 1 < $len && $json[$i + 1] === "\n") {
                    $i++; // skip the \n of \r\n pair
                }
                $result .= '\\n';
                continue;
            }

            // Replace raw tabs inside strings
            if ($inString && $ch === "\t") {
                $result .= '\\t';
                continue;
            }

            $result .= $ch;
        }

        return $result;
    }

    private function normaliseAiSoalArray(mixed $decoded): array
    {
        if (isset($decoded['pertanyaan'])) return [$decoded]; // single object
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function buildGeneratePrompt(string $text, string $mapelNama, int $jumlah, array $subNames = []): string
    {
        $subMateriInstruction = '';
        if (!empty($subNames)) {
            $list = implode(', ', array_map(fn($n) => "\"$n\"", $subNames));
            $subMateriInstruction = "\nSub-materi yang HARUS digunakan (pilih salah satu per soal): [{$list}]";
            $subMateriInstruction .= "\nJangan gunakan nama sub_materi selain dari daftar di atas.";
        }

        $exampleSubMateri = !empty($subNames) ? $subNames[0] : 'Topik Relevan';

        return <<<PROMPT
[SYSTEM]
Kamu adalah pembuat soal SNBT profesional Indonesia.
Buat soal HANYA berdasarkan konteks teks yang diberikan.
Output HANYA JSON array yang valid. JANGAN tambahkan teks apapun di luar JSON.

ATURAN JSON WAJIB — PENTING:
1. Output adalah SATU JSON array, mulai dengan [ dan diakhiri dengan ]
2. JANGAN gunakan markdown fence (```)
3. Semua nilai string HANYA boleh mengandung karakter ASCII printable (spasi hingga ~)
4. JANGAN ada karakter kontrol, tab, atau newline di dalam nilai string — ganti dengan spasi biasa
5. Tanda kutip (") dalam string WAJIB di-escape menjadi \"
6. Jangan kutip langsung dari teks sumber — parafrasekan dengan kata-kata sendiri
7. Pembahasan maksimal 2 kalimat, tanpa karakter spesial

[CONTEXT]
{$text}

[INSTRUCTION]
Buat tepat {$jumlah} soal pilihan ganda untuk mapel {$mapelNama} SNBT.{$subMateriInstruction}

Format output (ikuti PERSIS, ganti ... dengan konten):
[{"pertanyaan":"...","pilihan":{"A":"...","B":"...","C":"...","D":"...","E":"..."},"kunci":"B","pembahasan":"...","mapel":"{$mapelNama}","sub_materi":"{$exampleSubMateri}","tingkat_kesulitan":"sedang","tipe_soal":"MC"}]

Buat tepat {$jumlah} objek dalam array. Output HANYA JSON, tidak ada teks lain.
PROMPT;
    }

    /**
     * Phase 1: Generate a clean reading passage (wacana) from source material.
     * Used for LBI, LBE, KMBM mapels that require wacana-based questions.
     */
    private function buildWacanaPrompt(string $text, string $mapelNama, string $mapelKode): string
    {
        $lang = strtoupper($mapelKode) === 'LBE' ? 'English' : 'Bahasa Indonesia';
        $langInstruction = strtoupper($mapelKode) === 'LBE'
            ? 'Write the passage in ENGLISH. It should be academic/informative in style.'
            : 'Tulis dalam BAHASA INDONESIA yang baik dan benar sesuai EYD.';

        return <<<PROMPT
[SYSTEM]
Kamu adalah penulis soal SNBT profesional Indonesia.
Buat sebuah teks bacaan (wacana) berbahasa {$lang} yang relevan dengan materi berikut.
Output HANYA teks bacaan saja — jangan tambahkan judul, catatan, atau apapun selain teks bacaan.

[ATURAN WAJIB]
1. Panjang teks: 200-350 kata
2. Genre: teks eksposisi atau narasi yang informatif
3. {$langInstruction}
4. Teks harus orisinal — parafrase dari materi, bukan kutipan langsung
5. Gunakan hanya karakter ASCII printable — tidak ada karakter spesial atau unicode
6. Jangan gunakan tanda kutip dalam teks kecuali dialog langsung

[MATERI SUMBER]
{$text}

[OUTPUT]
Tulis hanya teks bacaan (wacana) sesuai aturan di atas. Tidak ada judul. Tidak ada penjelasan.
PROMPT;
    }

    /**
     * Phase 2: Generate SNBT-format questions based on a wacana (reading passage).
     * Questions explicitly reference the passage text.
     */
    private function buildWacanaSoalPrompt(
        string $wacana,
        string $mapelNama,
        string $mapelKode,
        int    $jumlah,
        array  $subNames = []
    ): string {
        $subMateriInstruction = '';
        if (!empty($subNames)) {
            $list = implode(', ', array_map(fn($n) => "\"$n\"", $subNames));
            $subMateriInstruction = "\nSub-materi yang HARUS digunakan (pilih salah satu per soal): [{$list}]";
            $subMateriInstruction .= "\nJangan gunakan nama sub_materi selain dari daftar di atas.";
        }
        $exampleSubMateri = !empty($subNames) ? $subNames[0] : 'Literasi';

        // Mapel-specific question types
        $questionTypes = match (strtoupper($mapelKode)) {
            'LBI'  => 'ide pokok paragraf, makna kata dalam konteks, inferensi, simpulan logis, kelemahan argumen, struktur teks',
            'LBE'  => 'main idea, vocabulary in context, inference, logical conclusion, text structure, author purpose',
            'KMBM' => 'penulisan EYD, kata baku, koherensi paragraf, tanda baca, ejaan, kalimat efektif',
            default => 'pemahaman bacaan',
        };

        return <<<PROMPT
[SYSTEM]
Kamu adalah pembuat soal SNBT profesional Indonesia untuk mapel {$mapelNama}.
Setiap soal HARUS menyertakan teks wacana sebagai konteks di dalam field "pertanyaan".
Output HANYA JSON array yang valid. JANGAN tambahkan teks apapun di luar JSON.

ATURAN JSON WAJIB:
1. Output adalah SATU JSON array, mulai [ dan diakhiri ]
2. JANGAN gunakan markdown fence (```)
3. Semua nilai string hanya karakter ASCII printable — GANTI semua newline/tab dengan spasi biasa
4. Tanda kutip (") dalam string WAJIB di-escape: \"
5. Jangan kutip verbatim dari wacana — parafrasekan pertanyaannya

[WACANA — SERTAKAN INI DI SETIAP PERTANYAAN]
{$wacana}

[INSTRUKSI KRITIS]
SETIAP pertanyaan di field "pertanyaan" WAJIB menggunakan format berikut (PERSIS):
"Bacalah teks berikut! {wacana_text} {kalimat_pertanyaan}"

Dimana:
- {wacana_text} = teks wacana di atas (dalam satu baris, ganti newline dengan spasi)
- {kalimat_pertanyaan} = pertanyaan spesifik tentang wacana

Ini penting agar user bisa membaca teks sebelum menjawab.

Buat tepat {$jumlah} soal dengan tipe: {$questionTypes}.{$subMateriInstruction}

Setiap soal harus:
- Memiliki 5 pilihan (A-E) yang logis
- Hanya SATU jawaban benar berdasarkan wacana
- Distractor terdengar masuk akal tapi tidak sesuai teks
- Pembahasan menjelaskan mengapa jawaban benar DAN mengapa pilihan lain salah

CONTOH FORMAT pertanyaan yang BENAR:
"Bacalah teks berikut! Teknologi kecerdasan buatan berkembang pesat dalam dekade terakhir. Para peneliti berhasil menciptakan sistem yang mampu mengenali wajah dengan akurasi tinggi. Namun demikian, masalah privasi menjadi perhatian utama masyarakat. Apa ide pokok paragraf tersebut?"

Format output JSON (ikuti PERSIS, ganti ... dengan konten):
[{{"pertanyaan":"Bacalah teks berikut! {wacana_satu_baris} {kalimat_tanya}","pilihan":{{"A":"...","B":"...","C":"...","D":"...","E":"..."}},"kunci":"B","pembahasan":"...","mapel":"{$mapelNama}","sub_materi":"{$exampleSubMateri}","tingkat_kesulitan":"sedang","tipe_soal":"MC"}}]

Buat tepat {$jumlah} objek. Output HANYA JSON, tidak ada teks lain.
PROMPT;
    }
}

