<?php

namespace App\Services;

use App\Models\MaterialUpload;
use App\Models\AiDraftSoal;
use App\Models\Mapel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;

class MaterialUploadService
{
    public function __construct(private GeminiService $gemini) {}

    /**
     * Handle uploaded file: store, extract text, trigger AI generation.
     */
    public function process(UploadedFile $file, array $mapelIds, int $jumlahSoal, int $adminId): MaterialUpload
    {
        // Store file
        $path = $file->store('uploads/materials', 'local');

        $upload = MaterialUpload::create([
            'admin_id'           => $adminId,
            'filename'           => $file->getClientOriginalName(),
            'file_type'          => $file->getClientOriginalExtension(),
            'file_url'           => $path,
            'status'             => 'processing',
            'target_mapel_ids'   => $mapelIds,
            'jumlah_soal_target' => $jumlahSoal,
        ]);

        // Dispatch to background job (or process inline for simplicity)
        dispatch(fn() => $this->generateSoal($upload, $file))->afterResponse();

        return $upload;
    }

    /**
     * Extract text from file based on type.
     */
    public function extractText(UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension());

        return match($ext) {
            'pdf'  => $this->extractPdf($file),
            'txt', 'md' => file_get_contents($file->getRealPath()),
            'docx' => $this->extractDocx($file),
            'jpg', 'jpeg', 'png' => $this->extractImageOcr($file),
            default => ''
        };
    }

    private function extractPdf(UploadedFile $file): string
    {
        try {
            $parser   = new PdfParser();
            $pdf      = $parser->parseFile($file->getRealPath());
            return $pdf->getText();
        } catch (\Exception $e) {
            Log::warning('PDF parse failed, using Gemini Vision', ['error' => $e->getMessage()]);
            return $this->extractImageOcr($file);
        }
    }

    private function extractDocx(UploadedFile $file): string
    {
        // Basic extraction via zip (DOCX is a zip)
        $zip = new \ZipArchive();
        if ($zip->open($file->getRealPath()) === true) {
            $xml  = $zip->getFromName('word/document.xml');
            $zip->close();
            return strip_tags(str_replace(['</w:p>', '</w:tr>'], "\n", $xml ?? ''));
        }
        return '';
    }

    private function extractImageOcr(UploadedFile $file): string
    {
        $base64 = base64_encode(file_get_contents($file->getRealPath()));
        $mime   = $file->getMimeType() ?? 'image/jpeg';

        $prompt = 'Ekstrak semua teks dari gambar ini. Pertahankan format asli teks. Hanya output teks, tanpa penjelasan tambahan.';
        $result = $this->gemini->analyzeImage($base64, $mime, $prompt);
        return $result['text'] ?? '';
    }

    /**
     * Generate draft soal from extracted text using Gemini.
     */
    private function generateSoal(MaterialUpload $upload, UploadedFile $file): void
    {
        try {
            $text   = $this->extractText($file);
            if (empty(trim($text))) {
                $upload->update(['status' => 'failed']);
                return;
            }

            // Chunk text (max ~3000 chars per chunk to stay in token budget)
            $chunks = str_split($text, 3000);
            $mapels = Mapel::whereIn('id', $upload->target_mapel_ids)->get();
            $perChunk = max(1, intdiv($upload->jumlah_soal_target, count($chunks)));

            foreach ($chunks as $chunk) {
                foreach ($mapels as $mapel) {
                    $prompt = $this->buildGeneratePrompt($chunk, $mapel->nama, $perChunk);
                    try {
                        $result = $this->gemini->generateFlash($prompt, $upload->admin_id, 'material_upload');
                        $drafts = $this->gemini->parseJson($result['text']);

                        foreach ((array) $drafts as $draft) {
                            AiDraftSoal::create([
                                'upload_id' => $upload->id,
                                'draft'     => $draft,
                                'status'    => 'pending',
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Draft generation failed for chunk', ['error' => $e->getMessage()]);
                    }
                }
            }

            $upload->update(['status' => 'done']);
        } catch (\Exception $e) {
            Log::error('MaterialUploadService::generateSoal failed', ['error' => $e->getMessage()]);
            $upload->update(['status' => 'failed']);
        }
    }

    private function buildGeneratePrompt(string $text, string $mapelNama, int $jumlah): string
    {
        return <<<PROMPT
[SYSTEM]
Kamu adalah pembuat soal SNBT profesional Indonesia.
Kamu HANYA boleh membuat soal berdasarkan konteks teks yang diberikan.
Jangan menambah fakta di luar teks sumber.
Output HARUS array JSON yang valid.

[CONTEXT]
{$text}

[INSTRUCTION]
Buat {$jumlah} soal pilihan ganda untuk mapel {$mapelNama} SNBT tingkat sedang dari teks di atas.
Output sebagai JSON array:
[
  {
    "pertanyaan": "...",
    "pilihan": {"A": "...", "B": "...", "C": "...", "D": "...", "E": "..."},
    "kunci": "B",
    "pembahasan": "...",
    "mapel": "{$mapelNama}",
    "sub_materi": "...",
    "tingkat_kesulitan": "sedang",
    "tipe_soal": "MC"
  }
]

[SELF-VERIFY]
1. Apakah kunci jawaban benar?
2. Apakah semua distractor plausible tapi salah?
3. Apakah soal bisa dijawab dari teks sumber?
PROMPT;
    }
}
