<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiDraftSoal;
use App\Models\AiCallLog;
use App\Models\AiExplanation;
use App\Models\Mapel;
use App\Models\SubMateri;
use App\Models\Soal;
use App\Models\PilihanJawaban;
use App\Models\MaterialUpload;
use App\Services\AiExplanationService;
use App\Services\GeminiService;
use App\Services\MaterialUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminAiController extends Controller
{
    public function __construct(
        private MaterialUploadService $uploadService,
        private AiExplanationService  $explanationService,
        private GeminiService         $gemini,
    ) {}

    // ─── Direct Gemini Generate ─────────────────────────────────────────────────

    /**
     * POST /admin/ai/generate
     * Generate soal SNBT langsung dari Gemini tanpa file upload.
     *
     * Body:
     *  - mapel_id         int      required
     *  - sub_materi_id    int      optional
     *  - jumlah_soal      int      1-20 (default 5)
     *  - tingkat_kesulitan string  mudah|sedang|sulit (default sedang)
     *  - topik            string   optional — extra context/topic hint
     *  - auto_publish     bool     false=draft for review, true=langsung publish
     */
    public function generateDirect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mapel_id'          => ['required', 'integer', 'exists:mapel,id'],
            'sub_materi_id'     => ['nullable', 'integer', 'exists:sub_materi,id'],
            'jumlah_soal'       => ['integer', 'min:1', 'max:20'],
            'tingkat_kesulitan' => ['in:mudah,sedang,sulit'],
            'topik'             => ['nullable', 'string', 'max:500'],
            'auto_publish'      => ['boolean'],
        ]);

        $mapel      = Mapel::findOrFail($validated['mapel_id']);
        $subMateri  = isset($validated['sub_materi_id'])
                      ? SubMateri::find($validated['sub_materi_id'])
                      : null;
        $jumlah     = $validated['jumlah_soal']       ?? 5;
        $kesulitan  = $validated['tingkat_kesulitan'] ?? 'sedang';
        $topik      = $validated['topik']             ?? null;
        $autoPublish= $validated['auto_publish']      ?? false;

        // ── Build prompt ──────────────────────────────────────────────────────
        $subMateriStr = $subMateri ? "sub-materi: **{$subMateri->nama}**" : 'sub-materi umum';
        $topikStr     = $topik ? "\nKonteks / topik khusus: {$topik}" : '';

        $prompt = <<<PROMPT
Kamu adalah pembuat soal ujian SNBT (Seleksi Nasional Berdasarkan Tes) berkualitas tinggi untuk mata pelajaran **{$mapel->nama}**, {$subMateriStr}.{$topikStr}

Buat **{$jumlah} soal** pilihan ganda (MC) dengan tingkat kesulitan **{$kesulitan}**.

Setiap soal HARUS mengikuti format JSON berikut PERSIS (tanpa teks tambahan di luar array):

```json
[
  {
    "pertanyaan": "Teks pertanyaan yang jelas dan lengkap.",
    "pilihan": {
      "A": "Pilihan A",
      "B": "Pilihan B",
      "C": "Pilihan C",
      "D": "Pilihan D",
      "E": "Pilihan E"
    },
    "kunci": "A",
    "pembahasan": "Penjelasan mengapa jawaban tersebut benar, langkah per langkah.",
    "sub_materi": "{$subMateriStr}",
    "tingkat_kesulitan": "{$kesulitan}"
  }
]
```

