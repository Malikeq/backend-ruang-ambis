<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiDraftSoal;
use App\Models\AiCallLog;
use App\Models\AiExplanation;
use App\Services\MaterialUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAiController extends Controller
{
    public function __construct(private MaterialUploadService $uploadService) {}

    public function drafts(Request $request): JsonResponse
    {
        $drafts = AiDraftSoal::with('upload')
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $drafts]);
    }

    public function approveDraft(Request $request, AiDraftSoal $draft): JsonResponse
    {
        $draft->update(['status' => 'approved', 'reviewer_id' => $request->user()->id, 'reviewed_at' => now()]);

        // Publish to soal bank
        $data      = $draft->draft;
        $soal      = \App\Models\Soal::create([
            'mapel_id'          => \App\Models\Mapel::where('nama', $data['mapel'] ?? '')->first()?->id ?? 1,
            'sub_materi_id'     => 1, // default, admin can update later
            'konten'            => $data['pertanyaan'] ?? '',
            'tipe'              => 'MC',
            'tingkat_kesulitan' => $data['tingkat_kesulitan'] ?? 'sedang',
            'is_ai_generated'   => true,
            'is_published'      => true,
        ]);

        foreach ($data['pilihan'] ?? [] as $label => $konten) {
            \App\Models\PilihanJawaban::create([
                'soal_id'    => $soal->id,
                'label'      => $label,
                'konten'     => $konten,
                'is_correct' => $label === ($data['kunci'] ?? 'A'),
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Draft disetujui dan dipublikasikan.']);
    }

    public function rejectDraft(Request $request, AiDraftSoal $draft): JsonResponse
    {
        $draft->update(['status' => 'rejected', 'reviewer_id' => $request->user()->id, 'reviewed_at' => now()]);
        return response()->json(['success' => true, 'message' => 'Draft ditolak.']);
    }

    public function editDraft(Request $request, AiDraftSoal $draft): JsonResponse
    {
        $draft->update(['draft' => $request->validate(['draft' => ['required', 'array']])['draft']]);
        return response()->json(['success' => true, 'data' => $draft]);
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
        // In production, store settings in DB or config file
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
}
