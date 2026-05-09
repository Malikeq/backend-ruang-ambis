<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Soal;
use App\Models\PilihanJawaban;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSoalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $soal = Soal::with(['mapel', 'sub_materi'])
            ->when($request->search, fn($q, $s) => $q->where('konten', 'like', "%{$s}%"))
            ->when($request->mapel_id, fn($q, $m) => $q->where('mapel_id', $m))
            ->latest()
            ->paginate($request->per_page ?? 20);

        return response()->json(['success' => true, 'data' => $soal]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mapel_id'          => ['required', 'exists:mapel,id'],
            'sub_materi_id'     => ['required', 'exists:sub_materi,id'],
            'konten'            => ['required', 'string'],
            'tipe'              => ['required', 'in:MC,BS,MJ'],
            'tingkat_kesulitan' => ['required', 'in:mudah,sedang,sulit'],
            'pilihan'           => ['required', 'array', 'min:4'],
            'kunci'             => ['required', 'string', 'max:1'],
        ]);

        $soal = Soal::create([
            'mapel_id'          => $data['mapel_id'],
            'sub_materi_id'     => $data['sub_materi_id'],
            'konten'            => $data['konten'],
            'tipe'              => $data['tipe'],
            'tingkat_kesulitan' => $data['tingkat_kesulitan'],
            'is_published'      => false,
        ]);

        foreach ($data['pilihan'] as $label => $konten) {
            PilihanJawaban::create([
                'soal_id'    => $soal->id,
                'label'      => $label,
                'konten'     => $konten,
                'is_correct' => $label === strtoupper($data['kunci']),
            ]);
        }

        return response()->json(['success' => true, 'data' => $soal->load('pilihan_jawaban')], 201);
    }

    public function update(Request $request, Soal $soal): JsonResponse
    {
        $soal->update($request->validate([
            'konten'            => ['sometimes', 'string'],
            'tingkat_kesulitan' => ['sometimes', 'in:mudah,sedang,sulit'],
            'is_published'      => ['sometimes', 'boolean'],
        ]));

        return response()->json(['success' => true, 'data' => $soal]);
    }

    public function destroy(Soal $soal): JsonResponse
    {
        $soal->delete();
        return response()->json(['success' => true, 'message' => 'Soal berhasil dihapus.']);
    }

    public function publish(Soal $soal): JsonResponse
    {
        $soal->update(['is_published' => !$soal->is_published]);
        return response()->json(['success' => true, 'data' => $soal]);
    }
}
