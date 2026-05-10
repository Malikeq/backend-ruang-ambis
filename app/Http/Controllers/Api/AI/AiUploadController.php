<?php

namespace App\Http\Controllers\Api\AI;

use App\Http\Controllers\Controller;
use App\Models\MaterialUpload;
use App\Models\Mapel;
use App\Services\MaterialUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiUploadController extends Controller
{
    public function __construct(private MaterialUploadService $service) {}

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file'               => ['required', 'file', 'max:20480', 'mimes:pdf,docx,pptx,txt,md,xlsx,csv,jpg,jpeg,png'],
            'target_mapel_ids'   => ['required', 'json'],
            'jumlah_soal_target' => ['required', 'integer', 'min:5', 'max:50'],
        ]);

        $mapelIds = json_decode($request->target_mapel_ids, true);

        if (empty($mapelIds)) {
            return response()->json(['success' => false, 'message' => 'Pilih minimal 1 mapel target.'], 422);
        }

        // Validate mapel IDs exist
        $validMapelIds = Mapel::whereIn('id', $mapelIds)->pluck('id')->toArray();
        if (empty($validMapelIds)) {
            return response()->json(['success' => false, 'message' => 'Mapel yang dipilih tidak valid.'], 422);
        }

        $upload = $this->service->process(
            $request->file('file'),
            $validMapelIds,
            (int) $request->jumlah_soal_target,
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'data'    => $upload,
            'message' => 'File berhasil diupload. AI sedang memproses dan membuat soal...',
        ], 201);
    }

    public function history(Request $request): JsonResponse
    {
        $uploads = MaterialUpload::where('admin_id', $request->user()->id)
            ->withCount('drafts')
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $uploads]);
    }

    public function status(Request $request, MaterialUpload $upload): JsonResponse
    {
        $draftCount = $upload->drafts()->count();           // FIX: was aiDrafts()
        $pendingCount = $upload->drafts()->where('status', 'pending')->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'upload'        => $upload,
                'draft_count'   => $draftCount,
                'pending_count' => $pendingCount,
            ],
        ]);
    }

    /**
     * GET /admin/mapel — list all mapel for frontend selector
     */
    public function mapelList(): JsonResponse
    {
        $mapels = Mapel::orderBy('id')->get(['id', 'nama', 'kode', 'snbt_weight']);
        return response()->json(['success' => true, 'data' => $mapels]);
    }
}
