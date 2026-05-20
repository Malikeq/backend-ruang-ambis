<?php

namespace App\Http\Controllers\Api\Pengamat;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Sekolah;
use App\Models\PengamatSekolah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PengamatAuthController extends Controller
{
    /**
     * POST /pengamat/register
     * Register akun pengamat baru dan request ke sekolah tertentu.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'email'      => ['required', 'email', 'unique:users,email'],
            'password'   => ['required', 'min:8'],
            'sekolah_id' => ['required', 'integer', 'exists:sekolah,id'],
        ]);

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role'     => 'pengamat',
            'tier'     => 'free',
        ]);

        PengamatSekolah::create([
            'pengamat_id' => $user->id,
            'sekolah_id'  => $validated['sekolah_id'],
            'status'      => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Akun pengamat berhasil dibuat. Menunggu verifikasi admin.',
            'data'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ],
        ], 201);
    }

    /**
     * GET /pengamat/status — cek status pending/approved setelah login
     */
    public function status(Request $request): JsonResponse
    {
        $user     = $request->user();
        $approval = $user->pengamatSekolah()->with('sekolah')->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'role'     => $user->role,
                'status'   => $approval?->status ?? 'not_registered',
                'sekolah'  => $approval?->sekolah,
                'catatan'  => $approval?->catatan,
            ],
        ]);
    }
}
