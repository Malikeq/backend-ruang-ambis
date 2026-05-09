<?php

namespace App\Http\Controllers\Api\AI;

use App\Http\Controllers\Controller;
use App\Models\MaterialUpload;
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
        $upload   = $this->service->process(
            $request->file('file'),
            $mapelIds,
            (int) $request->jumlah_soal_target,
            $request->user()->id
        );

        return response()->json(['success' => true, 'data' => $upload, 'message' => 'File sedang diproses AI.'], 201);
    }

    public function history(Request $request): JsonResponse
    {
        $uploads = MaterialUpload::where('admin_id', $request->user()->id)->latest()->paginate(20);
        return response()->json(['success' => true, 'data' => $uploads]);
    }

    public function status(Request $request, MaterialUpload $upload): JsonResponse
    {
        $draftCount = $upload->aiDrafts()->count();
        return response()->json(['success' => true, 'data' => ['upload' => $upload, 'draft_count' => $draftCount]]);
    }
}