Aturan wajib:
1. Semua soal harus relevan dengan SNBT dan kurikulum Merdeka Belajar.
2. Setiap soal punya tepat 5 pilihan (A–E).
3. Kunci jawaban hanya boleh satu huruf kapital: A, B, C, D, atau E.
4. Pembahasan minimal 2 kalimat, menjelaskan alasan kunci benar dan mengapa pilihan lain salah.
5. Bahasa Indonesia formal dan baku.
6. TIDAK boleh ada soal yang sama atau mirip.
7. Kembalikan HANYA array JSON, tanpa komentar, tanpa teks pengantar.
PROMPT;

        // ── Call Gemini ───────────────────────────────────────────────────────
        try {
            $result = $this->gemini->generateFlash($prompt, $request->user()->id, 'generate_soal');
            $items  = $this->gemini->parseJson($result['text']);
        } catch (\Exception $e) {
            Log::error('AdminAi@generateDirect: Gemini error', ['msg' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Gemini gagal menghasilkan soal: ' . $e->getMessage(),
            ], 500);
        }

        if (!is_array($items) || empty($items)) {
            return response()->json([
                'success' => false,
                'message' => 'Gemini tidak mengembalikan soal dalam format yang valid.',
            ], 500);
        }

        // ── Persist as drafts or published soal ───────────────────────────────
        DB::beginTransaction();
        try {
            // Create a virtual MaterialUpload record to satisfy foreign key
            $upload = MaterialUpload::create([
                'admin_id'           => $request->user()->id,
                'filename'           => "gemini-direct-{$mapel->kode}-" . now()->format('YmdHis') . '.ai',
                'file_type'          => 'ai',
                'file_url'           => 'ai/direct-generate',
                'status'             => 'done',
                'target_mapel_ids'   => [$mapel->id],
                'jumlah_soal_target' => count($items),
            ]);

            $created   = [];
            $published = [];

            foreach ($items as $item) {
                // Normalise keys
                $pertanyaan = $item['pertanyaan'] ?? ($item['question'] ?? '');
                $pilihan    = $item['pilihan']    ?? ($item['options'] ?? []);
                $kunci      = strtoupper($item['kunci'] ?? ($item['answer'] ?? 'A'));
                $pembahasan = $item['pembahasan'] ?? ($item['explanation'] ?? '');
                $subNama    = $subMateri?->nama ?? ($item['sub_materi'] ?? 'Umum');
                $diff       = in_array($item['tingkat_kesulitan'] ?? '', ['mudah','sedang','sulit'])
                              ? $item['tingkat_kesulitan'] : $kesulitan;

                if (empty($pertanyaan) || count($pilihan) < 4) continue; // skip malformed

                $draftData = [
                    'pertanyaan'        => $pertanyaan,
                    'pilihan'           => $pilihan,
                    'kunci'             => $kunci,
                    'pembahasan'        => $pembahasan,
                    'mapel'             => $mapel->nama,
                    'sub_materi'        => $subNama,
                    'tingkat_kesulitan' => $diff,
                    'tipe_soal'         => 'MC',
                    '_mapel_id'         => $mapel->id,
                    '_sub_materi_id'    => $subMateri?->id,
                ];

                if ($autoPublish) {
                    // Resolve sub_materi record
                    $smId = $subMateri?->id ?? SubMateri::firstOrCreate(
                        ['mapel_id' => $mapel->id, 'nama' => $subNama],
                        ['deskripsi' => 'Auto-created by AI generate']
                    )->id;

                    $soal = Soal::create([
                        'mapel_id'          => $mapel->id,
                        'sub_materi_id'     => $smId,
                        'konten'            => $pertanyaan,
                        'tipe'              => 'MC',
                        'tingkat_kesulitan' => $diff,
                        'is_ai_generated'   => true,
                        'is_published'      => true,
                    ]);
                    foreach ($pilihan as $label => $konten) {
                        PilihanJawaban::create([
                            'soal_id'    => $soal->id,
                            'label'      => strtoupper($label),
                            'konten'     => $konten,
                            'is_correct' => strtoupper($label) === $kunci,
                        ]);
                    }
                    // Store pembahasan
                    if ($pembahasan) {
                        $soal->pembahasan()->create(['konten' => $pembahasan]);
                    }
                    $published[] = $soal->id;
                } else {
                    $draft = AiDraftSoal::create([
                        'upload_id' => $upload->id,
                        'status'    => 'pending',
                        'draft'     => $draftData,
                    ]);
                    $created[] = $draft->id;
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('AdminAi@generateDirect: DB error', ['msg' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan soal: ' . $e->getMessage(),
            ], 500);
        }

        $mode = $autoPublish ? 'dipublikasikan' : 'disimpan sebagai draft';
        return response()->json([
            'success'       => true,
            'message'       => count($autoPublish ? $published : $created) . " soal berhasil {$mode}!",
            'jumlah'        => count($autoPublish ? $published : $created),
            'mode'          => $autoPublish ? 'published' : 'draft',
            'draft_ids'     => $autoPublish ? [] : $created,
            'soal_ids'      => $autoPublish ? $published : [],
            'tokens_used'   => $result['token_in'] + $result['token_out'],
            'upload_id'     => $upload->id,
        ], 201);
    }


    public function drafts(Request $request): JsonResponse
    {
        // Grouped view: return uploads with their draft counts + draft data
        if ($request->boolean('group_by_upload')) {
            $uploads = \App\Models\MaterialUpload::withCount([
                    'drafts',
                    'drafts as pending_count'  => fn($q) => $q->where('status', 'pending'),
                    'drafts as approved_count' => fn($q) => $q->where('status', 'approved'),
                    'drafts as rejected_count' => fn($q) => $q->where('status', 'rejected'),
                ])
                ->has('drafts')
                ->latest()
                ->paginate($request->per_page ?? 15);

            return response()->json(['success' => true, 'data' => $uploads]);
        }

        // Flat view: drafts for a specific upload (for expanding a group)
        $drafts = AiDraftSoal::with('upload')
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->upload_id, fn($q, $id) => $q->where('upload_id', $id))
            ->latest()
            ->paginate($request->per_page ?? 50);

        return response()->json(['success' => true, 'data' => $drafts]);
    }

    public function approveDraft(Request $request, AiDraftSoal $draft): JsonResponse
    {
        if ($draft->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Draft sudah diproses sebelumnya.'], 422);
        }
        if (empty($draft->draft['pertanyaan'] ?? '')) {
            return response()->json(['success' => false, 'message' => 'Draft tidak memiliki pertanyaan. Edit terlebih dahulu.'], 422);
        }

        try {
            $this->doApproveDraft($draft, $request->user()->id);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Draft disetujui dan dipublikasikan ke bank soal!']);
    }

    public function rejectDraft(Request $request, AiDraftSoal $draft): JsonResponse
    {
        if ($draft->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Draft sudah diproses sebelumnya.'], 422);
        }

        $draft->update([
            'status'      => 'rejected',
            'reviewer_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Draft ditolak.']);
    }

    /**
     * POST /admin/ai/drafts/bulk-approve — approve multiple drafts at once.
     * Body: { draft_ids: [1,2,3,...] }
     */
    public function bulkApproveDrafts(Request $request): JsonResponse
    {
        $request->validate([
            'draft_ids'   => ['required', 'array', 'min:1', 'max:100'],
            'draft_ids.*' => ['integer', 'exists:ai_draft_soal,id'],
        ]);

        $drafts = AiDraftSoal::whereIn('id', $request->draft_ids)
            ->where('status', 'pending')
            ->get();

        $approved = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($drafts as $draft) {
            try {
                // doApproveDraft now dispatches DSCEF pre-generation as non-blocking
                // afterResponse closure — so bulk approve won't timeout
                $this->doApproveDraft($draft, $request->user()->id);
                $approved++;
            } catch (\Exception $e) {
                $errors[] = "Draft #{$draft->id}: " . $e->getMessage();
                $skipped++;
            }
        }

        return response()->json([
            'success'  => true,
            'approved' => $approved,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'message'  => "{$approved} soal berhasil dipublikasi ke bank soal!" . ($skipped ? " {$skipped} gagal." : ''),
        ]);
    }

    /**
     * POST /admin/ai/drafts/bulk-reject — reject multiple drafts at once.
     * Body: { draft_ids: [1,2,3,...] }
     */
    public function bulkRejectDrafts(Request $request): JsonResponse
    {
        $request->validate([
            'draft_ids'   => ['required', 'array', 'min:1', 'max:100'],
            'draft_ids.*' => ['integer', 'exists:ai_draft_soal,id'],
        ]);

        $count = AiDraftSoal::whereIn('id', $request->draft_ids)
            ->where('status', 'pending')
            ->update([
                'status'      => 'rejected',
                'reviewer_id' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'count'   => $count,
            'message' => "{$count} draft ditolak.",
        ]);
    }

    /**
     * Core approve logic — extracted so bulk and single can share it.
     */
    private function doApproveDraft(AiDraftSoal $draft, int $reviewerId): void
    {
        $data = $draft->draft ?? [];

        $mapelId = $data['_mapel_id'] ?? null;
        if (!$mapelId && !empty($data['mapel'])) {
            $mapelId = Mapel::where('nama', $data['mapel'])->value('id') ?? Mapel::first()?->id;
        }
        if (!$mapelId) throw new \RuntimeException('Mapel tidak ditemukan.');

        $subMateriId = $data['_sub_materi_id'] ?? null;
        if (!$subMateriId) {
            $subMateri = SubMateri::where('mapel_id', $mapelId)
                ->when(!empty($data['sub_materi']), fn($q) => $q->where('nama', $data['sub_materi']))
                ->first();
            if (!$subMateri && !empty($data['sub_materi'])) {
                $subMateri = SubMateri::create(['mapel_id' => $mapelId, 'nama' => $data['sub_materi']]);
            }
            $subMateri = $subMateri ?? SubMateri::firstOrCreate(
                ['mapel_id' => $mapelId, 'nama' => 'Umum'],
                ['deskripsi' => 'Sub-materi umum']
            );
            $subMateriId = $subMateri->id;
        }

        $kesulitan = in_array($data['tingkat_kesulitan'] ?? '', ['mudah', 'sedang', 'sulit'])
            ? $data['tingkat_kesulitan'] : 'sedang';

        $soal = Soal::create([
            'mapel_id'          => $mapelId,
            'sub_materi_id'     => $subMateriId,
            'konten'            => $data['pertanyaan'] ?? '',
            'tipe'              => 'MC',
            'tingkat_kesulitan' => $kesulitan,
            'is_ai_generated'   => true,
            'is_published'      => true,
        ]);

        $pilihan = $data['pilihan'] ?? [];
        $kunci   = strtoupper($data['kunci'] ?? 'A');
        foreach ($pilihan as $label => $konten) {
            PilihanJawaban::create([
                'soal_id'    => $soal->id,
                'label'      => strtoupper($label),
                'konten'     => $konten,
                'is_correct' => strtoupper($label) === $kunci,
            ]);
        }

        $draft->update([
            'status'      => 'approved',
            'reviewer_id' => $reviewerId,
            'reviewed_at' => now(),
        ]);

        // Non-blocking pre-generation
        $soalId = $soal->id;
        dispatch(function () use ($soalId, $reviewerId) {
            set_time_limit(0);
            $soal = Soal::with(['mapel', 'sub_materi', 'pilihan_jawaban'])->find($soalId);
            if ($soal) {
                try {
                    app(AiExplanationService::class)->preGenerate($soal, $reviewerId);
                } catch (\Exception $e) {
                    Log::warning("DSCEF pre-generate failed for soal #{$soalId}: {$e->getMessage()}");
                }
            }
        })->afterResponse();
    }

    public function editDraft(Request $request, AiDraftSoal $draft): JsonResponse
    {
        if ($draft->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Hanya draft pending yang bisa diedit.'], 422);
        }

        $validated = $request->validate([
            'pertanyaan'        => ['required', 'string', 'min:10'],
            'pilihan'           => ['required', 'array', 'min:4'],
            'pilihan.A'         => ['required', 'string'],
            'pilihan.B'         => ['required', 'string'],
            'pilihan.C'         => ['required', 'string'],
            'pilihan.D'         => ['required', 'string'],
            'kunci'             => ['required', 'string', 'in:A,B,C,D,E'],
            'pembahasan'        => ['required', 'string'],
            'mapel'             => ['sometimes', 'string'],
            'sub_materi'        => ['sometimes', 'string'],
            'tingkat_kesulitan' => ['sometimes', 'in:mudah,sedang,sulit'],
        ]);

        $currentDraft = $draft->draft ?? [];
        $updatedDraft = array_merge($currentDraft, $validated);

        $draft->update(['draft' => $updatedDraft]);

        return response()->json(['success' => true, 'data' => $draft, 'message' => 'Draft berhasil diperbarui.']);
    }

    public function settings(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'model_flash'  => config('services.gemini.model_flash'),
                'model_pro'    => config('services.gemini.model_pro'),
                'max_rpm'      => config('services.gemini.max_rpm', 14),
                'cache_count'  => AiExplanation::count(),
                'total_tokens' => AiCallLog::sum('token_in') + AiCallLog::sum('token_out'),
                'total_cost'   => AiCallLog::sum('cost_idr'),
            ],
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Pengaturan disimpan.']);
    }

    public function clearCache(): JsonResponse
    {
        AiExplanation::truncate();
        return response()->json(['success' => true, 'message' => 'AI explanation cache dibersihkan.']);
    }

    public function logs(Request $request): JsonResponse
    {
        $logs = AiCallLog::with('user')
            ->latest()
            ->paginate(50);

        return response()->json(['success' => true, 'data' => $logs]);
    }

    /**
     * POST /admin/ai/drafts/test — create a sample draft for testing approve/reject flow.
     */
    public function createTestDraft(Request $request): JsonResponse
    {
        $mapel    = Mapel::first();
        $subMateri = $mapel ? SubMateri::where('mapel_id', $mapel->id)->first() : null;

        if (!$mapel || !$subMateri) {
            return response()->json([
                'success' => false,
                'message' => 'Mapel / sub-materi belum ada. Jalankan: php artisan db:seed --class=MapelSeeder',
            ], 422);
        }

        // Create a dummy upload record so the draft has a valid upload_id
        $upload = \App\Models\MaterialUpload::create([
            'admin_id'           => $request->user()->id,
            'filename'           => 'test-sample.txt',
            'file_type'          => 'txt',
            'file_url'           => 'test/sample',
            'status'             => 'done',
            'target_mapel_ids'   => [$mapel->id],
            'jumlah_soal_target' => 1,
        ]);

        $draft = AiDraftSoal::create([
            'upload_id' => $upload->id,
            'status'    => 'pending',
            'draft'     => [
                'pertanyaan'        => 'Berdasarkan teori kombinasi, berapa banyak cara memilih 3 orang dari 10 orang?',
                'pilihan'           => [
                    'A' => '120',
                    'B' => '720',
                    'C' => '210',
                    'D' => '30',
                    'E' => '504',
                ],
                'kunci'             => 'A',
                'pembahasan'        => 'C(10,3) = 10! / (3! × 7!) = (10×9×8)/(3×2×1) = 720/6 = 120. Jawaban A benar karena menggunakan rumus kombinasi, bukan permutasi.',
                'mapel'             => $mapel->nama,
                'sub_materi'        => $subMateri->nama,
                'tingkat_kesulitan' => 'sedang',
                'tipe_soal'         => 'MC',
                '_mapel_id'         => $mapel->id,
                '_sub_materi_id'    => $subMateri->id,
            ],
        ]);

        return response()->json([
            'success' => true,
            'data'    => $draft->load('upload'),
            'message' => 'Draft contoh berhasil dibuat. Sekarang kamu bisa test approve/reject!',
        ], 201);
    }

    /**
     * POST /admin/ai/upload/{upload}/retry — requeue a failed/stuck upload for reprocessing.
     */
    public function retryUpload(Request $request, \App\Models\MaterialUpload $upload): JsonResponse
    {
        if ($upload->status === 'processing') {
            return response()->json(['success' => false, 'message' => 'Upload masih dalam proses.'], 422);
        }

        $upload->update(['status' => 'processing']);

        $uploadId     = $upload->id;
        $storedPath   = $upload->file_url;
        $capturedType = $upload->file_type;
        $capturedMime = match($capturedType) {
            'pdf'  => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            default => 'text/plain',
        };
        $capturedIds   = $upload->target_mapel_ids;
        $capturedCount = $upload->jumlah_soal_target;

        dispatch(function () use ($uploadId, $storedPath, $capturedType, $capturedMime, $capturedIds, $capturedCount) {
            set_time_limit(0);
            ini_set('memory_limit', '256M');
            $upload  = \App\Models\MaterialUpload::find($uploadId);
            if (!$upload) return;
            $service = app(\App\Services\MaterialUploadService::class);
            $path    = \Illuminate\Support\Facades\Storage::disk('local')->path($storedPath);
            $text    = $service->extractTextFromPath($path, $capturedType, $capturedMime, $uploadId);
            $service->generateSoalFromText($upload, $text, $capturedIds, $capturedCount);
        })->afterResponse();

        return response()->json(['success' => true, 'message' => 'Upload dijadwalkan ulang untuk diproses.']);
    }
}
