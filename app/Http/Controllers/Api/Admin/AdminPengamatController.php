<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PengamatSekolah;
use App\Models\Sekolah;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminPengamatController extends Controller
{
    /**
     * GET /admin/pengamat — list semua pengamat (pending/approved/rejected)
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->get('status', 'pending');

        $data = PengamatSekolah::with(['pengamat', 'sekolah', 'approvedBy'])
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /admin/pengamat/{id}/approve
     */
    public function approve(Request $request, PengamatSekolah $pengamat): JsonResponse
    {
        if ($pengamat->status === 'approved') {
            return response()->json(['success' => false, 'message' => 'Sudah disetujui sebelumnya.'], 422);
        }

        $pengamat->update([
            'status'      => 'approved',
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'catatan'     => $request->catatan,
        ]);

        // Sinkronkan sekolah_id ke user
        $pengamat->pengamat->update(['sekolah_id' => $pengamat->sekolah_id]);

        return response()->json(['success' => true, 'message' => 'Pengamat berhasil disetujui.']);
    }

    /**
     * POST /admin/pengamat/{id}/reject
     */
    public function reject(Request $request, PengamatSekolah $pengamat): JsonResponse
    {
        $pengamat->update([
            'status'      => 'rejected',
            'approved_by' => $request->user()->id,
            'catatan'     => $request->catatan ?? 'Ditolak oleh admin.',
        ]);

        return response()->json(['success' => true, 'message' => 'Pengamat ditolak.']);
    }

    /**
     * POST /admin/pengamat — buat akun pengamat baru langsung dari admin (auto-approved)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'email'      => ['required', 'email', 'unique:users,email'],
            'password'   => ['required', 'string', 'min:8'],
            'sekolah_id' => ['required', 'integer', 'exists:sekolah,id'],
            'jabatan'    => ['nullable', 'string', 'max:100'],
        ]);

        // Buat user baru dengan role pengamat
        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => bcrypt($validated['password']),
            'role'     => 'pengamat',
        ]);

        // Buat record pengamat + langsung approved
        $pengamatRecord = PengamatSekolah::create([
            'pengamat_id' => $user->id,
            'sekolah_id'  => $validated['sekolah_id'],
            'jabatan'     => $validated['jabatan'] ?? null,
            'status'      => 'approved',
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);

        // Sinkronkan sekolah_id ke user
        $user->update(['sekolah_id' => $validated['sekolah_id']]);

        $pengamatRecord->load(['pengamat', 'sekolah']);

        return response()->json([
            'success' => true,
            'message' => 'Akun pengamat berhasil dibuat dan langsung disetujui.',
            'data'    => $pengamatRecord,
        ], 201);
    }

    /**
     * DELETE /admin/pengamat/{id} — hapus pengamat dan user-nya
     */
    public function destroy(PengamatSekolah $pengamat): JsonResponse
    {
        // Hapus record pengamat_sekolahs
        $pengamat->delete();

        return response()->json(['success' => true, 'message' => 'Pengamat berhasil dihapus.']);
    }

    /**
     * GET /admin/sekolah — list sekolah untuk admin manage
     */
    public function sekolahIndex(Request $request): JsonResponse
    {
        $sekolahs = Sekolah::withCount(['siswa', 'pengamatLinks as pengamat_count'])
            ->when($request->q, fn($q) => $q->where('nama', 'like', "%{$request->q}%"))
            ->orderBy('nama')
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $sekolahs]);
    }

    /**
     * POST /admin/sekolah — buat sekolah baru manual
     */
    public function sekolahStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nama'     => ['required', 'string', 'max:255'],
            'kota'     => ['nullable', 'string'],
            'provinsi' => ['nullable', 'string'],
            'npsn'     => ['nullable', 'string', 'max:20'],
        ]);

        $slug = Str::slug($validated['nama']);
        $original = $slug;
        $i = 1;
        while (Sekolah::where('slug', $slug)->exists()) {
            $slug = $original . '-' . $i++;
        }

        $sekolah = Sekolah::create(array_merge($validated, ['slug' => $slug]));

        return response()->json(['success' => true, 'data' => $sekolah], 201);
    }
}
