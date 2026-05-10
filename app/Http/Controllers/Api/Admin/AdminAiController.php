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
use App\Services\MaterialUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAiController extends Controller
{
    public function __construct(private MaterialUploadService $uploadService) {}

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
